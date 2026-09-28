# 08 — Security

Security is enforced **on the server**. The React form mirrors the rules for a
better user experience, but the API never trusts the browser — the public portal
can be posted to directly.

## 8.1 Layers of defence

| Layer | Control |
|---|---|
| Session | `LSC_ID_SESSION`, HttpOnly, SameSite=Lax, `Secure` when HTTPS |
| Authentication | bcrypt `password_hash()` / `password_verify()`; case-sensitive (BINARY) username match; session id regenerated on login |
| Authorisation | `requireLogin()` for staff actions, `requireAdmin()` for admin-only actions |
| CSRF | Per-session token, verified with `hash_equals()` on every state-changing request |
| CORS | Explicit origin allow-list; credentials only from allow-listed origins |
| Input | PDO prepared statements throughout; shared server-side validator |
| Uploads | MIME + extension allow-list, size and dimension limits, randomised names, no PHP execution |
| Path handling | `realpath()` containment check blocks traversal |
| Abuse | Rate limiting, honeypot and self-hosted captcha on public endpoints |
| Traceability | Append-only audit log with user, IP and user agent |
| Information exposure | Errors return plain language only — never table names, paths, SQL or versions |

## 8.2 Authentication details

* Passwords are stored as **bcrypt** hashes; the plain password is never stored
  or logged.
* Usernames are compared with a `BINARY` (case-sensitive) match, so
  `User@school` cannot sign in as `user@school`.
* `session_regenerate_id(true)` runs on every successful login, preventing session
  fixation.
* `logoutUser()` clears `$_SESSION`, expires the cookie and calls
  `session_destroy()`.
* Setting `is_active = 0` on a user blocks sign-in immediately without deleting
  the account or its audit history.

### Password reset

`forgotPassword` creates a token, stores only its **hash** in `password_resets`,
and **never returns the token in the API response** (out-of-band delivery). The
token expires after 30 minutes and is single-use (`used` flag). This is verified
by the QA suite: no token disclosure, correct expiry, correct single use.

## 8.3 CSRF protection

`generateCsrfToken()` creates a 32-byte random token once per session.
`verifyCsrf()` requires it in the `X-CSRF-Token` header on every write and
compares it with `hash_equals()` (constant-time). A missing or invalid token
returns `403`.

## 8.4 Upload hardening

| Check | Rule |
|---|---|
| MIME type | `image/png`, `image/jpeg`, `image/webp` (plus `application/pdf` for receipts) |
| Extension | Must match the allow-list — `png`, `jpg`, `jpeg`, `webp` (and `pdf`) |
| Size | Maximum 10 MB |
| Dimensions | Minimum 96 × 96 px, maximum 12,000 px per side |
| Validity | `getimagesize()` must succeed, so a renamed non-image is rejected |
| Naming | Random 12-hex-character suffix; the original filename is never used |
| Storage | Under `uploads/`, which never executes PHP |
| Traversal | `photoSafePath()` resolves with `realpath()` and verifies containment |
| Listing | `Options -Indexes` in every uploads folder |

## 8.5 Rate limiting (public portal)

The unauthenticated endpoints — `studentSaveCard`, `studentUploadPhoto`,
`lostIdRequest` and `studentLookup` — are rate limited **server side**, in
`api.php` through `backend/rate_limit.php`.

| Policy | Value |
|---|---|
| Default limit | **5 requests / 10 minutes / IP** |
| Scope | Per endpoint — each action has its own counter |
| On exceeding | **HTTP 429** with `Retry-After` and `X-RateLimit-*` headers |
| Processing | The request is rejected **before** any validation, upload or database write |
| Staff endpoints | Not throttled (they already require a session) |

Blocked clients receive a plain message and the time left in the window. No table
names, file paths, SQL, versions or other system information is ever returned.

### What happens on the 6th request

```json
{
  "success": false,
  "message": "Too many submissions from your network. To keep the ID system safe, only 5 requests are allowed every 10 minutes. Your submission was not processed — please try again in about 4 minutes.",
  "retry_after": 256,
  "retry_after_minutes": 5,
  "captcha_required": true
}
```

with a `Retry-After: 256` response header.

### How counting stays accurate

One row per `(bucket, IP, window)` in `rate_limit_counters`, bumped with a single
atomic statement:

```sql
INSERT ... ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits+1)
```

Parallel requests from one IP therefore cannot race past the limit, and the new
value is read back with `PDO::lastInsertId()` — no extra round trip. Buckets are
per action, so uploading a photo and saving the form do not consume an unrelated
action's quota, while a spammer is still capped per endpoint.

### Client IP handling

`X-Forwarded-For` / `X-Real-IP` are honoured **only** when the immediate peer is
listed in `RATE_LIMIT_TRUSTED_PROXY_IPS`. Trusting those headers unconditionally
would let anyone forge one and get a fresh bucket on every request. IPv6 clients
are counted per `/64` prefix, so rotating temporary addresses share a bucket.

