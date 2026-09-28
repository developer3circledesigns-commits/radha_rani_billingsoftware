# Radha Rani Hotel — Bill Portal

Multi-branch bill PDF management portal for **Radha Rani Hotel**. Branches upload daily
cash/card bill PDFs; the owner reviews, downloads, and tracks upload activity from a single
dashboard.

Built with vanilla **PHP 8.2 + MySQL 8** (PDO, prepared statements) and a hand-crafted
**Bootstrap 5** frontend (no framework). No Composer packages required — `bootstrap.php`
autoloads the app's own classes.

---

## Quick start (Docker)

```bash
cd docker
docker compose up -d --build
```

Open **http://localhost:8091** and sign in.

| Service | Host port | Notes |
|---------|-----------|-------|
| Web (nginx → php-fpm) | `8091` | docroot is `public/` |
| MySQL 8 | `3308` | db `radha_rani`, user `radha` |

> The compose uses host port `8091` to avoid clashes with XAMPP's Apache on `8080`.
> Change `ports` in `docker/docker-compose.yml` if needed.

#### `no configuration file provided`

The compose file lives in `docker/`, and `docker compose` only looks in the current
directory — so running it from the repository root fails with that error. Either
`cd docker` first as above, or point at the file explicitly:

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Both forms resolve to the **same** compose project, because the project name comes
from the compose file's own directory. That matters: the project name namespaces the
volumes, so a command that changed it would come up with an empty database.

Do not add a `compose.yaml` to the repository root to "fix" this. A root file would
default to a different project name and then either fail with *name already in use*
or, worse, start a second database under a new volume.


#### Database passwords

The dev passwords (`radhapass` / `rootpass`) are fallback defaults inside
`docker/docker-compose.yml` and are **only** safe on a machine that is not
reachable from the internet. To use your own, copy the template and edit it:

```
cp docker/.env.example docker/.env
```

`docker/.env` is git-ignored, so real passwords are never committed. Compose
picks it up automatically - no extra flags.

### Initial owner account

| Role | Email |
|------|-------|
| Owner | `owner@radharani.local` |

The owner password is set via the seed; change it from **Profile** after first login. Branches and branch admins are created from the owner dashboard.

### Resetting the database

The schema + seed data load automatically on first `docker compose up` (MySQL init script,
`database/init.sql`). To start clean:

```bash
docker compose down -v        # -v also deletes the db volume
docker compose up -d          # re-runs init.sql
```

---

## Running without Docker (XAMPP / shared hosting)

1. Point your web root at the `public/` directory.
2. Create a MySQL database and import `database/init.sql`.
3. Create `storage/uploads` and `storage/logs` and make them writable.
4. Set environment variables (or edit defaults in `app/config/config.php`):

   | Env | Default | Purpose |
   |-----|---------|---------|
   | `DB_HOST` | `db` | MySQL host (`localhost` for XAMPP) |
   | `DB_PORT` | `3306` | MySQL port (XAMPP MySQL uses `3306`, our compose maps host `3308`→`3306`) |
   | `DB_NAME` | `radha_rani` | database name |
   | `DB_USER` / `DB_PASS` | `radha` / *(none)* | MySQL user - **on shared hosting put the real password in `app/config/config.local.php`, never in `config.php`** |
   | `APP_URL` | auto-detected | set to the full base URL when installed in a sub-directory |
   | `APP_ENV` | `development` | set `production` to suppress error display |
   | `MAX_FILE_SIZE` | `20971520` (20 MB) | hard server-side upload cap |

   `/.htaccess` (Apache) protects `storage/` and backend folders from direct access; Nginx
   (compose) only exposes `public/`.

---

## Features

- **Role-based access** — Owner and Branch Admin; owner-only pages server-side enforced
  (`403` for branch users, cross-branch bills return `403`).
- **Secure PDF uploads** — drag & drop (AJAX with progress), validated by extension +
  MIME (`finfo`) + `%PDF-` signature + size; stored outside the web root under
  `storage/uploads/branches/{CODE}/{cash|card}/{Y}/{m}/`.
- **View / download** — authorized inline `view.php`/`view_pdf.php` and `download.php`
  (byte-range aware), branch-scoped.
- **Owner dashboards** — KPI cards, per-branch daily upload status (Uploaded / Partial /
  No Upload), 7-day activity chart, full bill search/filter/pagination.
