# Deploying to Hostinger (shared hosting)

Applies to any Apache/LiteSpeed shared host: Hostinger, cPanel, Hostinger Cloud, and
most conventional PHP hosting. The app already ships the configuration files needed —
this page is the checklist.

---

## 1. What to upload

Upload the **whole project** and keep the folder layout exactly as it is.

The code resolves paths relatively, so `public/` **must** stay a sibling of `app/`:

```
radha_rani/
├── app/          ← PHP application (must NOT be web-reachable)
├── database/     ← schema + migrations (must NOT be web-reachable)
├── docker/       ← local development only, safe to skip
├── public/       ← the ONLY directory that goes in public_html
├── storage/      ← uploads, archive, logs (must be writable, not web-reachable)
├── tools/        ← CLI utilities (must NOT be web-reachable)
├── vendor/       ← empty, no Composer install needed
└── .user.ini / .htaccess  (they live inside public/)
```

### Where each part goes

| Local path | Upload to | Notes |
|---|---|---|
| everything in `public/` | `public_html/` | `login.php`, `index.php`, `owner/`, `branch/`, `api/`, `assets/`, `.htaccess`, `.user.ini` |
| `app/` | `/home/USER/radha_rani/app/` | one level **above** `public_html` |
| `storage/` | `/home/USER/radha_rani/storage/` | must be writable |
| `tools/` | `/home/USER/radha_rani/tools/` | for the cron jobs |
| `database/` | `/home/USER/radha_rani/database/` | for migrations |
| `docker/` | *skip* | not used in production |

Resulting layout in your account:

```
/home/USER/
├── public_html/          ← docroot: public/* contents live here
└── radha_rani/           ← everything else
    ├── app/
    ├── database/
    ├── storage/
    └── tools/
```

> **Do not** put `app/` inside `public_html/`. If you prefer a single flat upload
> (everything inside `public_html/`), the included `.htaccess` blocks `app/`,
> `storage/`, `tools/`, `database/`, `docker/`, `db/` and `vendor/` from the web, so
> it is safe — but the split layout is better because those files are never
> web-reachable in the first place.

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
