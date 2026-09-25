# Deploying to Hostinger (shared hosting)

Applies to any Apache/LiteSpeed shared host: Hostinger, cPanel, Hostinger Cloud, and
most conventional PHP hosting. The app already ships the configuration files needed —
this page is the checklist.

---

## 1. What to upload

Upload the **whole project flat** — the repository root becomes the web root.
This is what a Git deploy gives you automatically, and it is the layout the
bundled `.htaccess` files are written to protect.

```
public_html/            ← the repository root, deployed as-is
├── .htaccess           ← root guard (blocks app/, storage/, database/ …)
├── app/                ← PHP application
├── database/           ← schema + migrations
├── public/             ← login.php, index.php, owner/, branch/, api/, assets/
├── storage/            ← uploads, archive, logs (must be writable)
├── tools/              ← CLI utilities (cron jobs)
├── vendor/             ← empty, no Composer install needed
└── docker/             ← local development only, safe to delete after deploy
```

### Why the layout matters

`public/login.php` resolves the app with `dirname(__DIR__)` and
`public/owner/bills.php` with `dirname(__DIR__, 2)`. Both resolve to the
**parent of the web root**, so `app/` has to sit directly beside it.

Two layouts work. Both are tested against Apache 2.4 with the shipped
`.htaccess` files.

#### A. Flat — the whole repository is the web root (use this for Git)

```
/home/USER/public_html/        ← the repository root
├── .htaccess                  ← front controller + private-folder guard
├── app/  database/  storage/  tools/  vendor/  docker/
└── public/                    ← login.php, owner/, api/, assets/
```

Hostinger's Git integration can only deploy the repository root into the web
root, so this is the layout you get by default. The root `.htaccess` rewrites
every public URL into `public/` and returns 403 for the private folders.

- All 17 public routes verified: `/login.php`, `/owner/*`, `/branch/*`,
  `/api/*`, and every asset including `/assets/vendor/bootstrap/…`.
- All 30 sensitive paths verified 403: `app/`, `storage/`, `database/`,
  `tools/`, `docker/`, `db/`, `vendor/`, dotfiles, and every `.sql`/`.md`.

#### B. Split — `public/` becomes the web root (use this for FTP uploads)

```
/home/USER/
├── app/                       ← sibling of public_html, NOT nested deeper
├── database/  storage/  tools/  vendor/
└── public_html/               ← the CONTENTS of public/
```

Upload the contents of `public/` into `public_html/`, then put `app/`,
`storage/`, `database/`, `tools/` and `vendor/` **directly in `/home/USER/`**.

> The one thing that breaks this layout is putting `app/` inside an extra
> folder such as `/home/USER/radha_rani/app/`. The entry points look for
> `/home/USER/app/`, not `/home/USER/radha_rani/app/`, and every page dies
> with a blank 500.

Here `app/` and `storage/` are outside the docroot, so they are unreachable by
construction — traversal attempts (`/../app/config/config.php`) were verified
to return 403 as well.

---

## 1a. Deploying with Hostinger Git integration (recommended)

### Do NOT add a `package.json`

If Hostinger shows:

> This repository is missing a package.json file. Add a package.json file to
> your repo to enable full import, or continue as a static website.

you have opened **Deploy Web App**, which is Hostinger's **Node.js** pipeline.
It expects `npm install` and a Node entry point. This project is plain PHP with
no npm dependencies, so adding a `package.json` would make Hostinger treat it as
a Node app, look for a start script, and fail to serve the site.

Use the generic Git feature instead — it runs **no build step** and serves the
repository exactly as committed, which is what a PHP app needs.

### Steps

1. Push the repository to GitHub.
2. hPanel → **Websites** → your domain → **Dashboard**.
3. Sidebar → **Advanced → Git**. *(Not "Deploy Web App", and not Auto Installer.)*
4. **Connect with GitHub** → authorise the Hostinger GitHub App for this repo.
5. Pick the repository, then the branch (`main`).
6. **Root directory**: leave it as `public_html`. That produces layout A above;
   the root `.htaccess` handles the rest.
