# 09 — Operations and Maintenance

## 9.1 What must be backed up

A complete backup is **two things**: the database, and the `uploads` folder.
The database stores only *relative* paths into `uploads/`, so both are required.

| What | Why |
|---|---|
| Database `lake_shore_id_system` | All student records, users, templates, audit log, print history |
| `backend/uploads/` | Student photos, signatures, receipts, template artwork |
| `frontend/public/lsc-logo.png` | The logo used on the card design |
| `backend/config.php` | Credentials and rate-limit settings (store securely) |

## 9.2 Backup procedure

### Database export

In phpMyAdmin: select the `lake_shore_id_system` database -> **Export** ->
**Quick** -> **SQL** -> **Add DROP TABLE** -> **Go**. Save the `.sql` file.

Or from a command prompt:

```cmd
cd C:\xampp\mysql\bin
mysqldump -u root -p --routines --triggers lake_shore_id_system > C:\backup\lsc_ids_%DATE:~10,4%%DATE:~4,2%%DATE:~7,2%.sql
```

### Files

Copy the whole `backend/uploads` folder to the backup location, keeping the
internal structure.

### Suggested schedule

| Frequency | Action |
|---|---|
| Daily | Database export + `uploads` copy to a second drive |
| Weekly | Copy the backup off the machine (network share or USB) |
| Before any update | Full backup — always |
| Monthly | Test a restore |

Keep at least **one backup off the server**. A backup on the same disk as the
database is not a backup.

## 9.3 Restore procedure

1. Start Apache and MySQL.
2. Create the database if it no longer exists:
   `CREATE DATABASE lake_shore_id_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Import the `.sql` file in phpMyAdmin.
4. Stop Apache, replace `backend/uploads/` with the backed-up folder, restart
   Apache.
5. Log in and confirm the record count on the dashboard matches the backup note.

## 9.4 Routine maintenance

| Task | Frequency | How |
|---|---|---|
| Clear the lost-ID inbox | Daily | Approve or delete each request |
| Review `created` records | Daily | Records view, filter by status |
| Prune archived photos | Monthly | Photo Library -> Archive view -> delete old entries |
| Review audit log | Weekly | Admin -> Audit; look for failed logins and unusual activity |
| Review user accounts | Termly | Admin -> Users; disable leavers |
| `npm outdated` / update | Quarterly | `npm update` then `npm run build` and re-test |
| Verify the backup | Monthly | Restore into a scratch database |

## 9.5 Housekeeping that runs itself

Expired rate-limit rows are removed automatically: one in
`RATE_LIMIT_GC_PROBABILITY` requests (default 1 in 10) also deletes expired
counter, grant and captcha rows. No cron job is required.

Password reset tokens expire on their own after 30 minutes and cannot be reused.

## 9.6 Monitoring what matters

| Signal | Where to look | Action |
|---|---|---|
| Portal unavailable | Apache / MySQL running? | Start them in the XAMPP panel |
| Portal returning 429 constantly | Audit log, action `rate_limited` | Raise `RATE_LIMIT_MAX_ATTEMPTS` for a shared network |
| Repeated failed logins | Audit log, `failed_login` | Consider disabling the account; review for guessing |
| Uploads failing | `upload_max_filesize` / `post_max_size` in `php.ini` | Raise above 10 MB |
| Database errors | PHP error log | Check disk space and MySQL connectivity |
| Slow listing | Large `id_cards` table | Confirm indexes exist; use filters |

Useful checks:

```cmd
:: Read-only database integrity check
php backend\tests\qa_db_check.php
```

It reports row counts per table, duplicate identifier groups, orphan records and
orphan upload files.

## 9.7 Updating the system

1. Back up (see 9.2).
2. Stop Apache.
3. Replace the project files, keeping `backend/uploads/` and `backend/config.php`.
4. Start Apache, import `database/id_system.sql` (guarded inserts — nothing is
   overwritten) and any newer files in `database/migrations/` in date order.
5. `cd frontend && npm install && npm run build` (or `npm run dev` for a test
   run).
6. Restart Apache and run the QA suites (see [10 — Testing](10-testing.md)).

## 9.8 Capacity and limits

| Limit | Value | Where set |
|---|---|---|
| Public requests | 5 per 10 minutes per IP per action | `RATE_LIMIT_MAX_ATTEMPTS` |
| Upload size | 10 MB | `uploadImage()` / `photoLibraryValidate()` |
| Upload dimensions | 96 px min, 12,000 px max | `photoLibraryValidate()` |
| Import rows | 5,000 per file | `IMPORT_MAX_ROWS` |
| Import file size | 10 MB | `IMPORT_MAX_FILE_BYTES` |
| Release notes | 1,000 characters | `releaseCard` |
| Reprint reason | 255 characters | `printCard` / `bulkPrint` |
| Search input | 4 characters minimum | `normalizeStudentNumber()` |

## 9.9 Environment notes

* **Development:** `npm run dev` gives hot reload on port 5173.
* **Production:** `npm run build` outputs static files to `frontend/dist`.
* **Windows + XAMPP:** Apache must be stopped before replacing files, or a
  cached PHP file may keep running.
* **File permissions:** `backend/uploads/` must be writable by the Apache user.

---

*Next: [10 — Testing and QA](10-testing.md)*
