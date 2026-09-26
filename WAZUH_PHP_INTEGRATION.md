# Wazuh integration for the Radha Rani Hotel Portal

End-to-end process for turning the portal's security activity into decoded,
correlated, alertable events in Wazuh: a structured application log, Agent
collection plus file integrity monitoring, Manager-side decoding and rules, and
verification at each step.

Written against **Wazuh 4.14.8** (Agent and Manager) on **Ubuntu 24.04**, with
the application running in Docker on Windows.

---

## 1. Architecture

```
 PHP app (Docker)              Windows host                 Manager VM
 ─────────────────            ──────────────────            ─────────────────────
  SecurityLogger::log()
      │  appends one JSON
      │  object per line
      ▼
 storage/logs/security.log ──► wazuh-agent
  (bind mount, so the            │  <localfile> log_format json
   container writes the           │  no decoder on the agent
   same file the Agent            │
   reads)                          ├─ FIM: app/, public/, storage/,
                                   │       .htaccess, assets/js
                                   │
                                   └── TCP 1514 ──► analysisd
                                                       │  decoder 0600
                                                       │  rules   0580
                                                       ▼
                                                 alerts ──► indexer ──► dashboard
```

Two decisions worth understanding, because both are easy to get wrong:

**The decoder and rules live on the Manager, not the Agent.** The Agent only
ships raw lines; `analysisd` on the Manager decodes them. Adding a `<ruleset>`
block to the Agent's `ossec.conf` would cause a second, conflicting decode.

**The log is a plain file on the host, not a container volume.** The app
directory is bind-mounted, so PHP writing inside the container writes the same
inode the Windows Agent watches. No log shipper, no volume mount, no agent
running in the container.

---

## 2. Application side: the security logger

### 2.1 What was built

`app/helpers/SecurityLogger.php` — one JSON object per line, appended with
`FILE_APPEND | LOCK_EX`.

Requirements it satisfies:

| Requirement | Implementation |
|---|---|
| One JSON object per line | `json_encode` with no pretty printing |
| Never truncate on failure | Write failure calls `error_log()` and returns; it never throws |
| No fatal on unwritable path | Path is checked before writing; directory exists via `storage/logs` |
| Sensitive values redacted | Key-name match against a deny list, applied to the whole field |
| No secrets in the log | Metadata only; file contents, query values and request bodies are never read |
| Bounded fields | Capped at 24 context fields, values truncated |
| No per-event DB or HTTP cost | Pure file append, no query, no network call |
| Rotation | Optional, at 20 MB (`SECURITY_LOG_MAX_BYTES`; `0` disables) |

### 2.2 Configuration

In `app/config/config.php`:

```php
define('SECURITY_LOG_ENABLED', true);
define('SECURITY_LOG_FILE', dirname(__DIR__, 2) . '/storage/logs/security.log');
define('SECURITY_LOG_MAX_BYTES', 20 * 1024 * 1024); // 0 disables rotation
```

`SECURITY_LOG_ENABLED` is the kill switch — set it to `false` to stop all
security logging without touching any call site.

Loaded once in `app/bootstrap.php` via `require_once`, after config, so the
error handler can use it too.

### 2.3 Event vocabulary

`event` is a fixed, low-cardinality identifier. Rules match on it; free text
never becomes a field name.

| Event | Meaning |
|---|---|
| `login_success` / `login_failed` | Authentication outcome |
| `account_locked` | Lockout after repeated failures |
| `rate_limit_triggered` | Login throttle fired |
| `unauthorized_access` | No session where one required |
| `forbidden_access` | Authenticated but role not permitted |
| `sensitive_record_access` | Cross-branch or otherwise out-of-scope record access |
| `csrf_validation_failed` | Token missing or mismatched |
| `suspicious_request` | Malformed or out-of-pattern request |
| `invalid_input` | Failed validation |
| `password_change` / `password_reset` | Own password / someone else's |
| `password_reset_requested` / `password_reset_failed` | Reserved for a self-service flow |
| `role_changed` | Role or branch assignment changed |
| `user_created` / `user_updated` / `user_deleted` | Account administration |
| `admin_action` / `admin_settings_changed` | Owner-level operations |
| `record_created` / `record_updated` / `record_deleted` / `record_restored` | Data lifecycle |
| `file_uploaded` / `file_downloaded` / `file_deleted` | File operations |
| `application_error` / `database_error` / `unexpected_exception` | Runtime health |
| `security_logging_error` | The logger itself failed |
| `log_rotated` | Log rotation occurred |

