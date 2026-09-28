# 03 — Database Reference

Database name: **`lake_shore_id_system`**
Engine: InnoDB · Character set: `utf8mb4` / `utf8mb4_unicode_ci`

The authoritative schema is `database/id_system.sql`. The dated files in
`database/migrations/` are the incremental upgrades applied to existing installs.

## 3.1 Table overview

| Table | Rows in a new install | Purpose |
|---|---|---|
| `id_cards` | 0 | Every student ID record — the core table |
| `users` | 1 (seeded admin) | Staff and admin accounts |
| `signatories` | 1 (seeded) | Authorized signatories and their signature images |
| `system_settings` | 5 (seeded) | Institution name, address and contact details |
| `password_resets` | 0 | Hashed, expiring, single-use password reset tokens |
| `audit_logs` | 0 | Immutable trail of every important action |
| `print_history` | 0 | One row per print job (original or reprint) |
| `id_templates` | 3 (seeded) | ID designs with data zones, versioned and department-bound |
| `student_photos` | 0 | Every photo variant held for a student |
| `rate_limit_counters` | 0 | Per-bucket, per-IP, per-window hit counters |
| `rate_limit_grants` | 0 | Extra attempts granted by a solved captcha |
| `rate_limit_captchas` | 0 | Issued captcha challenges and their state |

## 3.2 `id_cards` — the core table

### Identity and department

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `student_name` | `VARCHAR(150)` | Rendered in uppercase on the card |
| `id_type` | `ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH')` | Department |
| `course` | `VARCHAR(255)` | College only; cleared for Basic Education |
| `grade_level` | `VARCHAR(20)` | Basic Education only; cleared for College |
| `section_name` | `VARCHAR(100)` | Basic Education |
| `student_number` | `VARCHAR(100)` | Searchable identifier |
| `student_id_number` | `VARCHAR(100)` | Searchable identifier |
| `lrn` | `VARCHAR(100)` | Basic Education; exactly 12 digits when present |
| `academic_year` | `VARCHAR(50)` | College, e.g. `2026-2027` |
| `school_year` | `VARCHAR(50)` | Basic Education |

### Photo handling

| Column | Type | Notes |
|---|---|---|
| `photo_path` | `VARCHAR(255)` | The photo currently used on the card |
| `original_photo_path` | `VARCHAR(255)` | The unprocessed upload |
| `photo_processing_status` | `VARCHAR(32)` | Default `original` |
| `photo_processed_at` | `DATETIME NULL` | When processing finished |
| `photo_crop` | `VARCHAR(255)` | Crop data |
| `background_mode` | `ENUM('transparent','white','custom')` | Cut-out background |
| `background_color` | `VARCHAR(16)` | Default `#FFFFFF` |
| `preferred_photo_id` | `INT UNSIGNED NULL` | Photo Library "preferred" flag |

### Back-of-card details

| Column | Type | Notes |
|---|---|---|
| `address_line1`, `address_line2` | `VARCHAR(255)` | Home address |
| `emergency_label` | `VARCHAR(255)` | Default *"In case of emergency, please notify"* |
| `emergency_contact` | `VARCHAR(150)` | Contact name |
| `emergency_phone` | `VARCHAR(80)` | Contact number |
| `terms_title` | `VARCHAR(150)` | Default *"Terms and Conditions"* |
| `term_1`, `term_2`, `term_3` | `TEXT` | The three printed conditions |

### Institution and signatory

| Column | Type | Notes |
|---|---|---|
| `institution_name` | `VARCHAR(150)` | Default *Lake Shore Colleges* |
| `institution_address` | `VARCHAR(255)` | |
| `mobile_no`, `telephone_no` | `VARCHAR(150)` | |
| `email_address` | `VARCHAR(150)` | |
| `signatory_id` | `INT UNSIGNED NULL` | FK to `signatories.id` |
| `signatory_name` | `VARCHAR(150)` | Denormalised for display |
| `signature_path` | `VARCHAR(255)` | Copied signature image path |

### Lifecycle, template and audit fields

| Column | Type | Notes |
|---|---|---|
| `template_id` | `INT UNSIGNED NULL` | The **version** row the card was generated with |
| `template_version` | `INT UNSIGNED NULL` | Pinned version number, so reprints reuse the same design |
| `status` | `ENUM('created','done','edited','printed','released')` | Lifecycle state |
| `printed_at` | `DATETIME NULL` | Last print timestamp |
| `print_count` | `INT UNSIGNED` | Incremented on every print |
| `released_by` | `INT UNSIGNED NULL` | FK to `users.id` |
| `released_at` | `DATETIME NULL` | |
| `release_notes` | `TEXT NULL` | Max 1000 characters |
| `student_received` | `TINYINT(1)` | Ticked when the student physically received the card |
| `created_at`, `updated_at` | `TIMESTAMP` | Automatic |

Index: `idx_card_tpl_version (template_id, template_version)`.