- **Daily bill-upload compliance** — the owner is alerted when a branch misses its
  Cash/Card bill after the daily deadline, with a notification bell, a dashboard banner
  and a per-day report. See [Daily bill-upload compliance](#daily-bill-upload-compliance).
- **Branch management** — CRUD, activate/inactivate, soft-delete branches.

- **Admin management** — CRUD branch admins, reset-password modal.
- **Audit trail** — every sign-in, failed login, upload, and admin action recorded
  (`audit_logs`), plus login throttling (5 tries / 15 min lockout).
- **Soft deletes** — bills and branches are marked deleted, never physically removed;
  no financial totals exposed, only document counts.
- **Bilingual UI (English / German)** — every page, form, flash message, and API
  response is translated. See [Languages](#languages) below.

---

## Daily bill-upload compliance

The owner can be told automatically when a branch has not sent a bill for a day. The
alert **also goes to that branch's admins**, in the same topbar bell, because they
are the only people who can upload the missing bill. The whole feature is in-app: a
notification bell badge, a dashboard banner, and a per-day report at
**Owner → Daily Compliance**. There is no email and no polling.

### What counts as a missing day

| Question | Answer |
| --- | --- |
| Which branches? | Only branches that are `active` and not deleted. |
| Which bills? | Bills whose **`uploaded_at`** falls in the day, not the `business_date` printed on them. |
| What is required? | Whatever is switched on in the settings: Cash, Card, or both. |
| When is it checked? | After the deadline for that day has passed. |

The `uploaded_at` rule is deliberate: the owner is asking *"did each branch send
something today"*, so a bill back-dated to last week satisfies today, and a bill
uploaded at 00:05 does not satisfy yesterday.

### Configuration (Owner → Settings)

| Setting | Meaning |
| --- | --- |
| `daily_upload_alert_enabled` | Master switch. `0` disables every check. || `daily_cash_required` / `daily_card_required` | Which bill types a day needs. Both off means nobody is ever alerted — and the UI says "Not required" rather than claiming the bills are uploaded. |
| `daily_upload_deadline_mode` | `time` for one deadline, `per_weekday` for per-weekday overrides. |
| `daily_upload_deadline_time` | The deadline, `HH:MM` in the portal timezone. |
| `daily_upload_deadline_weekdays` | JSON, keyed `0`–`6` (Sunday first). A blank day falls back to the global time. |
| `daily_upload_alert_start_date` / `..._end_date` | Optional window. Days outside it are never enforced. |

A day is **not** enforced before its deadline, so nothing is ever alerted early.
The timezone is `Asia/Kolkata` unless `APP_TIMEZONE` says otherwise, and the
database session is pinned to that same zone so upload timestamps and day
boundaries always agree — see [DEPLOYMENT.md](DEPLOYMENT.md) for how to confirm it.

### How an alert behaves

- One alert per branch, day, and *set of missing types*. A branch that still owes
  the Card bill gets a Card-scoped warning, not "missing both".
- It reaches **two** audiences, because they can act differently: the owner, who
  has to chase the branch, and the **admins of that branch**, who are the only
  people who can upload the missing bill. Both see it in the same topbar bell, and
  the branch admin's alert links straight to the upload form.
- An admin is only ever notified about **their own branch** — the fan-out is
  scoped by `branch_id` on the user, and read/mark-as-read is scoped by
  `user_id`, so there is no path for one branch's alert to reach another.
- Branch admins are told about **today only**. A backfill over past days would
  otherwise greet a newly created admin with alerts for days before they worked
  there, and they cannot act on those: compliance is judged on the upload
  timestamp, so a past day can never be satisfied after the fact. The owner keeps
  the full history.
- Uploading a bill settles the alert for **every** recipient, for the day it was
  **uploaded**. If the day is only partly complete, the stale alert is closed and
  a fresh one is raised for exactly what is still outstanding, so an incomplete
  day never goes quiet.
- Alerts are never deleted. `read_at` means the recipient looked at it;
  `resolved_at` means the condition stopped being true. Both are shown in the
  report.
- Alerts are per user. The stored dedupe key is namespaced `u{userId}:{event}`.

### Running the sweep

The owner dashboard runs a fallback sweep on load, so the feature works without a
cron. For unattended operation, schedule the CLI hourly after the deadline:

```
php tools/check-daily-uploads.php                 # today
php tools/check-daily-uploads.php --date=2026-03-10
php tools/check-daily-uploads.php --dry-run       # report, write nothing
```

It exits `1` when a day has a non-compliant branch, so a monitoring check can alert
on it. It is idempotent: a second run for an unchanged day creates nothing.

### When the check actually happens

**The deadline is a clock time, not an alarm.** Setting it to 13:00 does not by
itself run anything at 13:00. There are four triggers, and it is worth knowing
which one you are relying on:

| Trigger | When it fires |
| --- | --- |
| Owner page load | Whenever an owner opens the dashboard and today's check is due |
| Hourly cron | At the cron minute, once the deadline has passed — **up to an hour late** |
| **Run the check now** | Whenever the owner presses it on the compliance page |
| Page left open | An owner page arms a single timer and refreshes itself at the deadline |

So an alert can appear up to an hour late if you rely on the cron alone and nobody
is looking. The last two rows exist to remove that wait: the button runs the check
on demand, and a page left open updates itself when the deadline arrives. Before the
deadline the button says the check is not due yet rather than alerting early — a day
is never judged before its deadline.

While a check is still pending, the dashboard and the compliance page say when it is
due and how long is left, instead of showing nothing.

### Troubleshooting

| Symptom | Cause |
| --- | --- |
| No alerts, ever | `daily_upload_alert_enabled=0`, or both requirements off. The report shows a banner saying so, and the status column reads "Not required" rather than "Uploaded". |
| Alerts missing for a day | The day was not yet past its deadline, or fell outside the start/end window. |
| A branch is listed as missing despite uploading | The bill was uploaded after the window closed, or for a different day than you expect — check `uploaded_at`. |
| Every time is a few hours out, or uploads land on the wrong day | The database clock and `APP_TIMEZONE` disagree. Run `php tools/deploy-check.php`; it reports `database clock agrees with APP_TIMEZONE`. |
| A branch has bills but is still flagged | It owes a *different* type: one Cash bill does not satisfy "Cash **and** Card". |
| Alert count keeps growing per day | One alert per branch per day is expected; the report filters by day and state. |

---

## Languages


The portal ships with English (`en`) and German (`de`). The active language is chosen
per visitor and stored in the `rr_lang` cookie plus the session, so it survives a login
without a URL change.

**Resolution order:** `rr_lang` cookie → `session['lang']` → `Accept-Language` header
(only when `AUTO_DETECT_LANGUAGE=1`) → `DEFAULT_LOCALE`.

### Configuration

Both are read from the environment in `app/config/config.php`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `DEFAULT_LOCALE` | `en` | Fallback for missing keys and for visitors with no preference. |
| `AUTO_DETECT_LANGUAGE` | `1` | Set to `0` to ignore `Accept-Language` and always use the cookie, the session, or the default. |

```env
# .env / host environment
DEFAULT_LOCALE=en
AUTO_DETECT_LANGUAGE=1
```

`Lang::available()` lists the supported locales by reading `app/lang/`, so the sidebar
switcher and the language endpoint pick up new files without a code change.

### Adding a language

1. Copy `app/lang/en.php` to `app/lang/<code>.php` and translate the `strings` array.
   Keys and `:placeholders` must match English exactly.
2. Nothing else. The switcher, the `<html lang>` attribute, and the date/number
   formats pick it up on the next request.

### Checking the catalogues

```bash
php tools/check-i18n.php
```

This compares every locale against the English fallback and fails on missing or extra
keys, mismatched `:placeholders`, wrong plural shape, or empty values.

### Notes

- Translation lookup falls back active locale → English → the key itself, so a missing
  translation never blanks the page.
- Dates, times, byte sizes, and relative times are locale-formatted; month and day
  names come from the catalogue, not from `date()`, so no `intl` extension is needed.
- User content (branch names, filenames, descriptions) is never translated.
- Audit and security log entries stay in English on purpose: they are evidence, and
  translating their free text would make cross-locale searching unreliable.

---

## Structure

```
app/
  bootstrap.php          entry bootstrap: session, error handlers, class autoload
  config/config.php      env-driven configuration & path constants
  helpers/               Database (PDO), functions, view rendering, Lang
  middleware/auth.php    auth guards, CSRF, login throttling, audit logging
  models/                Branch, User, Bill, AuditLog, Setting
  validators/            PdfValidator
  lang/                  en.php, de.php — UI string catalogues (see Languages)
  views/                 layouts, error pages, shared form partials
public/
  index.php, login.php, logout.php, profile.php
  set-language.php       POST endpoint for the language switcher
  view.php, view_pdf.php, download.php     (authorized PDF access)
  owner/                 dashboard, branches, admins, bills, activity, audit, settings
  branch/                dashboard, upload, my-uploads
  api/                   auth, branches, admins, bills (JSON endpoints)
assets/
  css/app.css            custom SaaS theme (burgundy/gold)
  js/app.js, upload.js   UI + drag-drop upload logic
  vendor/                vendored Bootstrap 5.3 + Bootstrap Icons
tools/
  check-i18n.php         validates the app/lang catalogues against English
database/
  init.sql               authoritative schema + seed (used by Docker MySQL init)
  migrations/, seeders/  same content split for documentation
docker/
  docker-compose.yml, Dockerfile, nginx.conf, php.ini
storage/                 uploads + logs (gitignored, writable)
```

---

## Security notes

- Passwords hashed with `password_hash()` (bcrypt); sessions `httponly` +
  `SameSite=Lax`; session IDs regenerated on login.
- CSRF token on every POST (forms + API); absent/mismatched → `419`.
- All queries use prepared statements; output escaped with `e()` (`htmlspecialchars`).
- PDFs are never served from a static path — always through authorized PHP endpoints.