### 2.4 Two details that matter more than they look

**`result` describes the event outcome, not the audit write.** The first
implementation hardcoded `"result":"success"` because the audit insert had
succeeded, so a failed login logged as `result: success`. A rule filtering on
`result=failed` then matched nothing and an analyst would have concluded
nothing was wrong — worse than no logging. It is now derived from the event
(`failed` / `denied` / `blocked` / `success`).

**Redaction is case-insensitive.** A case-sensitive comparison missed
`Authorization` and `Set-Cookie`, both of which belong on the deny list. The
key is lowercased before matching.

### 2.5 How call sites are instrumented

Most events flow through the existing audit helper rather than being added
individually. `log_activity()` in `app/helpers/functions.php` now mirrors to
`SecurityLogger` through a single mapper, so every existing call site is
covered without editing each one:

```php
log_activity($userId, $branchId, 'LOGIN_FAILED', 'user', $id, $description, $target);
```

Events that have no existing audit call — CSRF failures, throttling,
authorization denials, validation errors — call `SecurityLogger` directly in
`app/middleware/auth.php` and at the entry points.

Files touched: `app/bootstrap.php`, `app/config/config.php`,
`app/helpers/functions.php`, `app/middleware/auth.php`,
`app/helpers/SecurityLogger.php` (new), `public/login.php`,
`public/profile.php`, `public/view.php`, `public/view_pdf.php`,
`public/download.php`, `public/api/auth/login.php`,
`public/api/auth/logout.php`, `public/api/bills/upload.php`,
`public/api/bills/item.php`, `public/api/admins/index.php`,
`public/api/admins/item.php`, `public/api/branches/item.php`, and the
`public/owner/*.php` pages.

### 2.6 Client IP

`REMOTE_ADDR` only, validated. `HTTP_X_FORWARDED_FOR` is deliberately ignored
because it is client-controlled unless a trusted proxy is known to overwrite
it. The Docker nginx sets `fastcgi_param REMOTE_ADDR $remote_addr`, so the real
client address is preserved.

### 2.7 Log exposure

`storage/logs/` sits outside the web root, and is denied by `.htaccess`,
`public/.htaccess` and `docker/nginx.conf`. No Wazuh change was needed.
Confirmed by `GET /storage/logs/security.log` returning 404 from nginx.

---

## 3. Agent side: collection and FIM

### 3.1 `ossec.conf` additions

```xml
<localfile>
    <location>C:\xampp\htdocs\radha_rani\storage\logs\security.log</location>
    <log_format>json</log_format>
</localfile>
```

`json` is a built-in Agent log format. It tells the Agent the file is JSON so
it can be read as discrete events; no custom decoder goes here.

```xml
<directories recursion_level="2" restrict="\.php$">C:\xampp\htdocs\radha_rani\app</directories>
<directories recursion_level="2" restrict="\.php$">C:\xampp\htdocs\radha_rani\public</directories>
<directories recursion_level="0" restrict="\.php$">C:\xampp\htdocs\radha_rani\tools</directories>
<directories recursion_level="0" restrict="\.js$">C:\xampp\htdocs\radha_rani\public\assets\js</directories>
<directories recursion_level="0" restrict="^\.htaccess$">C:\xampp\htdocs\radha_rani</directories>
<directories recursion_level="0" restrict="^\.htaccess$">C:\xampp\htdocs\radha_rani\public</directories>
<directories recursion_level="0" restrict="^\.htaccess$">C:\xampp\htdocs\radha_rani\storage</directories>
```

Scope is deliberately narrow. `app/` and `public/` are the only executable code
paths; `restrict` keeps FIM off uploads, logs and other high-churn files, which
would otherwise flood the queue. `config.local.php` is watched (integrity, not
content) but its values are never transmitted — Wazuh reports the hash, not the
file. `.htaccess` is watched separately because disabling it is a
web-root exposure.

A note on the FIM window: `scan_on_start` plus a 43200-second (12-hour) scan
is a backstop; `Real-time file integrity monitoring started` in the Agent log
confirms changes are also caught within seconds.

### 3.2 Applying it safely