> **Template pinning** — the version an ID was generated with is stored on the
> card. Activating a newer template version therefore never changes the design of
> cards that were already generated; a reprint keeps reproducing the original.

## 3.3 `users`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `username` | `VARCHAR(150) UNIQUE` | Login name; matched **case-sensitively** (BINARY) |
| `password` | `VARCHAR(255)` | bcrypt hash |
| `full_name` | `VARCHAR(150)` | Shown in the app bar |
| `role` | `ENUM('admin','staff')` | Drives all admin-only gates |
| `is_active` | `TINYINT(1)` | 0 disables the account; it cannot log in |
| `created_at`, `updated_at` | `TIMESTAMP` | |

Seeded admin: `mbautista@lakeshore.edu.ph` / Ma. Bautista / role `admin`.

## 3.4 `signatories`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `full_name` | `VARCHAR(150)` | Printed under the signature |
| `position_title` | `VARCHAR(150)` | e.g. *Authorized Signatory* |
| `signature_path` | `VARCHAR(255)` | Image under `uploads/signatures/` |
| `is_active` | `TINYINT(1)` | Inactive signatories are hidden |

Default department signatories used by quick-create:

| Department | Default signatory |
|---|---|
| College | Sherill S. Villaluz |
| Junior High | Annabelle V. Molina |
| Senior High | Annabelle V. Molina |

## 3.5 `system_settings`

Simple key/value table (`setting_key` PK, `setting_value` TEXT) read through
`?action=settings`. Seeded keys: `institution_name`, `institution_address`,
`mobile_no`, `telephone_no`, `email_address`. The rate-limit HMAC secret is
stored here too, auto-generated the first time it is needed.

## 3.6 `password_resets`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `user_id` | `INT UNSIGNED` | |
| `token_hash` | `CHAR(64)` | SHA-256 of the token; indexed |
| `expires_at` | `DATETIME` | 30 minutes |
| `used` | `TINYINT(1)` | Single use |

The raw token is never stored and never returned by the API — delivery is
out-of-band.

## 3.7 `audit_logs`

Append-only. There is **no update or delete route** for audit rows.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `user_id` | `INT UNSIGNED NULL` | `NULL` for public / pre-auth actions |
| `action` | `VARCHAR(64)` | e.g. `card_updated`, `rate_limited` |
| `entity_type` | `VARCHAR(64)` | e.g. `id_card`, `user`, `template` |
| `entity_id` | `INT UNSIGNED NULL` | |
| `old_value` | `LONGTEXT NULL` | JSON — the pre-action snapshot |
| `new_value` | `LONGTEXT NULL` | JSON — the post-action state or field diff |
| `ip_address` | `VARCHAR(45)` | Fits IPv6 |
| `user_agent` | `VARCHAR(255)` | |
| `created_at` | `TIMESTAMP` | |

Indexes: `created_at`, `user_id`, `action`, `(entity_type, entity_id)` — one for
every UI filter.

## 3.8 `print_history`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `card_id` | `INT UNSIGNED` | Indexed |
| `user_id` | `INT UNSIGNED NULL` | Who printed |
| `print_type` | `ENUM('original','reprint')` | First print vs. reprint |
| `reason` | `VARCHAR(255)` | Required for reprints (Damaged / Lost / Incorrect Information / Other) |
| `printed_at` | `DATETIME` | Indexed |
| `created_at` | `TIMESTAMP` | |

Indexes: `card_id`, `user_id`, `print_type`, `printed_at`.

## 3.9 `id_templates`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `name` | `VARCHAR(150)` | |
| `version` | `INT UNSIGNED` | 1, 2, 3 … within a family |
| `parent_id` | `INT UNSIGNED NULL` | `NULL` for the family root |
| `id_type` | `ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH')` | Department |
| `front_image`, `back_image` | `VARCHAR(255)` | Artwork paths |
| `fields_json` | `LONGTEXT` | The data zones (JSON array) |
| `photo_processing_mode` | `ENUM('ORIGINAL','TRANSPARENT')` | Per-template photo handling |
| `is_active` | `TINYINT(1)` | Only one active template per department |
| `is_system` | `TINYINT(1)` | Protected built-in templates |
| `active_dept` | `VARCHAR(20)` | **Generated** column: `id_type` when active, else `NULL` |

Keys:

| Key | Type | Purpose |
|---|---|---|
| `uq_tpl_family_version` | `UNIQUE (parent_id, version)` | One row per version in a family |
| `uq_tpl_active_dept` | `UNIQUE (active_dept)` | **Database-enforced** single active template per department |
| `idx_tpl_parent` | index | Family lookups |
| `idx_id_type` | index | Department filtering |

Three protected templates are seeded — *Original LSC Design* for each of College,
Senior High and Junior High. They cannot be deleted or renamed.

The `active_dept` generated column is a strong design point: the "only one active
template per department" rule is enforced by the database, not only by the UI, so
two concurrent activations cannot both succeed.

