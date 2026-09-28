# 10 — Testing and Quality Assurance

The system ships with a substantial automated QA harness. Every suite runs
against a **live server** and cleans up everything it creates.

## 10.1 Running the suites

Start **Apache and MySQL** first, then from the project root:

```cmd
php backend\tests\qa_db_check.php              :: read-only DB integrity check
php backend\tests\qa_api_test.php              :: core API suite
php backend\tests\qa_api_test2.php             :: adversarial / edge-case suite
php backend\tests\qa_audit_test.php            :: audit logging
php backend\tests\qa_release_test.php          :: release lifecycle
php backend\tests\qa_print_history_test.php    :: print history
php backend\tests\qa_bulk_print_test.php       :: bulk printing
php backend\tests\qa_dashboard_test.php        :: dashboard analytics
php backend\tests\qa_student_lookup_test.php   :: public student search
php backend\tests\qa_import_test.php           :: CSV / XLSX / XLS import
php backend\tests\qa_rate_limit_test.php       :: rate limiting, live API
php backend\tests\qa_rate_limit_ip_test.php    :: client address resolution, offline
```

Each suite writes its latest output to a matching `qa_*_run_latest.txt` file.

> The suites create their own temporary `qa_admin_test` / `qa_staff_test` users
> and sample data, run every scenario through
> `http://localhost/lake-shore-id-editor/backend/api.php`, then remove
> everything they created (users, cards, lost-ID requests, uploads).

## 10.2 Current results

| Suite | Cases | Result |
|---|---|---|
| `qa_api_test.php` | 68 | **68 PASS / 0 FAIL** |
| `qa_api_test2.php` | 42 | 40 PASS / 2 documented policy items |
| `qa_audit_test.php` | 26 | **26 PASS / 0 FAIL** |
| `qa_release_test.php` | 12 | **12 PASS / 0 FAIL** |
| `qa_print_history_test.php` | 22 | all PASS |
| `qa_bulk_print_test.php` | 23 | all PASS |
| `qa_dashboard_test.php` | 21 | all PASS |
| `qa_student_lookup_test.php` | 30 | all PASS |
| `qa_import_test.php` | 24 | all PASS |
| `qa_rate_limit_test.php` | 42 | **42 PASS / 0 FAIL** |
| `qa_rate_limit_ip_test.php` | 12 | **12 PASS / 0 FAIL** |
| **Total** | **322** | **320 PASS / 2 flagged policy items** |

Latest recorded runs are in `backend/tests/qa_*_run_latest.txt`. A full
narrative of the QA cycle, the defects found and the fixes applied is in
`backend/tests/QA_REPORT_2026-09-05.md`.

## 10.3 Coverage by area

| Area | Covered by | Examples |
|---|---|---|
| Authentication and session | AUTH-* | Login, logout invalidates session, case-sensitive username, brute-force handling |
| Authorisation, CSRF, CORS, info exposure | SEC-* | Anonymous 401, staff 403 on admin routes, missing CSRF 403, no SQL/paths in errors |
| Public self-service | FUNC-*, VAL-* | Create, upload, validation messages, HTTP status codes |
| Lost-ID flow | FUNC-007…010, FILE-001 | Request, receipt upload, staff review |
| Upload security | FILE-* | MIME spoofing, extension mismatch, path traversal, oversized files |
| Search and filter | SRCH-* | Search depth, SQL injection attempts, filters |
| Department rules | VAL-*, EXT-* | Grade band matrix, LRN 12-digit matrix, course allow-list |
| Duplicates and races | DUP-*, EXT-090 | 6-way parallel identical submissions -> exactly 1 insert |
| Dashboard analytics | DA-* | Totals match SQL, per-department sums, monthly series, lost/reprint counts |
| Print history | PH-* | Original vs reprint, reason required, counter and timestamp in sync |
| Bulk print | BP-* | Multi-card print, skipped and missing ids reported |
| Release lifecycle | RLS-* | Only printed IDs release, terminal state, permissions, released_by_name |
| Audit logging | AUD-* | Every required action, old/new values, IP, user agent, filters, pagination |
| Public search | SL-* | Found / not found, privacy, validation, enumeration resistance, duplicates |
| Import | CSV-*, COM-*, TPL-*, DBDUP-* | Parsers, department detection, in-file and database duplicates, transaction |
| Rate limiting | RL-* | 429, Retry-After, per-bucket counters, captcha issue/accept, honeypot |
| Address resolution | IP-* | Trusted proxy handling, XFF chains, IPv6 /64 bucketing, fail-open |

## 10.4 Notable verified behaviours

| Behaviour | How it is verified |
|---|---|
| 6 parallel identical submissions create exactly one row | `EXT-090` — `rows=1 statuses=[409,200,409,409,409,409]` |
| A released ID cannot be re-released or re-statused | `RLS-*` — 409 with data intact |
| An edit records a readable field diff | `AUD-*` — e.g. `Course: BS Psychology -> BS Accountancy` |
| The unauthenticated search cannot enumerate students | `SL-*` — prefix searches return nothing; `LIMIT 1` |
| Rate-limit counters cannot be raced | `RL-*` — parallel hits still cap at the limit |
| A database hiccup never takes the portal offline | fail-open path exercised in `RL-*` |
| `GET ?action=cards` performance | `PERF-001` — under 1000 ms (10–27 ms observed) |

## 10.5 The two open items

`EXT-030` and `EXT-031` in `qa_api_test2.php` are **policy questions, not
defects**. The duplicate rule keys on *name + any identifier*. Two different
students who submit the **same student number** (or LRN) under different names
are both accepted.

Making identifiers globally unique is a product decision that affects
re-enrolment and reprint workflows, so it is flagged for the owner rather than
silently changed. The decision needed: *should a student number or LRN be unique
across the whole school, or only per student name?*

## 10.6 Manual test checklist

After any deployment or update:

| # | Check | Expected |
|---|---|---|
| 1 | Open the public portal | Loads with no console errors |
| 2 | Submit a College ID with a valid course | Saved, reference number shown |
| 3 | Submit a College ID with an invalid course | Rejected with a clear message |
| 4 | Submit a JHS ID with `GRADE 12` | Rejected (out of band) |
| 5 | Submit with an 11-digit LRN | Rejected |
| 6 | Submit the same student twice | Second attempt returns a duplicate message |
| 7 | Search an existing student number | `Student Found`, form fills |
| 8 | Search a non-existent number | `Student Not Found` |
| 9 | Sign in as staff | Dashboard loads; admin menus hidden |
| 10 | Sign in as admin | All menus visible |
| 11 | Open a record, mark it Done | Status becomes `done` |
| 12 | Edit a done record | Status becomes `edited` |
| 13 | Print the card | Status `printed`, counter +1, one history row |
| 14 | Release the card | Status `released`, released-by recorded |
| 15 | Try to print a released card | Refused with a clear message |
| 16 | Export front and back as PNG and JPG | Four files download, correct aspect ratio |
| 17 | Submit a lost-ID request with a receipt | Appears in the staff inbox |
| 18 | Approve and reprint | Reprint recorded with the reason |
| 19 | Import a small CSV | Preview shows valid rows; commit inserts them |
| 20 | Import the same CSV again | Rows flagged as duplicates; nothing inserted |
| 21 | Make 6 public requests quickly | 6th returns 429 with a countdown |
| 22 | Solve the offered captcha | Extra attempts granted |
| 23 | Open Audit, filter by today's date | Today's actions listed with user and IP |

---

*Next: [11 — Troubleshooting](11-troubleshooting.md)*
