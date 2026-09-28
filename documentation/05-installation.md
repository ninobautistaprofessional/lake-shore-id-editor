# 05 — Installation and Configuration

## 5.1 Requirements

| Requirement | Version | Notes |
|---|---|---|
| XAMPP | 8.x or newer | Bundles Apache, MySQL/MariaDB and PHP |
| PHP | 8.0 or newer | Developed and tested on 8.5 |
| MySQL / MariaDB | 5.7+ / 10.4+ | Must support `ON DUPLICATE KEY UPDATE` and generated columns |
| Node.js | 18 LTS or newer | For Vite |
| npm | 9 or newer | Ships with Node.js |
| A browser | Any current Chrome, Edge or Firefox | |

No Composer, no global npm packages, and no internet access is required after the
initial `npm install`.

## 5.2 Fresh installation

### Step 1 — Place the project

Copy the folder to:

```
C:\xampp\htdocs\lake-shore-id-editor
```

The path matters: `frontend/.env` and the default Vite proxy assume this layout.

### Step 2 — Start the servers

Open the **XAMPP Control Panel** and start **Apache** and **MySQL`.

### Step 3 — Import the database

1. Open `http://localhost/phpmyadmin`.
2. Click the **Import** tab.
3. Choose `database/id_system.sql`.
4. Leave the character set as `utf-8` and press **Go**.

This creates the `lake_shore_id_system` database, all tables and the seed data
(admin account, institution settings, signatory, and the three system templates).

### Step 4 — Check the credentials

Open `backend/config.php` and confirm:

| Constant | Default |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_NAME` | `lake_shore_id_system` |
| `DB_USER` | `root` |
| `DB_PASS` | *(empty by default on XAMPP)* |

### Step 5 — Verify the backend

Browse to:

```
http://localhost/lake-shore-id-editor/backend/api.php?action=cards
```

A JSON response is expected (for a signed-out browser it will be a
`401 Unauthorized` JSON message — that still proves PHP, PDO and the database
connection are working).

### Step 6 — Run the frontend

Open a **Command Prompt**:

```cmd
cd C:\xampp\htdocs\lake-shore-id-editor\frontend
npm install
npm run dev
```

### Step 7 — Open the application

Open the address Vite prints, normally:

```
http://localhost:5173
```

### Step 8 — Secure the first account

Log in with the seeded administrator, **change the password immediately**, then
create a separate named account for each staff member. Never share one login.

## 5.3 Updating an existing installation

To keep existing records when moving to a newer version:

1. **Back up first** — export the database and copy `backend/uploads/`.
2. Replace the project files with the new version.
3. In phpMyAdmin, import `database/migrate_existing.sql`.
4. Import `database/id_system.sql` if you need to ensure the signatories and
   default settings exist (its inserts are guarded, so nothing is overwritten).
5. Import any newer files from `database/migrations/` in date order.
6. Run `npm install` again inside `frontend`.
7. Run `npm run dev`.

## 5.4 Production build

For a real deployment, build the React app and serve the static output:

```cmd
cd frontend
npm install
npm run build
```

Then deploy the contents of `frontend/dist` to your web server. If the API lives
at a different address, set `VITE_API_URL` **before** building:

```
VITE_API_URL=https://ids.example.edu.ph/backend/api.php
```

`vite.config.js` uses `base: "./"`, so the build works under any sub-path (for
example `/lake-shore-id-editor/`) without further configuration.

### Apache notes

* `backend/.htaccess` disables directory indexing and denies `.sql`, `.log`,
  `.ini` and `.env` files. Confirm `mod_rewrite` and `AllowOverride All` are
  enabled, or copy those rules into the Apache config.
* Each `backend/uploads/*` folder has its own `Options -Indexes`.
* `frontend/public/.htaccess` registers the MIME types for `.wasm` and
  `.tflite`, needed by the self-hosted photo-segmentation model.
* For anything beyond a private school LAN, serve over **HTTPS**. The session
  cookie automatically sets `Secure` when HTTPS is detected.

## 5.5 Configuration reference

`backend/config.php` holds the database settings and every rate-limit constant.

| Constant | Default | Purpose |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_NAME` | `lake_shore_id_system` | Schema name |
| `DB_USER` | `root` | MySQL user |
| `DB_PASS` | *(empty)* | MySQL password |
| `RATE_LIMIT_ENABLED` | `true` | Master switch for public throttling |
| `RATE_LIMIT_MAX_ATTEMPTS` | `5` | Requests per window per IP per action |
| `RATE_LIMIT_WINDOW_SECONDS` | `600` | Window length (10 minutes) |
| `RATE_LIMIT_HONEYPOT_FIELD` | `'website'` | Set to `''` to disable the honeypot |
| `RATE_LIMIT_CAPTCHA_ENABLED` | `true` | Offer the human check |
| `RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS` | `5` | Extra attempts after a solved captcha |
| `RATE_LIMIT_CAPTCHA_ISSUE_LIMIT` | `10` | Challenges one IP may request per window |
| `RATE_LIMIT_CAPTCHA_TTL` | `300` | Challenge lifetime in seconds |
| `RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS` | `4` | Wrong answers allowed per challenge |
| `RATE_LIMIT_SECRET` | `''` | Captcha HMAC secret — auto-generated into `system_settings` when empty |
| `RATE_LIMIT_TRUSTED_PROXY_IPS` | `[]` | Proxies allowed to send `X-Forwarded-For` |
| `RATE_LIMIT_GC_PROBABILITY` | `10` | 1 in N requests also deletes expired rows |

### Tuning for a shared network

A whole school can sit behind a single public IP address. If legitimate students
start seeing *"Too many submissions from your network"*, raise
`RATE_LIMIT_MAX_ATTEMPTS` (for example to `20` for a lab of 25 students) rather
than disabling the limiter. The captcha unlock already provides a per-student
escape hatch for a single blocked address.

### Behind a reverse proxy or load balancer

List the proxy addresses in `RATE_LIMIT_TRUSTED_PROXY_IPS`. Otherwise the real
client address stays `REMOTE_ADDR`, which is the safe default — trusting
`X-Forwarded-For` unconditionally would let anyone reset the limiter by forging
the header on every request.

## 5.6 Access points

| What | Where |
|---|---|
| Student portal (public) | `http://localhost:5173` |
| Staff and admin login | `http://localhost:5173` |
| Backend API | `http://localhost/lake-shore-id-editor/backend/api.php` |
| phpMyAdmin | `http://localhost/phpmyadmin` |
| Default admin account | `mbautista@lakeshore.edu.ph` (Ma. Bautista) |

## 5.7 Important folders to preserve when copying or backing up

| Folder | Contents |
|---|---|
| `backend/uploads/students/` | Student photos (originals and processed cut-outs) |
| `backend/uploads/signatures/` | Signatory signature images |
| `backend/uploads/receipts/` | Lost-ID receipt uploads |
| `backend/uploads/templates/` | Uploaded template artwork |
| `frontend/public/lsc-logo.png` | The logo shown on the ID and in the app bar |
| `frontend/public/models/` | Self-hosted segmentation model (if present) |
| `frontend/public/mediapipe/` | Self-hosted MediaPipe WASM runtime (if present) |

> The database stores **relative** paths into `uploads/`, so the whole project
> folder can be moved as long as the relative layout is preserved.

---

*Next: [06 — Student Guide](06-student-guide.md)*