`C:\Program Files (x86)\ossec-agent\ossec.conf` needs elevation, and the file
is XML — a malformed edit stops the Agent from starting. So:

1. Back up `ossec.conf` first.
2. Insert the blocks.
3. **Parse the result before writing it over the live file.** A hand edit that
   produces invalid XML must be rejected, not discovered at service start.
4. Restart, then read `ossec.log`.

Two things that bit during this work and are worth internalising:

- **XML comments cannot contain `--`.** A `-----` separator line inside a
  comment is a hard parse error. Use `=====` or `.....`.
- **A PowerShell `-match` on a Windows path treats `\` as a regex escape.**
  `'radha_rani\storage\logs\security.log'` fails to match. Use `.Contains()`.

### 3.3 Verifying the Agent

```powershell
Get-Content "C:\Program Files (x86)\ossec-agent\ossec.log" -Tail 40
```

Expected, and all observed on this host:

```
INFO: Monitoring path: 'c:\xampp\htdocs\radha_rani\app', with options ...
INFO: (1950): Analyzing file: 'C:\xampp\htdocs\radha_rani\storage\logs\security.log'.
INFO: Trying to connect to server ([192.168.136.128]:1514/tcp).
INFO: (4102): Connected to the server ([192.168.136.128]:1514/tcp).
INFO: Agent is now online. Process unlocked, continuing...
INFO: (6012): Real-time file integrity monitoring started.
```

A 200 on `http://localhost:8091/login.php` plus two deliberately failed logins
should produce two well-formed JSON lines in `security.log`. That proves the
bind mount works and the Agent reads what the container writes.

---

## 4. Manager side: decoder

`/var/ossec/etc/ruleset/decoders/0600-radha-rani-security_decoder.xml`

Wazuh's default Manager `ossec.conf` already includes `ruleset/decoders` and
`ruleset/rules`, so no config change is needed to activate these files.

Four chained decoders. The first matches this application and hands the line
to the built-in `JSON_Decoder` plugin, which turns every top-level JSON key
into a queryable field. The other three rename the three keys whose canonical
Wazuh names matter:

| JSON key | Wazuh field | Why |
|---|---|---|
| `ip` | `srcip` | only `srcip` is understood by `same_srcip` correlation and the source-IP panels |
| `username` | `srcuser` | canonical "who did it" field |
| `event` | `event_type` | `event` is a reserved concept in `analysisd`; `event_type` reads correctly in rules |

```xml
<decoder name="radha-rani-security">
    <prematch>^\{"timestamp":"[^"]*","event":"[^"]*","application":"RADHA_RANI_PORTAL"</prematch>
    <plugin_decoder>JSON_Decoder</plugin_decoder>
</decoder>

<decoder name="radha-rani-security-srcip">
    <parent>radha-rani-security</parent>
    <regex>"ip":"([^"]*)"</regex>
    <order>srcip</order>
</decoder>

<decoder name="radha-rani-security-srcuser">
    <parent>radha-rani-security-srcip</parent>
    <regex>"username":"([^"]*)"</regex>
    <order>srcuser</order>
</decoder>

<decoder name="radha-rani-security-eventtype">
    <parent>radha-rani-security-srcuser</parent>
    <regex>"event":"([^"]*)"</regex>
    <order>event_type</order>
</decoder>
```

Two things to preserve if you edit these:

**The patterns have no leading anchor**, so they are independent of field order
in the JSON.

**They are written as `"username":"`, with the quote.** This is what stops
`srcuser` matching inside `target_username`: there the preceding character is
`_`, not `"`. Verified against a payload where `target_username` precedes
`username` — `srcuser` still resolves to the acting account, which is what
keeps per-user correlation honest.

Numeric-looking fields (`user_id`, `file_size`) arrive as strings, because
Wazuh decoder fields are strings. Fine for equality matching and correlation,
which is all the rules use them for.

---

## 5. Manager side: rules

`/var/ossec/etc/ruleset/rules/0580-radharani_rules.xml` — 40 rules,
IDs 100100–100154, in a range clear of Wazuh's shipped and stock local rules
so nothing collides on upgrade.

### Alerting noise

A single failed login is not an incident. On a busy hotel portal a
mistyped password or a clerk confusing two usernames is routine, sometimes
dozens of times a day. Alerting on each one trains staff to ignore the
channel, which costs more than the missed signal.

