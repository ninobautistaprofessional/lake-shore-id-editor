# 04 — API Reference

Every backend capability is exposed through **one entry point**:

```
http://localhost/lake-shore-id-editor/backend/api.php?action=<actionName>
```

The base URL is set once in `frontend/.env` as `VITE_API_URL`.

## 4.1 Conventions

| Rule | Detail |
|---|---|
| Request body | JSON, or `multipart/form-data` when uploading a file |
| Response | Always JSON, `Content-Type: application/json; charset=utf-8` |
| Success shape | `{ "success": true, "data": ... }` |
| Failure shape | `{ "success": false, "message": "..." }` |
| Authenticated reads | Session cookie `LSC_ID_SESSION` |
| Authenticated writes | Session cookie **plus** header `X-CSRF-Token` |
| Status codes | `200` OK · `401` not logged in · `403` forbidden / bad CSRF · `404` not found · `409` conflict · `422` validation · `429` rate limited · `503` busy |

The `message` field is always plain language. Table names, file paths, SQL,
versions and other system details are never disclosed to a client.

## 4.2 Public endpoints (no login)

These four data endpoints are rate limited; all public routes accept the
honeypot field `website` and a captcha unlock.

| Action | Method | Purpose |
|---|---|---|
| `studentSaveCard` | POST | Create an ID from the public form |
| `studentUploadPhoto` | POST | Upload a student photo from the public form |
| `studentLookup` | POST | Find an existing record by student number / ID / LRN |
| `lostIdRequest` | POST | Report a lost ID and request a reprint |
| `publicSignatory` | GET | Active signatories, so the portal can show a signature |
| `publicCaptcha` | GET | Issue a self-hosted SVG captcha challenge |

### `studentLookup` in detail

Students who already have an ID should not retype their details. They enter their
student number and press **Search**.

| Aspect | Behaviour |
|---|---|
| Input | `student_number` — matched against `student_number`, `student_id_number` **and** `lrn` |
| Found | `200` with `data` (whitelisted fields only) and `card_id` |
| Not found | `404` — *Student Not Found. No ID record matches that Student Number.* |
| Invalid | `422` — empty, under 4 characters, bad characters, or longer than the column |
| Rate limited | `429` after 5 requests / 10 minutes / IP, in its own `public:studentLookup` bucket |

Returned fields (exactly these, in this order):

```
student_name, id_type, course, grade_level, section_name,
student_number, student_id_number, lrn, academic_year, school_year
```

The response is re-keyed through that list and never built from the raw row, so
photos, home addresses, emergency contacts, staff notes and the whole
print/release lifecycle can never leak through the unauthenticated search — even
if those columns are added to `id_cards` later. The matching constant in the
frontend is `PUBLIC_LOOKUP_FIELDS` in `frontend/src/main.jsx`.

**Why it cannot be used to enumerate students**

* **Exact match only** — never `LIKE`, so numbers cannot be walked one prefix at
  a time (`2026` returns nothing, never a list).
* **One row** — `LIMIT 1`; it can only answer about the number submitted.
* **Minimum length** — below 4 characters nothing reaches the database.
* **No wildcards** — `%` and `_` are not in the accepted character set, so they
  cannot become SQL patterns.
* **Server-side sanitising** — `normalizeStudentNumber()` strips control and
  zero-width characters, allows only `A–Z 0-9 - / _ .` and a space, and
  **rejects rather than truncates** an over-long value (a truncated number could
  match a different student).
* **Separate rate-limit bucket** — a search never eats the student's submission
  quota.
* Successful lookups are written to the audit log as `student_lookup`.

## 4.3 Authentication

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `login` | POST | Public | Authenticate; regenerates the session id |
| `logout` | POST | Session | Clear the session and expire the cookie |
| `me` | GET | Session | Current user identity and role |
| `forgotPassword` | POST | Public | Create a reset token; **never returns it** |
| `resetPassword` | POST | Public | Consume a token (30-minute, single use) |

`login` matches the username with a **BINARY (case-sensitive)** comparison, so
`User@x` cannot log in as `user@x`. A successful login regenerates the session id
to prevent session fixation.

## 4.4 ID records

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `cards` | GET | staff | List records; supports search, filters, pagination |
| `card` | GET | staff | One record by `id`, with `released_by_name` and `photo_count` |
| `saveCard` | POST | staff | Create or update a record |
| `quickCreateStudent` | POST | staff | Create the shell record, then add photos |
| `deleteCard` | POST | staff | Remove a record |
| `setCardStatus` | POST | staff | Move to `created` / `done` / `edited` / `printed` |
| `printCard` | POST | staff | Record a print; writes `print_history` |
| `bulkPrint` | POST | staff | Print many cards with per-card type and reason |
| `releaseCard` | POST | staff | Release a printed ID to the student |
| `dashboardStats` | GET | staff | Analytics for the dashboard |

`cards` search matches `student_name`, `student_number`, `student_id_number`,
`lrn`, `course`, `grade_level` and `section_name`.

### Duplicate prevention on write

`studentSaveCard` (public) and `saveCard` (staff) share the same rule: the same
**name** plus any one of **student number / student ID number / LRN** is a
duplicate and returns `409 Conflict`. The staff path excludes the record being
edited, so saving an existing card never flags itself.

On top of that, a record located via `studentLookup` sends its `card_id` back
with the submission as `existing_card_id`. `studentSaveCard` re-verifies that id
against the database **inside the existing write lock** and answers `409` if that
record still carries one of the submitted identifiers — so a student who searches
and then submits cannot create a second row, even with a slightly different
spelling of their name. The form also swaps "Submit ID" for "Report Lost ID" as
soon as a record is found.

### Print and release rules

