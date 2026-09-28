# 02 — System Architecture

## 2.1 Three-tier design

The system is a classic three-tier web application, deliberately kept simple so it
can be hosted on an ordinary office PC running XAMPP.

```
┌──────────────────────────────────────────────────────────────────────┐
│  CLIENT  —  Browser                                                    │
│  React 18 SPA (frontend/src/main.jsx)                                 │
│  Public student portal  ·  Staff login  ·  Admin dashboard            │
│  Card preview & export (html2canvas)  ·  Bulk export (JSZip)          │
└───────────────────────────────┬──────────────────────────────────────┘
                                │  HTTP  JSON
                                │  VITE_API_URL -> backend/api.php?action=...
                                │  X-CSRF-Token header on writes
┌───────────────────────────────▼──────────────────────────────────────┐
│  APPLICATION  —  PHP                                                  │
│  backend/api.php        single entry point, 55 actions                │
│  backend/auth.php.php   sessions, currentUser, requireLogin/Admin,    │
│                        CSRF token issue + verify                      │
│  backend/rate_limit.php public-endpoint throttling (rlGuard)          │
│  backend/audit.php      auditLog(), field-level diffs                 │
│  backend/import.php     CSV / XLSX / XLS parsing and validation       │
│  backend/photos.php     photo storage, validation, library helpers    │
│  backend/config.php     DB credentials + rate-limit constants         │
└───────────────────────────────┬──────────────────────────────────────┘
                                │  PDO, prepared statements, utf8mb4
┌───────────────────────────────▼──────────────────────────────────────┐
│  DATA  —  MySQL / MariaDB   (lake_shore_id_system)                   │
│  10 tables  ·  InnoDB  ·  utf8mb4 / utf8mb4_unicode_ci                │
│  Plus on-disk uploads/ for photos, receipts, signatures, templates    │
└──────────────────────────────────────────────────────────────────────┘
```

## 2.2 Technology stack

