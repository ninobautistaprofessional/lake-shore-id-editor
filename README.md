# Lake Shore Colleges ID Management System

This version adds the full student ID workflow for **College, Junior High School and Senior High School**, while keeping the previous editable back-side ID and signatory features.

## New features

### College Department
- Course dropdown with 8 requested programs, stored/displayed in ALL CAPS
- Editable student name
- Editable student ID number
- Editable academic year
- ID photo upload

### Basic Education
- Separate Junior High School dropdown: Grade 7, 8, 9, 10
- Separate Senior High School dropdown: Grade 11, 12
- Editable section
- Editable ID number and LRN
- Editable school year
- Color-coded name bands:
  - Grade 7: Green
  - Grade 8: Yellow
  - Grade 9: Blue
  - Grade 10: Red
  - Grade 11: Purple
  - Grade 12: Orange

### Dashboard
- Create College ID
- Create Junior High ID
- Create Senior High ID
- Saved ID records
- Front and back Smart ID 51 / CR80 proportion preview
- PNG and JPG export buttons for each side

### Smart ID export
The source design uses a portrait proportion of **642 × 1013**. The preview/export uses the same proportion and a high-resolution 3× render. It is suitable as a transfer image for Smart ID 51 software. Configure the final print size in the Smart ID software according to your card setup (commonly CR80 54 × 85.6 mm portrait).

## Fresh installation on XAMPP

1. Extract the project to:
   `C:\xampp\htdocs\lake-shore-id-editor`
2. Start Apache and MySQL.
3. Open phpMyAdmin: `http://localhost/phpmyadmin`
4. Import `database/id_system.sql`.
5. Check `backend/config.php`:
   - DB_HOST = localhost
   - DB_NAME = lake_shore_id_system
   - DB_USER = root
   - DB_PASS = empty by default on XAMPP
6. Test the backend:
   `http://localhost/lake-shore-id-editor/backend/api.php?action=cards`
7. Open Command Prompt:
   ```cmd
   cd C:\xampp\htdocs\lake-shore-id-editor\frontend
   npm install
   npm run dev
   ```
8. Open the Vite address, normally `http://localhost:5173`.

## Updating an existing version

If you already use the previous version and want to preserve your records:

1. Replace the project files with this new version.
2. Import `database/migrate_existing.sql` in phpMyAdmin.
3. Import `database/id_system.sql` if you need to ensure the signatories/default settings exist.
4. Run `npm install` again inside the frontend folder.
5. Run `npm run dev`.

## Important folders

- Student photos: `backend/uploads/students/`
- Signatures: `backend/uploads/signatures/`
- React logo: `frontend/public/lsc-logo.png`
- Segmentation model (self-hosted, 244 KB): `frontend/public/models/selfie_segmenter.tflite`
- MediaPipe Wasm runtime (self-hosted): `frontend/public/mediapipe/`

## Production

Build the React app with:

```cmd
cd frontend
npm install
npm run build
```

Then deploy the contents of `frontend/dist` to your web server and set `VITE_API_URL` if the API URL is different.

## Student search (existing student detection)

Students who already have an ID record do not retype their details. On the
public "Create ID" form they enter their **Student Number** and press
**Search**; the API returns that one record, the form is filled in
automatically, and the panel shows either **Student Found** or
**Student Not Found**.

```
Student Number: 2026-00125   ->  [Search]  ->  Student Found / Student Not Found
```

| Item | Detail |
|---|---|
| Endpoint | `POST api.php?action=studentLookup` |
| Input | `student_number` (matched against `student_number`, `student_id_number` **and** `lrn` — the same identity triple the duplicate check already uses) |
| Found | `200` with `data` (whitelisted fields only) and `card_id` |
| Not found | `404` `Student Not Found. No ID record matches that Student Number.` |
| Invalid input | `422` (empty, under 4 characters, bad characters, or longer than the column) |
| Rate limited | `429` after 5 requests / 10 minutes / IP, in its own `public:studentLookup` bucket, with the same honeypot and captcha escape hatch as the other public endpoints |

### What is returned

Only what the form needs, in this exact list (`studentLookupColumns()` in
`backend/api.php`, mirrored by `PUBLIC_LOOKUP_FIELDS` in
`frontend/src/main.jsx`):

`student_name`, `id_type`, `course`, `grade_level`, `section_name`,
`student_number`, `student_id_number`, `lrn`, `academic_year`, `school_year`

The response is re-keyed through that list and never built from the raw row,
so photos, home addresses, emergency contacts, staff notes and the whole
print/release lifecycle cannot leak through the unauthenticated search —
even if those columns are added to `id_cards` later.

### Why it cannot be used to enumerate students

* **Exact match only** — never `LIKE`, so numbers cannot be walked one
  prefix at a time (`2026` returns nothing, never a list).
* **One row** — `LIMIT 1`; the endpoint can only ever answer about the
  number that was submitted.
* **Minimum length** — below 4 characters nothing is sent to the database.
* **No wildcards** — `%` and `_` are not in the accepted character set, so
  they cannot become SQL patterns.
