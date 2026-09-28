# 11 — Troubleshooting

## 11.1 Installation and startup

**The page is blank / "This site can't be reached"**

1. Are Apache and MySQL both green in the XAMPP Control Panel?
2. Is the project really at `C:\xampp\htdocs\lake-shore-id-editor`?
3. Try `http://localhost/` — if that also fails, Apache is not running or is
   occupied by another web server (IIS, Skype, or Docker on port 80).

**`npm run dev` fails**

| Message | Fix |
|---|---|
| `'npm' is not recognized` | Install Node.js 18+ and reopen the command prompt |
| `Cannot find module` / `vite: not found` | You skipped `npm install` — run it inside `frontend` |
| `EADDRINUSE: port 5173` | Another Vite process is running. Close it, or run `npm run dev -- --port 5174` |
| `Cannot find package.json` | You are in the wrong folder — `cd` into `frontend` |

**The frontend loads but every action fails**

`VITE_API_URL` in `frontend/.env` must point at the real API, and Apache must be
running:

```
VITE_API_URL=http://localhost/lake-shore-id-editor/backend/api.php
```

Restart `npm run dev` after changing `.env` — Vite only reads it at start-up.

## 11.2 Database

**"Unknown database 'lake_shore_id_system'"**

Import `database/id_system.sql` in phpMyAdmin, or create it manually:

```sql
CREATE DATABASE lake_shore_id_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**"Access denied for user 'root'@'localhost'"**

Set the correct password in `backend/config.php`. On default XAMPP the `root`
password is empty. If you set one, put it in `DB_PASS`.

**Tables exist but a column is missing**

An old installation is missing newer columns. Import the relevant file from
`database/migrations/` in date order. Re-importing `database/id_system.sql` will
not add columns to an existing table — use the migration for that.

**"Table 'lake_shore_id_system.rate_limit_counters' doesn't exist"**

The rate-limit tables are created automatically on first use. Import
`database/migrations/2026_09_28_create_rate_limits.sql` to create them up front.

## 11.3 Authentication

**"Invalid security token" (403)**

Your session expired or the page was open a long time. Reload the page and sign
in again. If it repeats, check that cookies are enabled and that the system clock
is correct.

**Cannot sign in with a username that "exists"**

Usernames are matched **case-sensitively**. `User@school` and `user@school` are
different accounts.

**Account disabled**

An administrator set `is_active = 0`. Ask them to re-enable it in **Users**, or
to use **Forgot Password**.

**Password reset never arrives**

`forgotPassword` deliberately does not return the token — that is the security
design. Delivery is out-of-band; contact the administrator to have the account
reset.

## 11.4 Public portal

**"Too many submissions from your network" (429)**

You have used 5 requests in the current 10-minute window. Wait for the countdown,
or solve the captcha challenge when offered. In a shared lab, an administrator can
raise `RATE_LIMIT_MAX_ATTEMPTS` in `backend/config.php`.

**"This ID already exists" (409)**

A record with the same name and student number / ID number / LRN is already in
the system. Use the **Search** box to find it, then report a lost ID if you need a
replacement.

**"Student Not Found"**

No record matches that number. It may be typed differently, or the ID may not
have been created yet. Check the number, or create the ID.

**"Please select a valid course" / "valid grade level"**

The value sent is not in the allow-list. On the public form this usually means
the page is out of date — refresh the browser.

**"LRN must be exactly 12 digits"**

The LRN is Basic Education only and must be exactly 12 digits, or left blank.

**Photo upload fails**

| Message | Cause |
|---|---|
| `Only PNG, JPG or WEBP student photos are allowed` | Wrong format — convert it |
| `Invalid file extension` | The extension does not match the allowed list |
| `Photo is too large (max 10 MB)` | Reduce the file size |
| `Image resolution is too low (minimum 96x96 px)` | Use a larger image |
| Upload silently fails | Raise `upload_max_filesize` and `post_max_size` in `php.ini` |

## 11.5 Records, printing and templates

**"This ID has already been released" (409)**

`released` is terminal. A released card cannot be re-statused, re-released or
printed again. If a genuinely new card is needed, create a new record or process
a lost-ID reprint.

**"Only a printed ID can be released" (422)**

Release comes after printing. Print the card first, then release it.

**A reprint asks for a reason**

Every print after the first is a reprint and must state why: Damaged, Lost,
Incorrect Information or Other.

**"Server busy" (503)**

Another write held the `lsc_card_write` lock for longer than 10 seconds — a very
large import, most likely. Wait and retry; if it persists, check for a long
running import.

**The card preview does not match the printed card**

Check that the card's **pinned template version** is the one you expect. Cards
keep the version they were generated with, so activating a newer template will
not change an existing card — that is by design.

**No template appears for a department**

Each department needs an active template. In **Templates**, activate one for
College, Junior High and Senior High. The database permits only one active
template per department.

**"Cannot delete/rename the system template"**

Correct — the three *Original LSC Design* templates are protected. Create your
own template and activate that instead.

## 11.6 Import

| Symptom | Cause and fix |
|---|---|
| All rows marked invalid | Check the required columns; download the template and match its headers |
| Rows flagged as duplicates | They already exist in the database, or repeat inside the file |
| "File is too large" | Over 10 MB or 5,000 rows — split the file |
| Department detected wrongly | Add an explicit `Department` column, or name the sheet clearly |
| Photos missing after import | The `Photo File` entry did not exist in `backend/uploads/students/` — a warning, not an error |
| Nothing imported after an error | Correct behaviour — the commit is one transaction, so a failure writes nothing |

## 11.7 Getting more information

| Where | What to look at |
|---|---|
| Browser developer tools -> Console | JavaScript errors, CORS failures |
| Browser developer tools -> Network | The exact API request, status and JSON response |
| XAMPP -> Apache -> Error Log | PHP errors and warnings |
| phpMyAdmin -> `audit_logs` | Who did what, when, from which IP |
| `php backend\tests\qa_db_check.php` | Row counts, duplicates, orphans |
| `backend/tests/qa_*_run_latest.txt` | The last test results |

## 11.8 Getting help

When reporting a problem, include:

1. What you were doing and what you expected to happen.
2. The exact error message shown.
3. The browser and the URL.
4. Whether the problem affects one student or all of them.
5. The time it happened — the audit log can confirm what the server saw.

---

*Back to the [documentation index](README.md)*