7. **Deploy**.

After the first deploy, open the hPanel File Manager and create
`app/config/config.local.php` with your database credentials (section 3). That
file is git-ignored, so a normal `git pull` will not touch it.

> If a later deploy ever removes it, move the credentials to environment
> variables instead — `app/config/config.php` reads `DB_HOST`, `DB_PORT`,
> `DB_NAME`, `DB_USER` and `DB_PASS` from the environment first, ahead of both
> the local file and the built-in defaults.

---

## 2. Create the database

**hPanel → Databases → MySQL Databases**

1. Create a database, e.g. `radha_rani`.
2. Create a user with a strong password.
3. **Add the user to the database** with all privileges.

hPanel prefixes both names with your account, e.g. `u123456789_radha_rani`. Use the
prefixed names exactly as shown.

---

## 3. Add the credentials

Edit **`app/config/config.local.php`** (shipped, all lines commented out) and uncomment
what you need:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u123456789_radha_rani');   // prefixed name from hPanel
define('DB_USER', 'u123456789_radha_rani');   // prefixed name from hPanel
define('DB_PASS', 'the-password-hPanel-generated');
```

This file is loaded before the defaults and overrides them, so upgrades never
overwrite your credentials. Never put the password in `config.php`.

### If the app is in a sub-folder

Only when the URL is `https://example.com/portal` rather than the domain root:

```php
define('APP_URL', 'https://example.com/portal');
```

At the domain root this is detected automatically — leave it alone.

---

## 4. Import the schema

**hPanel → Databases → phpMyAdmin**, select your prefixed database, **Import**, and
choose `database/init.sql`.

That creates every table, the owner account, and the default settings.

Then confirm the dual-storage columns exist. Via SSH or hPanel's Terminal:

```bash
cd ~/radha_rani
php tools/deploy-check.php
```

If it reports `bills.pdf_bytes is missing`, run the migrations instead:

```bash
php tools/migrate.php
```

---

## 5. Permissions

```bash
chmod 755 ~/radha_rani
chmod -R 755 ~/radha_rani/app ~/radha_rani/database ~/radha_rani/tools
chmod -R 755 ~/radha_rani/storage/uploads ~/radha_rani/storage/archive ~/radha_rani/storage/logs
```

If PHP runs as a different user than your FTP user, use `775` on the three `storage`
directories instead of `755`.

Never use `777`.

---

## 6. PHP version and limits

**hPanel → Select PHP Version → PHP 8.0 or newer** (8.1/8.2 recommended), then
**Extensions**: enable `pdo_mysql`, `fileinfo`, `mbstring`, `json`.

`public/.user.ini` already raises the limits and is the shared-hosting-safe way to do
it. hPanel's own values **override** `.user.ini`, so if you change anything in
**PHP Settings**, keep it at least as large:

| Setting | Value |
|---|---|
| `upload_max_filesize` | `25M` |
| `post_max_size` | `30M` |
| `memory_limit` | `256M` |
| `max_execution_time` | `120` |

> Do **not** use `php_value` in `.htaccess`. It requires mod_php and returns
> **HTTP 500** on LiteSpeed/PHP-FPM, which is what Hostinger runs. The shipped
> `.htaccess` deliberately contains none.

### The `max_allowed_packet` gotcha

Every PDF is also stored as a database blob, so a single `INSERT` must fit inside
MySQL's `max_allowed_packet`. Many shared plans default to **16 MB**, which is *below*
the portal's 20 MB default — uploads that large would fail.

The app reads the real value and caps uploads automatically, so nothing breaks; it
just tells you the lower limit. To support full 20 MB bills, either lower
**Settings → Maximum PDF Size** to match, or ask Hostinger to raise the packet limit.

`php tools/deploy-check.php` reports both numbers.

---

## 7. Security check

The included `public/.htaccess` handles the hardening, provided `.htaccess` support is
enabled (it is by default on Hostinger):