* **Server-side sanitising** — `normalizeStudentNumber()` strips control and
  zero-width characters, allows only `A–Z 0-9 - / _ .` and a space, and
  *rejects* rather than truncates an over-long value (a truncated number
  could match a different student).
* **Rate limited** — 5 per 10 minutes per IP, separate from the other
  public buckets so a search never eats the student's submission quota.
* Successful lookups are written to the audit log as `student_lookup`.

### Duplicate prevention

The pre-existing check (same name + same number / ID / LRN → `409`) is
unchanged. On top of it, a record located by the search sends its
`card_id` back with the submission as `existing_card_id`. `studentSaveCard`
re-verifies that id against the database inside the existing write lock, and
answers `409` if that record still carries one of the submitted
identifiers — so a student who searches and then submits cannot create a
second row, even if they spelled their name slightly differently. The form
also swaps "Submit ID" for "Report Lost ID" as soon as a record is found.

### Tests

```cmd
php backend/tests/qa_student_lookup_test.php  :: 41 live-API cases (found, not found, privacy, validation, enumeration, rate limit, duplicates, regressions)
```

## Rate limiting (public portal)

The unauthenticated endpoints — `studentSaveCard`, `studentUploadPhoto`,
`lostIdRequest` and `studentLookup` — are rate limited **server side** (in
`api.php`, through `backend/rate_limit.php`). The React form only mirrors
the message; the limit itself never depends on the browser.

| Policy | Value |
|---|---|
| Default limit | **5 requests / 10 minutes / IP** |
| Scope | per endpoint (each action has its own counter) |
| On exceeding | **HTTP 429**, `Retry-After`, `X-RateLimit-*` headers |
| Processing | the request is rejected **before** any validation, upload or database write |
| Staff endpoints | not throttled (they already require a session) |

Blocked clients get a plain message, the time left in the current window and —
when a human check is offered — a captcha prompt. No table names, file paths,
SQL, versions or any other system information is ever returned.

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

with `Retry-After: 256` on the response.

### Human verification (shared networks, automation)

* **Captcha** — a blocked client can request a self-hosted challenge
  (`?action=publicCaptcha`, an inline SVG, no third party and no GD
  extension) and send the answer back as `captcha_id` + `captcha_code`.
  A correct answer unlocks `RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS` extra attempts
  **once per window** for that IP. This is what keeps a classroom or dormitory
  behind a single NAT address usable without weakening the anonymous limit.
  Challenges are single use, expire after 5 minutes, accept 4 wrong answers,
  are bound to the address that requested them and only store an HMAC of the
  answer. A failed check costs the student nothing.
* **Honeypot** — the public forms carry a hidden `website` field; a non-empty
  value means an automated submission and is rejected with a generic 422.

### Configuration (backend/config.php)

| Constant | Default | Purpose |
|---|---|---|
| `RATE_LIMIT_ENABLED` | `true` | master switch |
| `RATE_LIMIT_MAX_ATTEMPTS` | `5` | requests per window per IP per action |
| `RATE_LIMIT_WINDOW_SECONDS` | `600` | window length (10 minutes) |
| `RATE_LIMIT_HONEYPOT_FIELD` | `'website'` | set to `''` to disable the honeypot |
| `RATE_LIMIT_CAPTCHA_ENABLED` | `true` | offer the human check |
| `RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS` | `5` | extra attempts after a solved captcha |
| `RATE_LIMIT_CAPTCHA_ISSUE_LIMIT` | `10` | challenges one IP may request per window |
| `RATE_LIMIT_CAPTCHA_TTL` | `300` | challenge lifetime in seconds |
| `RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS` | `4` | wrong answers per challenge |
| `RATE_LIMIT_SECRET` | `''` | captcha HMAC secret (auto-generated into `system_settings` when empty) |
| `RATE_LIMIT_TRUSTED_PROXY_IPS` | `[]` | proxies allowed to send `X-Forwarded-For` |
| `RATE_LIMIT_GC_PROBABILITY` | `10` | 1 in N requests also clean up expired rows |

Behind a reverse proxy / load balancer, list its addresses in
`RATE_LIMIT_TRUSTED_PROXY_IPS`, otherwise the real client address is
`REMOTE_ADDR` (which is the safe default: an untrusted `X-Forwarded-For`
header would otherwise let anyone reset the limiter with every request).
IPv6 clients are counted per `/64` so rotating temporary addresses share a
bucket.

Counters live in `rate_limit_counters`, `rate_limit_grants` and
`rate_limit_captchas`; the tables are created automatically on first use, or
they can be imported from
`database/migrations/2026_09_28_create_rate_limits.sql`. Blocked and rejected
public traffic is written to the existing audit log as `rate_limited`,
`rate_limit_captcha_unlocked` and `public_bot_submission`.

If the counter storage is unavailable the request is **allowed** (fail open)
and the reason is written to the PHP error log, so a database problem can never
take the ID portal offline.

### Tests

```cmd
php backend/tests/qa_rate_limit_test.php     :: 41 live-API cases (429, retries, captcha, honeypot, buckets)
php backend/tests/qa_rate_limit_ip_test.php  :: 12 offline client-address cases
```

`studentLookup` (the student-number search) is covered by its own suite; it
uses the same policy and the same `rlGuard()` call as the endpoints above.