## 3.10 `student_photos`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `student_id` | `INT UNSIGNED` | FK to `id_cards.id` |
| `source` | `ENUM('CREATE_ID','PHOTO_PROCESSING')` | How the photo entered the system |
| `type` | `ENUM('ORIGINAL','PROCESSED','ARCHIVED','THUMBNAIL')` | Version type |
| `parent_photo_id` | `INT UNSIGNED NULL` | Self-FK: the photo this was derived from |
| `file_path` | `VARCHAR(500)` | Relative storage path |
| `mime_type` | `VARCHAR(32)` | |
| `file_size` | `INT UNSIGNED` | Bytes |
| `width`, `height` | `INT UNSIGNED` | Pixels |
| `background_info` | JSON | Mode and colour |
| `crop_data` | JSON | Crop rectangle |
| `processing_status` | `ENUM('completed','queued','processing','needs_review','failed')` | |
| `quality_score` | `TINYINT UNSIGNED NULL` | 0–100 automated score |
| `is_active` | `TINYINT(1)` | 1 = selectable, 0 = archived |
| `created_by` | `INT UNSIGNED NULL` | `NULL` for public uploads |

Preferred-photo selection prefers, in order: a `PROCESSED` photo from
`PHOTO_PROCESSING`, any other `PROCESSED` photo, a `CREATE_ID` photo, then
anything else — newest first within each tier.

## 3.11 Rate-limit tables

Created automatically on first use, or importable from
`database/migrations/2026_09_28_create_rate_limits.sql`.

| Table | Key columns | Purpose |
|---|---|---|
| `rate_limit_counters` | `bucket`, `ip_address`, `window_start`, `hits` | One row per (bucket, IP, window) |
| `rate_limit_grants` | `bucket`, `ip_address`, `window_start`, `extra_attempts` | Attempts unlocked by a solved captcha |
| `rate_limit_captchas` | `ip_address`, `bucket`, `attempts`, `used_at`, `expires_at` | Issued challenges |

Counters are bumped with a single atomic statement —
`INSERT ... ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits+1)` — so parallel
requests from one IP can never race past the limit, and the new value is read
back through `PDO::lastInsertId()` without an extra round trip.

## 3.12 Relationships

```
users 1 ──< id_cards (created_by is implicit; released_by FK)
users 1 ──< password_resets
users 1 ──< audit_logs
users 1 ──< print_history

id_cards 1 ──< student_photos        (student_id)
id_cards 1 ──< print_history         (card_id)
id_cards >── 1 id_templates          (template_id = a specific VERSION row)
id_cards >── 1 signatories           (signatory_id)
id_templates >── 1 id_templates      (parent_id = version family)

signatories 1 ──< id_cards            (denormalised name + signature_path)
system_settings  ── key/value, no FK
rate_limit_*  ── standalone, keyed by (bucket, ip, window)
```

> **Referential integrity note** — the schema does not declare foreign key
> constraints; uniqueness and referential integrity are enforced in the
> application layer. The QA database check verifies zero orphan rows and zero
> duplicate identifier groups after every test run.

## 3.13 Seed data

`database/id_system.sql` inserts, only if absent:

| Table | Seeded content |
|---|---|
| `users` | `mbautista@lakeshore.edu.ph` — Ma. Bautista, role `admin` |
| `system_settings` | Institution name, address, mobile, telephone, e-mail |
| `signatories` | Annabelle V. Molina, Authorized Signatory |
| `id_templates` | 3 protected *Original LSC Design* templates (one per department) |

Default academic / school year in the data: **`2026-2027`**.

> **First-login duty** — change the seeded admin password immediately after the
> first successful login, and create individual named accounts for each staff
> member instead of sharing one login.

## 3.14 Migrations

| File | Purpose |
|---|---|
| `migrate_existing.sql` | Brings a previous version up to date |
| `migrate_lost_id.sql` | Lost-ID request table |
| `migrate_print_status.sql` | Printed status, `printed_at`, `print_count` |
| `migrate_templates.sql` | `id_templates` + the 3 system templates |
| `migrate_users_resets.sql` | `users` and `password_resets` |
| `migrations/2026_09_05_add_release_fields.sql` | `released` status and release columns |
| `migrations/2026_09_05_create_audit_logs.sql` | `audit_logs` |
| `migrations/2026_09_07_create_print_history.sql` | `print_history` |
| `migrations/2026_09_08_photo_processing.sql` | Photo processing columns on `id_cards` |
| `migrations/2026_09_10_photo_background.sql` | Background mode and colour |
| `migrations/2026_09_14_create_student_photos.sql` | `student_photos` |
| `migrations/2026_09_28_create_rate_limits.sql` | The three rate-limit tables |
| `migrations/2026_09_28_template_versioning.sql` | Version family columns and keys |

---

*Next: [04 — API Reference](04-api-reference.md)*
