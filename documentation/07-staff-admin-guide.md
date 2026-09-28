# 07 — Staff and Admin Guide

## 7.1 Signing in

Open the application and sign in with the account issued to you.

| Role | What you can do |
|---|---|
| `staff` | Create and edit IDs, review lost-ID requests, print, manage the photo library, import students, manage signatories and settings |
| `admin` | Everything staff can do, **plus** users, ID templates, audit log and print history |

If your account is disabled by an administrator, sign-in is refused. Contact your
administrator — do not ask a colleague to log in for you.

## 7.2 The dashboard

The dashboard is the starting point after login.

| Element | What it gives you |
|---|---|
| Quick create | Create a College, Junior High or Senior High ID in one click |
| Saved records | Count of all stored ID records |
| Summary | Totals for generated, printed and released |
| By department | College / JHS / SHS breakdown |
| Monthly trend | Generated vs. printed per month |
| Lost IDs and reprints | Counts of lost-ID requests and reprint jobs |
| Filters | Narrow every chart by date range, department and status |

## 7.3 Creating an ID record

### Quick create

Use **Quick Create** to register a student by name and identifier only, then add
photos afterwards. The default academic year (`2026-2027`) and the department's
default signatory are applied automatically. Section and LRN are cleared for
College students, since they are Basic Education fields.

### Full editor

1. Open the **ID Editor**.
2. Choose the department — the active template for that department loads
   automatically.
3. Fill the form. The same department rules the students see are enforced here
   too, server-side.
4. Upload or pick a photo from the Photo Library.
5. Watch the live front/back preview.
6. **Save**. The active template version is pinned to the record.

The editor refuses to save a second record for the same name plus student
number, ID number or LRN, and says so clearly.

## 7.4 Working with records

The **Records** view lists every ID with its status, department and dates.

* **Search** matches name, student number, student ID number, LRN, course, grade
  level and section.
* **Filter** by department, status and date.
* Open a record to view the full card, the photo, the signatory and the audit
  trail.

## 7.5 The status lifecycle

```
created  -->  done  -->  edited  -->  printed  -->  released
 (new)      (approved)  (changed)    (on card)     (handed over)
```

| From | Action | Result |
|---|---|---|
| `created` | Mark as Done | to `done` |
| `done` | Edit any field | to `edited` (needs re-review) |
| `done` / `edited` | Print | to `printed`, `print_count` +1, `printed_at` stamped, one `print_history` row written |
| `printed` | Release ID | to `released`, with released-by, date, notes and *student received* |
| `released` | Any status change or print | **Refused (409)** — released is final |

Reprints require a reason: **Damaged**, **Lost**, **Incorrect Information** or
**Other**. The first print of a card is recorded as `original`; every later one as
`reprint`.

## 7.6 Printing and export

| Method | How |
|---|---|
| PNG / JPG export | Export the front or the back separately from the editor |
| Direct print | One print job — page 1 the front in colour, page 2 the back, auto duplex |
| Bulk print | Select many records, set a print type and reason per card, then print in one job |

Source proportion is 642 × 1013 px, rendered at 3× for a crisp result. Set the
final size in Smart ID 51 (commonly CR80, 54 × 85.6 mm portrait).

## 7.7 Lost-ID requests

Open **Requests** (or the bell icon, which shows the pending count).

1. Review the request and its receipt image.
2. Check the details against the existing record.
3. Approve or update the request, then reprint the card.
4. The reprint is recorded in the print history with your name against it.

## 7.8 Photo Library

| Action | Use it for |
|---|---|
| Upload | Add a photo for a student |
| Search / Browse | Find an existing photo instead of asking for a new one |
| Clone | Copy a photo to another student (e.g. a sibling or a correction) |
| Set preferred | Choose which photo appears on the card |
| Replace | Swap the image while keeping the same record |
| Archive / Restore | Hide a photo without deleting it (soft delete) |
| Delete | Permanently remove a photo |

**Photo processing** removes the background from a photo to produce a clean
cut-out. The processed version is stored alongside the original, the background
mode (`transparent` / `white` / `custom`) is recorded, and the original is always
kept so the result can be redone.

Accepted formats: PNG, JPG and WEBP. Minimum 96 × 96 px, maximum 10 MB.

## 7.9 Importing students in bulk

For a new batch of students, use **Import** instead of creating records one by
one.

1. **Download the template** (`importTemplate`) — a blank CSV with the correct
   columns.
2. Fill it in, or export your existing roster to CSV / XLSX / XLS.
3. **Upload** the file. The system parses every sheet, works out the department
   from a `Department` column, the sheet name, or the course/grade present, and
   validates each row.
4. **Review the preview.** Every row is marked valid or invalid, with a reason.
   Duplicates are detected both inside the file and against the database.
5. **Commit.** Valid rows are inserted in a single transaction — if anything
   fails, nothing is written.

Limits: 5,000 rows and 10 MB per file. Photos are optional; a `Photo File` entry
that does not exist results in the row being imported without a photo and a
warning in the preview.

## 7.10 Admin: users

**Users -> Add user.**

| Field | Notes |
|---|---|
| Username | Must be unique; matched case-sensitively at login |
| Full name | Shown in the app bar |
| Role | `admin` or `staff` — choose the least privilege that does the job |
| Password | Stored only as a bcrypt hash |
| Active | Untick to disable the account immediately |

Prefer **disabling** an account over deleting it: it preserves the audit trail
showing who did what.

## 7.11 Admin: templates

**Templates** is where the official ID design lives.

1. **Upload the design** — the exact front (and optionally back) artwork as
   PNG/JPG. The system uses your artwork pixel for pixel.
2. **Place the data zones** — drag and resize boxes for photo, name, course,
   number and so on. Each zone is bound to one data field.
3. **Style each zone** — font, size, colour, uppercase transform, alignment and
   image fit (cover or contain).
4. **Activate** it. Only one template is active per department; the database
   enforces this.

### Versioning

Saving over an existing template creates **version n+1** rather than changing
version n. Because each card pins the version it was generated with, activating a
new design never alters cards that were already produced, and a reprint always
reproduces the original design. This is why a mid-year design change is safe.

The three seeded *Original LSC Design* templates are protected: they cannot be
deleted or renamed.

## 7.12 Admin: audit log

**Audit** records every important action: card creation, edits (with a field-level
before/after diff), status changes, deletion, printing, release, template and
signatory changes, user changes, logins, logouts, failed logins, password reset
requests, student lookups, rate-limited requests and rejected bot submissions.

* Filter by **action**, **user**, **date range**, or free-text **search**.
* Open a row to see FIELD / FROM / TO detail, the IP address and the user agent.
* The log is **read-only** — there is no way to edit or delete an entry, which is
  what makes it trustworthy.

## 7.13 Admin: print history

**Print History** lists every print job with the card, the user, the print type
(`original` / `reprint`), the reason, and the exact time. Use it to answer
"who printed this card, and when?".

## 7.14 Daily routine

1. Open **Requests** — clear the lost-ID inbox.
2. Open **Records**, filter by `created` — review new submissions.
3. Mark verified records **Done**.
4. Edit anything that needs correcting (it moves to `edited`).
5. Print the day's batch.
6. Release the cards to students.
7. Spot-check the **Audit** view at the end of the day.

---

*Next: [08 — Security](08-security.md)*