### Human verification (shared networks, automation)

* **Captcha** — a blocked client can request a self-hosted challenge
  (`?action=publicCaptcha`, an inline SVG — no third party, no API key, no GD
  extension) and send the answer back as `captcha_id` + `captcha_code`. A correct
  answer unlocks `RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS` extra attempts **once per
  window** for that IP. This keeps a classroom or dormitory behind one NAT
  address usable without weakening the anonymous limit. Challenges are
  single-use, expire after 5 minutes, accept 4 wrong answers, are bound to the
  address that requested them, and store only an HMAC of the answer. A failed
  check costs the student nothing.
* **Honeypot** — the public forms carry a hidden `website` field; a non-empty
  value marks an automated submission and is rejected with a generic `422`,
  without consuming the human's quota.

### Failure behaviour

If the counters cannot be read or written, the request is **allowed** (fail
open) and the reason is written to the PHP error log, so a database problem can
never take the ID portal offline. Blocked and rejected public traffic is written
to the existing audit log as `rate_limited`, `rate_limit_captcha_unlocked` and
`public_bot_submission`.

## 8.6 Concurrency safety

Both write paths take the MySQL named lock `GET_LOCK('lsc_card_write', 10)`
around the duplicate check **and** the write. Without it, two students submitting
the same details at the same moment could both pass the duplicate check and both
insert. The lock is released only on the `409` path and after the write — an
earlier version released it too early and silently re-opened the race.

Verified result: repeated 6-way parallel identical submissions always yield
**exactly 1 insert and 5 × HTTP 409**.

## 8.7 Data minimisation on the public search

`studentLookup` is unauthenticated, so it returns only a fixed whitelist of
fields: `student_name`, `id_type`, `course`, `grade_level`, `section_name`,
`student_number`, `student_id_number`, `lrn`, `academic_year`, `school_year`.

The response is assembled from that list and never from the raw row, so photos,
home addresses, emergency contacts, staff notes and the print/release lifecycle
cannot leak — even if those columns are added to `id_cards` later. Successful
lookups are audited as `student_lookup`.

## 8.8 Audit logging

`auditLog()` captures the authenticated user (or `NULL` for public actions), the
client IP and the user agent automatically, and JSON-encodes `old_value` and
`new_value`. `auditDiffFields()` produces a field-level diff
(`{field, label, from, to}`) so an edit reads as *"Course: BS Psychology →
BS Accountancy"*.

Crucially, `auditLog()` is wrapped in a try/catch: **it never throws**. A logging
failure must not break the real operation. Log rows are never updated or deleted,
and no API route exists to modify them.

### Events recorded

Card created, quick-created, updated (with diff), status changed, marked done,
deleted, printed, bulk printed, released · template saved / updated / deleted /
activated · signatory saved / updated / deleted · user created / updated /
enabled / disabled · lost-ID submitted / approved / updated / deleted · login,
logout, failed login, password reset requested · student lookup · rate limited,
captcha unlocked, public bot submission.

## 8.9 Documented, by-design trade-offs

These are conscious decisions, not silent gaps:

* **Public signatories and student photos are served without a login.** The
  student portal needs to preview a photo it has just uploaded before any account
  exists. Mitigations: unguessable 32-hex file names and Apache
  `Options -Indexes` so directories cannot be listed. For stricter hosting, add
  `Require all denied` in Apache and serve images through an authenticated PHP
  endpoint if the portal is switched to a login-based flow.
* **No database-level `UNIQUE` constraints or foreign keys.** Uniqueness and
  referential integrity are enforced in the application layer; the QA database
  check verifies zero duplicate groups and zero orphans after every run. (The
  exception is `id_templates`, which does use `UNIQUE` keys and a generated
  column for the single-active-template rule.)
* **Two open policy questions** (EXT-030 / EXT-031): two *different* students
  submitting the *same* student number or LRN under different names are both
  accepted. Making identifiers globally unique is a product decision that
  affects re-enrolment and reprint workflows, so it is flagged rather than
  silently changed.

## 8.10 Security checklist for go-live

- [ ] Change the seeded admin password
- [ ] Create individual named accounts; no shared logins
- [ ] Give every account the least privilege that does the job
- [ ] Serve over HTTPS if the system is reachable outside the school LAN
- [ ] Confirm `.htaccess` is honoured (`AllowOverride All`)
- [ ] Confirm `backend/.htaccess` denies `.sql`, `.log`, `.ini`, `.env`
- [ ] Restrict phpMyAdmin access or remove it from a production host
- [ ] Set a real MySQL password instead of a blank `root` password
- [ ] Configure `RATE_LIMIT_TRUSTED_PROXY_IPS` if behind a proxy
- [ ] Take a first backup (see [09 — Operations](09-operations.md))
- [ ] Review the audit log weekly

---

*Next: [09 — Operations](09-operations.md)*