So the pattern is:

- **one event** → low level, searchable, not something to triage
- **a pattern** → real alert, correlated on the field that matters
- **a high-value event** (role change, permanent purge, failed log pipeline)
  → alerts on its own

| Rule | Level | Trigger |
|---|---|---|
| 100100 | 3 | one failed login |
| 100101 | 2 | successful login (audit trail) |
| 100104 | 10 | 5 failed logins, one account, 5 min |
| 100105 | 12 | 10 failed logins, one account, 5 min |
| 100106 | 9 | 5 failed logins, one IP, 2 min |
| 100107 | 10 | 5 *different* accounts from one IP, 5 min (enumeration) |
| 100110 | 5 | `unauthorized_access` |
| 100111 | 7 | `forbidden_access` |
| 100112 | 8 | cross-branch record access |
| 100113 | 6 | CSRF failure |
| 100120–123 | 5–7 | password change / reset |
| 100124 | 9 | role or branch changed |
| 100125–127 | 5–7 | account created / updated / deleted |
| 100130–132 | 3–8 | record created / updated / permanently deleted |
| 100133 | 3 | upload accepted |
| 100134 | 5 | upload rejected |
| 100135–138 | 8 | rejected upload with `.php`, `.phtml`, `.phar`, `.exe` |
| 100140 | 7 | `security_logging_error` — the evidence pipeline failed |
| 100150 | 7 | `security_logging_error` |
| 100153 | 7 | `database_error` |
| 100154 | 8 | `unexpected_exception` |

Rules 100100/100102/100103 carry
`<group>authentication_failures,</group>`, which is what lets 100107 use
`<if_matched_group>` with `<count_distinct>` to separate username enumeration
from repeated attempts on one account.

Every rule carries a MITRE ATT&CK mapping so portal alerts line up with the
rest of the console.

### Deliberate gaps

**No "succeeded right after failures" rule.** Wazuh's `frequency` element
counts prior matches of a single rule; it cannot ask "was rule A seen
recently, and is the event arriving now rule B". The only approximation is
`frequency="1"`, which fires on *every* failed login. Getting this signal right
needs a correlation rule in a custom analyser or a saved query in the
dashboard. Shipping the broken version would have manufactured noise, so it is
documented here instead.

**`password_reset_requested` / `password_reset_failed` have no call site.** The
portal has no self-service reset flow; only an owner resetting another user's
password, which maps to `password_reset`. The decoder and rules support all
three so a future flow needs no SIEM change.

**Web-server access logs are not collected.** The live stack is nginx in a
container whose logs are not written to the host. Collecting them needs a log
volume mount, which changes the Docker setup. The XAMPP Apache logs on the host
are not the live runtime and are not monitored.

### Known limitation: client IPs in the local Docker stack

Every request through the Docker port mapping shows `ip` as the Docker bridge
gateway (for example `172.29.0.1`), because Docker rewrites the source address
in NAT. So per-IP correlation groups all local users together. Per-username
rules are the meaningful signal here, and they work either way. On a real
deployment where nginx terminates the request directly, the true client IP is
preserved and per-IP rules become meaningful as well.

---

## 6. Deploying to the Manager

```bash
scp wazuh/manager/decoders/0600-radha-rani-security_decoder.xml \
    root@MANAGER:/var/ossec/etc/ruleset/decoders/
scp wazuh/manager/rules/0580-radharani_rules.xml \
    root@MANAGER:/var/ossec/etc/ruleset/rules/

chown root:root /var/ossec/etc/ruleset/decoders/0600-*.xml
chown root:root /var/ossec/etc/ruleset/rules/0580-*.xml
chmod 640 /var/ossec/etc/ruleset/decoders/0600-*.xml
chmod 640 /var/ossec/etc/ruleset/rules/0580-*.xml
```

Check for an ID collision before restarting — `analysisd` refuses to start on
duplicate rule IDs:

```bash
grep -ho 'rule id="[0-9]*"' /var/ossec/etc/ruleset/rules/*.xml | sort | uniq -d
```

Validate, then restart:

```bash
/var/ossec/bin/wazuh-logtest -V
systemctl restart wazuh-manager
journalctl -u wazuh-manager -n 50 --no-pager
```

Restarting the Manager is disruptive to ingestion for a few seconds, so do it
once, after both files are in place — not after each.