| Layer | Technology | Version / notes |
|---|---|---|
| Web server | Apache | Via XAMPP, port 80 |
| Language | PHP | 8.x (developed and tested against 8.5) |
| Database | MySQL / MariaDB | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` |
| Database access | PDO | Prepared statements, `ERRMODE_EXCEPTION`, `FETCH_ASSOC` |
| Frontend framework | React | 18.3.x |
| Build tool | Vite | 5.4.x with `@vitejs/plugin-react` |
| Card rendering | html2canvas | 1.4.1 — DOM to canvas for preview and export |
| Bulk export | JSZip | 3.10.2 — zipped multi-card export |
| File download | file-saver | 2.0.5 — triggered PNG / JPG downloads |
| Password hashing | bcrypt | `password_hash()` / `password_verify()` |
| Presentation | python-pptx | 1.0.2 — deck generation |

> There is **no Composer and no npm server-side framework**. The backend is plain
> procedural PHP; the frontend has exactly the six runtime dependencies above.
> This keeps installation to `npm install` plus a database import.

## 2.3 Request lifecycle

A typical write from the staff editor:

1. React calls `api(action, data, method)` with `VITE_API_URL` as the base.
2. `api.php` starts the session (`LSC_ID_SESSION`, HttpOnly, SameSite=Lax,
   `Secure` when HTTPS is detected).
3. CORS is checked against the allow-list when the request carries an
   `Origin` header.
4. The action is matched. Public actions (`studentSaveCard`,
   `studentUploadPhoto`, `lostIdRequest`, `studentLookup`, `publicSignatory`,
   `publicCaptcha`) are routed through `rlGuard()` **first**.
5. `requireLogin()` / `requireAdmin()` gate staff and admin actions; write
   methods call `verifyCsrf()`.
6. Input is normalised and validated. `normalizeCardByType()` applies the
   department rules and the 12-digit LRN rule.
7. For card writes, a MySQL named lock `GET_LOCK('lsc_card_write', 10)` is taken
   around the duplicate check plus the insert, so concurrent identical
   submissions cannot both succeed.
8. The row is written, the lock is released, and `auditLog()` records the action
   with user, IP and user agent.
9. A JSON response is returned with the correct HTTP status.

## 2.4 Folder map

| Path | Purpose |
|---|---|
| `backend/api.php` | All API actions (~100 KB, the core of the backend) |
| `backend/auth.php.php` | Session bootstrap and auth helpers |
| `backend/config.php` | `DB_*` constants, rate-limit constants, `db()` |
| `backend/audit.php` | `auditLog()`, `auditDiffFields()`, `auditFetchRow()` |
| `backend/rate_limit.php` | `rlGuard()` and the whole throttling engine |
| `backend/import.php` | Spreadsheet parsers and import validation |
| `backend/photos.php` | Photo paths, validation, ownership checks |
| `backend/.htaccess` | `Options -Indexes`; deny `.sql`, `.log`, `.ini`, `.env` |
| `backend/uploads/students/` | Student photos, per-student folders |
| `backend/uploads/receipts/` | Lost-ID receipt uploads |
| `backend/uploads/signatures/` | Signatory signature images |
| `backend/uploads/templates/` | Uploaded template artwork |
| `backend/uploads/photos/` | Shared photo assets |
| `backend/tests/` | QA suites and their latest run logs |
| `frontend/src/main.jsx` | The entire React application |
| `frontend/src/styles.css` | The entire stylesheet |
| `frontend/public/` | `lsc-logo.png` and static assets |
| `frontend/.env` | `VITE_API_URL` |
| `database/id_system.sql` | Authoritative schema + seed data |
| `database/migrations/` | Dated one-purpose migrations |
| `documentation/` | This documentation set |
| `presentation/` | PowerPoint generator, deck and verifier |

## 2.5 Frontend views

The React app is a single component tree that switches on a `view` state value.

| View | Access | Purpose |
|---|---|---|
| `dashboard` | staff, admin | Analytics, quick create, saved-record summary |
| `editor` | staff, admin | Create / edit a single ID |
| `records` | staff, admin | Searchable table of all ID records |
| `requests` | staff, admin | Lost-ID reprint inbox |
| `import` | staff, admin | CSV / XLSX / XLS student import |
| `photolibrary` | staff, admin | Student photo library |
| `photoprocessing` | staff, admin | Background removal / cut-out review |
| `templates` | admin | Template designer and versioning |
| `users` | admin | User management |
| `audit` | admin | Audit log viewer |
| `printhistory` | admin | Print history viewer |

The public portal (student form, search, lost-ID report) is rendered outside the
authenticated shell and requires no session.

## 2.6 File naming and storage rules

* Uploaded files are given **unguessable names**: a prefix, a timestamp and
  `bin2hex(random_bytes(6))` — e.g. `processed_1756440000_a1b2c3d4e5f6.png`.
* Student photos live under `uploads/students/{card_id}/photos/{original|processed}/`.
* `photoSafePath()` resolves every path with `realpath()` and verifies it is
  still inside `uploads/students`, which blocks directory-traversal attempts.
* Apache directory listing is disabled, so upload folders cannot be browsed.
* Template files keep a stable name so reprints resolve the same artwork.

## 2.7 Design decisions worth knowing

| Decision | Reason |
|---|---|
| One `api.php` instead of a router | Simple to deploy on shared XAMPP hosting with no rewrite rules |
| Procedural PHP, no framework | Zero dependency install; easy for a small IT team to maintain |
| Validation on the server, not only in React | The public form can be posted to directly; the API must not trust the browser |
| Shared `normalizeCardByType()` for public and staff writes | Guarantees identical rules on both paths |
| Named lock around the duplicate check | Closes the race where two identical submissions both insert |
| `auditLog()` never throws | A logging failure must never break the real operation |
| Rate limiter fails **open** | A database hiccup must never take the student portal offline |
| `released` is terminal | A handed-over ID must not be silently re-printed or re-statused |

---

*Next: [03 — Database](03-database.md)*
