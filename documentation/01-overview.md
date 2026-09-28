# 01 — System Overview

## 1.1 What the system is

The Lake Shore Colleges ID Management System is a self-hosted web application
that turns student data into a finished, printable school ID card. It covers the
complete lifecycle of an ID: a student submits their own details through a public
portal, staff verify and approve the record, the card is rendered against the
official design, printed on CR80 stock, and finally released to the student with
a full audit trail behind every action.

The system replaces the previous paper-and-spreadsheet process, where ID data was
retyped by hand into a card design, printouts were produced in small batches, and
there was no reliable record of who printed which ID or when.

## 1.2 Goals

| Goal | How the system meets it |
|---|---|
| Remove retyping | Students enter their own details once; staff reuse them on every reprint |
| Guarantee design fidelity | The official artwork is uploaded once as a template with live data zones |
| Prevent duplicate IDs | Server-side duplicate detection on name + student number / ID number / LRN |
| Protect student data | Role-based access, CSRF, validated uploads, rate limiting, audit logging |
| Make IDs traceable | Every create, edit, status change, print and release is logged |
| Stay maintainable | Plain PHP + MySQL + React on XAMPP — easy to host, back up and update |

## 1.3 The three departments

The system treats each department as a distinct *ID type*, and each type has its
own required fields and grade colour band.

### College (`COLLEGE`)

* A **course** is required, chosen from a fixed allow-list of 8 programmes.
* Grade level and LRN are forcibly cleared — they do not apply.
* Stores the academic year (e.g. `2026-2027`).

| # | Programme (stored and displayed in ALL CAPS) |
|---|---|
| 1 | BACHELOR OF SCIENCE IN PSYCHOLOGY |
| 2 | BACHELOR OF SPECIAL NEEDS EDUCATION |
| 3 | BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION |
| 4 | BACHELOR OF SCIENCE IN ACCOUNTANCY |
| 5 | BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT |
| 6 | BACHELOR OF SCIENCE IN TOURISM MANAGEMENT |
| 7 | BACHELOR OF SCIENCE IN MANAGEMENT ACCOUNTING |
| 8 | BACHELOR OF SCIENCE IN CRIMINOLOGY |

> The importer (`backend/import.php`) carries a wider catalogue of 45 programmes
> for mapping spreadsheet values; anything outside the enforced allow-list is
> rejected by the shared validator.

### Junior High School (`JUNIOR_HIGH`)

* A **grade level** is required, restricted to `GRADE 7` – `GRADE 10`.
* Course is cleared.
* Section, school year, ID number and LRN are captured.

| Grade | Name band colour |
|---|---|
| Grade 7 | Green |
| Grade 8 | Yellow |
| Grade 9 | Blue |
| Grade 10 | Red |

### Senior High School (`SENIOR_HIGH`)

* A **grade level** is required, restricted to `GRADE 11` – `GRADE 12`.
* Course is cleared.
* Section, school year, ID number and LRN are captured.

| Grade | Name band colour |
|---|---|
| Grade 11 | Purple |
| Grade 12 | Orange |

> **LRN rule** — for Basic Education only, the LRN is optional but, when
> supplied, must be exactly 12 digits. This rule is enforced by the shared
> server-side validator `normalizeCardByType()`, which both the public form and
> the staff form call.

## 1.4 Feature list

### Public student portal (no login)

* Create an ID request for College, Junior High or Senior High.
* Upload a photo (PNG / JPG / WEBP) with server-side validation.
* Live front-and-back preview while typing.
* **Existing student detection** — search by student number, ID number or LRN to
  re-fill the form instead of retyping.
* Duplicate-safe submission: a student who finds an existing record is offered
  "Report Lost ID" rather than creating a second card.
* Report a lost ID and request a reprint, with a receipt image upload.
* Rate limited and bot-protected, so the portal cannot be flooded or automated.

### Staff dashboard

* Dashboard with quick-create for all three departments, saved-record counts and
  analytics (totals, per-department breakdown, monthly generation and printing
  trends, lost-ID and reprint counts, all filterable by date, type and status).
* Full ID editor driven by the active template for the chosen department.
* Searchable records table with status, date and one-click actions.
* Status lifecycle: `created → done → edited → printed → released`.
* Preview, PNG/JPG export per side, and direct Smart ID 51 dual-side printing.
* Bulk printing with a per-card print type and reason.
* Lost-ID request inbox with receipt preview.
* Photo library: upload, search, clone, set preferred, archive, restore, replace
  and delete student photos.
* Student import from CSV / XLSX / XLS with a validation preview.

### Admin-only

* User management — create, edit, enable and disable staff and admin accounts.
* ID template designer — upload the exact design, place and resize live data
  zones, and activate one template per department.
* Template versioning — publish a new version without disturbing IDs already
  generated with the previous one.
* Signatory management — names, positions and signature images.
* Audit log viewer — filter by action, user, entity and date range; see field-level
  before/after diffs, IP address and user agent.
* Print history viewer — every print job, original or reprint, with reason.

## 1.5 The ID lifecycle

| Status | Meaning | How it is set |
|---|---|---|
| `created` | New request received from the portal, or created by staff | Automatic on insert |
| `done` | Details confirmed and approved by staff | "Mark as Done" |
| `edited` | A completed card was changed and needs re-review | Automatic on edit of a done card |
| `printed` | Sent to the printer | Print action (increments `print_count`, stamps `printed_at`) |
| `released` | Handed to the student | Release action (records who, when, notes, and whether the student received it) |

`released` is **terminal** — a released ID can no longer change status and cannot
be printed again. This is enforced server-side and returns HTTP `409`.

## 1.6 Smart ID export

The source design uses a portrait proportion of **642 × 1013 px**. Preview and
export use the same proportion rendered at 3× resolution, which is suitable as a
transfer image for Smart ID 51 software. The final print size is configured in
Smart ID itself (commonly CR80, 54 × 85.6 mm portrait).

## 1.7 Who uses the system

| Persona | Access | Main tasks |
|---|---|---|
| **Student** | Public portal, no login | Create ID, search existing record, report lost ID |
| **Staff** | Login (role `staff`) | Create and edit IDs, review requests, print, photo library, import |
| **Admin** | Login (role `admin`) | Everything staff can do, plus users, templates, signatories, audit log, print history |

## 1.8 Key numbers

| Metric | Value |
|---|---|
| Departments | 3 |
| ID sides | 2 (front + back) |
| College programmes (allow-list) | 8 |
| Grade levels | 6 (Grades 7–12) |
| Statuses | 5 |
| API actions | 55 |
| Database tables | 10 |
| Automated QA cases | 271 |
| Default public rate limit | 5 requests / 10 minutes / IP |

---

*Next: [02 — Architecture](02-architecture.md)*