---

## 7. Verifying end to end

### 7.1 Does the rule fire?

```bash
echo '{"timestamp":"2026-09-26T14:09:46+05:30","event":"login_failed","application":"RADHA_RANI_PORTAL","ip":"203.0.113.9","method":"POST","uri":"/login.php","user_id":1,"username":"owner","result":"failed"}' \
  | /var/ossec/bin/wazuh-logtest
```

Expect `srcip`, `srcuser`, `event_type` populated and rule **100100, level 3**.

Feed it six events within the timeframe and expect **100104, level 10** to
appear on the fifth — that proves correlation works, not just decoding.

### 7.2 Does it work through the real pipeline?

Trigger real events in the app rather than injecting text, so the whole chain is
exercised: Agent → Manager → indexer → dashboard.

1. A few failed logins (brute-force rule 100104/100105).
2. A real successful login (100101) — check the actor hint is correct.
3. An upload attempt, including one rejected for the wrong type (100134, and
   100135 if a `.php` name is tried).
4. A cross-branch access attempt by a branch admin (100112).
5. An owner settings change (100129).
6. Edit `app/helpers/SecurityLogger.php` and watch a built-in FIM alert fire
   (rules 550/551/552); restore it afterwards.

### 7.3 Dashboard views

Create a saved search per view so analysts do not rebuild queries:

- **Recent security events** — `agent.name=AGENT-NAME AND event_type:*`,
  columns `timestamp, event_type, srcuser, srcip, url, outcome`
- **Failed logins** — `event_type:login_failed`, sorted by `timestamp` desc
- **Brute force** — `rule.id:100104 OR rule.id:100105`
- **Uploads** — `event_type:file_uploaded`
- **Admin activity** — `event_type:admin_*`
- **FIM changes** — `rule.group:syscheck` filtered to the project paths

Useful field choices, all provided by the decoder:
`event_type`, `srcuser`, `srcip`, `srcrole`, `url`, `http_method`, `outcome`,
`reason`, `object_type`, `object_id`, `dstuser`, `file_extension`,
`file_mime`, `file_size`, `admin_operation`, `audit_action`.

For a per-app panel, add
`data.app_name: RADHA_RANI_PORTAL` as the filter.

---

## 8. Operations

**Log growth.** At 20 MB the logger renames the file to `security.log.1` and
starts fresh, logging `log_rotated` (rule 100141). One generation is kept, by
design — the portal is low volume and unbounded logs on a laptop are worse than
a bounded trail. Raise `SECURITY_LOG_MAX_BYTES` if the site grows.

**If alerts stop arriving.** Check in this order:
`security.log` still being written → Agent `ossec.log` for
`Analyzing file` / `Connected to the server` → Agent queue
(`analysisd` on Windows) → Manager `wazuh-analysisd` log for decode errors →
indexer and dashboard filters.

**Pipeline health is itself monitored.** `security_logging_error` (100150) and
`log_rotated` (100141) mean a silent loss of evidence is visible rather than
looking like a quiet day.

**Disabling.** Set `SECURITY_LOG_ENABLED` to `false`. To stop SIEM-side
processing without touching the app, remove or rename the two Manager files and
restart `wazuh-manager`.

**Tuning.** Start with the levels as shipped and adjust after a week of real
traffic. If 100105 is noisy, it is almost always a shared NAT or the Docker
gateway address — check `srcip` before lowering severity. Per-username rules
are more reliable than per-IP in that environment.

---

## 9. Status

Verified on this host:

- Logger: redaction, JSON validity, newline-injection neutralisation, UTF-8,
  query-value omission, 12 processes × 200 concurrent events with zero loss
- All PHP files lint clean
- Agent `ossec.conf` patched, XML-validated before writing, service restarted
- Agent reads `security.log` through the Docker bind mount and connects to the
  Manager
- FIM active on all six configured paths, real-time
- Decoder field patterns unit-tested, including the `target_username`
  collision case
- Ruleset: 40 rules, no duplicate IDs, all `if_matched` targets resolve, valid
  XML

Not yet verified — requires Manager SSH access:

- Decoder and rules deployed to the Manager
- `wazuh-logtest` decode and correlation output
- `wazuh-manager` restart clean
- End-to-end alert arrival and the dashboard views
- FIM alerts reaching the Manager