* The first print is `print_type = 'original'`; every later print is
  `'reprint'` and **requires a reason** (Damaged / Lost / Incorrect Information /
  Other, or free text up to 255 characters).
* `printCard` and `bulkPrint` keep `status`, `printed_at` and `print_count` in
  sync in the same transaction, and append one immutable `print_history` row.
* `releaseCard` requires the ID to be `printed`; it stores `released_by`,
  `released_at`, `release_notes` (max 1000 characters) and `student_received`,
  and audits `card_released` with the old and new status.
* `released` is terminal: re-releasing or changing the status of a released ID
  returns `409` and changes nothing.

### `dashboardStats`

Returns `summary` (totals), `by_type` (per-department rows), `monthly`
(generated vs. printed series), `lost_ids` and `reprints` counts, plus the
`filters` that produced them (`date_from`, `date_to`, `id_type`, `status`).

## 4.5 Templates

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `templates` | GET | staff | All templates, with `fields_json` decoded |
| `saveTemplate` | POST | **admin** | Create a template or a new version of one |
| `setActiveTemplate` | POST | **admin** | Activate a version for its department |
| `deleteTemplate` | POST | **admin** | Remove a non-system template |

Versioning rules:

* A template family is a root row (`parent_id IS NULL`) plus version rows.
* `UNIQUE (parent_id, version)` allows only one row per version number.
* `UNIQUE (active_dept)` allows only one **active** template per department,
  enforced by the database.
* Saving over an existing template creates version *n+1* rather than mutating
  version *n*, so published cards keep their design.
* `is_system = 1` templates are protected: they cannot be deleted or renamed.
* When a card is saved, `pinCardTemplateVersion()` copies the active version's
  `id` and `version` onto the card.

## 4.6 Signatories, settings and users

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `signatories` | GET | staff | Active signatories |
| `saveSignatory` | POST | staff | Create or update a signatory and signature image |
| `settings` | GET | staff | Institution settings key/value |
| `users` | GET | **admin** | List accounts |
| `saveUser` | POST | **admin** | Create or update an account (including enable/disable) |
| `deleteUser` | POST | **admin** | Remove an account |

## 4.7 Photo library

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `uploadPhoto` | POST | staff | Upload a photo against a record |
| `photoLibraryUpload` | POST | staff | Upload to the library (original) |
| `photoLibraryUploadProcessed` | POST | staff | Upload a processed cut-out |
| `photoLibraryList` | GET | staff | List photos, filterable by source/type/status |
| `photoLibraryPreferred` | GET | staff | The current preferred photo for a student |
| `photoLibrarySetPreferred` | POST | staff | Mark a photo as preferred |
| `photoLibrarySearch` | GET | staff | Search the library |
| `photoLibraryClone` | POST | staff | Copy a photo to another student |
| `photoLibraryReplace` | POST | staff | Replace a photo in place |
| `photoLibraryArchive` | POST | staff | Archive (soft delete) |
| `photoLibraryRestore` | POST | staff | Restore an archived photo |
| `photoLibraryDelete` | POST | staff | Hard delete |
| `photoLibraryServe` | GET | staff | Stream a stored photo |
| `deleteUpload` | POST | staff | Remove an uploaded file |

## 4.8 Import

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `importTemplate` | GET | staff | Download the blank import template (CSV) |
| `importUpload` | POST | staff | Upload and parse a CSV / XLSX / XLS file |
| `importCommit` | POST | staff | Insert the validated rows in one transaction |
| `bulkGenerateAudit` | POST | staff | Return an audit summary of a bulk generation run |

The import workflow is **Upload → Validate → Preview → Detect duplicates →
Import → Generate IDs**. Limits: `IMPORT_MAX_ROWS = 5000` and
`IMPORT_MAX_FILE_BYTES = 10485760` (10 MB). Validation reuses
`normalizeCardByType()`, adds required-field and format checks, and detects
duplicates both *inside the file* and *against the database*. The commit runs in
one transaction while holding `GET_LOCK('lsc_card_write')`, so a failed import
never leaves partial records and an existing student is never imported twice.
Photos are optional — a `Photo File` value that does not exist results in the row
being imported without a photo and a warning in the preview.

## 4.9 Lost-ID requests

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `lostIdRequests` | GET | staff | Inbox list |
| `lostIdRequest` | GET | staff | One request |
| `updateLostIdRequest` | POST | staff | Approve / update / delete a request |
| `deleteLostIdRequest` | POST | staff | Delete a request |

A public submission that matches no existing ID is rejected with a clear message
telling the student to have the ID created first, so the lost-ID form cannot be
used to probe the database.

## 4.10 Audit and history

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `auditLogs` | GET | **admin** | Paginated, filterable audit log |
| `printHistory` | GET | **admin** | Print jobs, filterable by card, user, type, date |

`auditLogs` supports `search`, `action`, `user_id`, `date_from`, `date_to` and
pagination. It is **read-only by design** — no update or delete route exists for
audit rows. The read endpoint requires `admin`; anonymous callers get `401` and
staff get `403`.

## 4.11 HTTP status codes used

| Code | Meaning in this system |
|---|---|
| `200` | Success |
| `401` | Not logged in (`Unauthorized. Please log in.`) |
| `403` | Logged in but not permitted, or missing/invalid CSRF token |
| `404` | Record not found, or student number not found |
| `409` | Conflict — duplicate student, released ID, or invalid status change |
| `422` | Validation failed (missing field, bad course, bad LRN, invalid input) |
| `429` | Rate limited — includes `Retry-After` and `X-RateLimit-*` headers |
| `503` | Could not take the write lock (server busy) |

---

*Next: [05 — Installation](05-installation.md)*