- `app/`, `storage/`, `tools/`, `database/`, `docker/`, `db/`, `vendor/` → 403
- dotfiles (`.env`, `.user.ini`, `.htaccess`, `.git`) → 403
- `.sql`, `.ini`, `.log`, `.md`, `.json`, archives → 403
- `storage/.htaccess` additionally stops anything uploaded from ever executing
- `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN` (needed for the PDF iframe),
  `Referrer-Policy`, `Permissions-Policy` headers
- `ServerSignature` off, `X-Powered-By` removed

Verify by requesting one of these in a browser — each must return **403**:

```
https://yourdomain.com/app/config/config.php
https://yourdomain.com/storage/
https://yourdomain.com/database/init.sql
https://yourdomain.com/.user.ini
```

### First login, then lock down

1. Sign in with the seeded owner account.
2. **Profile → Update Password** — change the seeded password immediately.
3. In hPanel, turn on **HTTPS/SSL** (free certificate) and make sure hPanel forces HTTPS.

---

## 8. Cron jobs

**hPanel → Advanced → Cron Jobs**, command:

```bash
/usr/bin/php /home/USER/radha_rani/tools/purge-bills.php
```

- Frequency: once a day, e.g. `0 3 * * *`

This erases the stored PDF copies of bills whose restore window has expired. The app
also runs this sweep whenever an owner opens the Recently Deleted page, so the cron job
is a safety net, not the only trigger.

Optional, run after the first deploy:

```bash
php /home/USER/radha_rani/tools/backfill-pdf-copies.php --apply
```

Only needed if you are moving an existing database whose bills predate dual storage.

---

## 9. Final verification

```bash
cd ~/radha_rani
php tools/deploy-check.php
```

A healthy install ends with `0 error(s)`. Warnings are informational — read them,
but they do not block the site.

Then confirm in a browser:

1. `https://yourdomain.com/` redirects to the login page
2. Owner login works and the dashboard loads
3. Upload a PDF as a branch admin → it appears in Bills and opens in the viewer
4. Owner → **Recently Deleted** shows the new page; delete and restore a bill

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **500 on every page** | `php_value` left in `.htaccess` | Remove it; use `public/.user.ini` or hPanel PHP Settings |
| **Blank page, nothing loads** | `app/` not found from the docroot | Check the `app/` ↔ `public/` sibling layout in section 1 |
| `Access denied for user` | Wrong DB name/user in `config.local.php` | Use the **prefixed** hPanel names |
| Uploads fail silently | `storage/` not writable | `chmod -R 755` (or `775`) the three `storage` dirs |
| `bills.pdf_bytes` missing | Schema predates dual storage | `php tools/migrate.php` |
| Large uploads rejected | `max_allowed_packet` too small | Expected — see section 6; lower Settings → Maximum PDF Size |
| No PDF data being stored | `config.local.php` DB password wrong, old DB still selected | Confirm `DB_NAME` points at the imported database |

### Blank 500 pages: the two built-in diagnostic pages

The error page is deliberately vague, because in production a raw exception message
can leak the database name and hostname. But while you are still setting up, the app
will show you the actual reason. Both pages appear **only** when `APP_ENV` is not
`production`; set `define('APP_ENV', 'production');` in `config.local.php` once you
are finished and both fall back to a plain 500 with no detail.

| Page you see | What it means | What to do |
|---|---|---|
| **"Cannot connect to the database"** | MySQL refused the connection. The driver message and the exact `user@host:port/db` target are printed, with no password. | Create `app/config/config.local.php` with the four `DB_*` values from hPanel → Databases, then import `database/init.sql` (section 4). |
| **"Application error"** + exception name | The connection worked but a query failed — usually a table that does not exist because the schema was never imported. A stack trace is available under a fold. | Import the schema, and check `DB_NAME` matches the database you imported into. |

Both failures are also appended to `storage/logs/app.log`, which is the authoritative
record. Read it with hPanel → File Manager → `storage/logs/app.log`.

A useful tell: **if the login form renders but submitting it returns 500**, the failure
is at the database, not the page. The form itself needs no database; the first thing
the POST handler does is query the login-throttle table.

