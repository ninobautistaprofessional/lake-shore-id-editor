# Lake Shore Colleges — ID Management System

## Complete System Documentation

Version **2.0** · Last updated **28 September 2026**

This folder contains the full technical and user documentation for the Lake Shore
Colleges ID Management System — the web application that captures student data,
designs and generates official school ID cards, tracks them from creation to
release, and prints them through Smart ID 51 / CR80 hardware.

---

## Document index

| # | Document | Audience | Purpose |
|---|---|---|---|
| 01 | [Overview](01-overview.md) | Everyone | What the system does, who uses it, feature list |
| 02 | [Architecture](02-architecture.md) | Developers | Layers, technology stack, folder map, request flow |
| 03 | [Database](03-database.md) | Developers / DBAs | Every table, column group, indexes, relationships |
| 04 | [API Reference](04-api-reference.md) | Developers | All `action=` endpoints, methods, auth level, responses |
| 05 | [Installation](05-installation.md) | IT / Installer | Fresh install, updates, production build, configuration |
| 06 | [Student Guide](06-student-guide.md) | Students | Public portal: create ID, search, report lost ID |
| 07 | [Staff & Admin Guide](07-staff-admin-guide.md) | Staff / Admin | Dashboard, editor, records, printing, templates, users |
| 08 | [Security](08-security.md) | IT / Auditors | Auth, CSRF, uploads, rate limiting, audit logging |
| 09 | [Operations](09-operations.md) | IT | Backup, restore, maintenance schedule, monitoring |
| 10 | [Testing & QA](10-testing.md) | Developers / QA | Test suites, how to run them, current results |
| 11 | [Troubleshooting](11-troubleshooting.md) | Everyone | Common problems and fixes |

---

## Quick start (short version)

```
1. Copy the project to  C:\xampp\htdocs\lake-shore-id-editor
2. Start Apache and MySQL in the XAMPP control panel
3. Open http://localhost/phpmyadmin  ->  Import  database/id_system.sql
4. Check the credentials in  backend/config.php
5. Open a command prompt:
       cd C:\xampp\htdocs\lake-shore-id-editor\frontend
       npm install
       npm run dev
6. Open the Vite address (normally http://localhost:5173)
```

Full detail is in [05 — Installation](05-installation.md).

---

## System identity

| Item | Value |
|---|---|
| System name | Lake Shore Colleges ID Management System |
| Database | `lake_shore_id_system` (MySQL / MariaDB, `utf8mb4`) |
| Backend entry point | `backend/api.php?action=<name>` |
| Frontend | React 18 + Vite 5 (`frontend/`) |
| Runtime | XAMPP — Apache + MySQL + PHP |
| Card output | Smart ID 51 / CR80 (54 × 85.6 mm portrait) |
| Card source design | 642 × 1013 px portrait |
| Departments supported | College, Junior High School, Senior High School |
| Default admin account | `mbautista@lakeshore.edu.ph` (Ma. Bautista) |

---

## Source-code map

```
lake-shore-id-editor/
|-- database.sql                  Legacy first-install schema
|-- database/
|   |-- id_system.sql             Authoritative schema + seed data
|   |-- migrate_*.sql             Step-by-step upgrades for existing installs
|   `-- migrations/               Dated, one-purpose migrations
|-- backend/
|   |-- api.php                   All API actions (single entry point)
|   |-- auth.php.php              Session, login helpers, CSRF
|   |-- config.php                DB credentials + rate-limit settings
|   |-- audit.php                 Audit-log helper and field diffs
|   |-- rate_limit.php            Public-portal throttling engine
|   |-- import.php                CSV / XLSX / XLS student import
|   |-- photos.php                Student photo storage and library helpers
|   |-- .htaccess                 No indexing; deny .sql/.log/.ini/.env
|   |-- uploads/                  photos, receipts, signatures, templates
|   `-- tests/                    QA suites (see 10-testing.md)
|-- frontend/
|   |-- src/main.jsx              React application
|   |-- src/styles.css            Application stylesheet
|   |-- public/                   logo and static assets
|   `-- .env                      VITE_API_URL
`-- presentation/                 PowerPoint generator and output
```

---

*Next: [01 — Overview](01-overview.md)*
