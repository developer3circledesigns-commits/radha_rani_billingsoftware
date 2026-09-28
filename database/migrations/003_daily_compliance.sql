-- -----------------------------------------------------------
-- Table: daily_upload_checks
-- -----------------------------------------------------------
-- One row per calendar day the compliance sweep has run for. The UNIQUE key on
-- check_date is the idempotency guard: the cron may run hourly and the owner
-- dashboard may run the same check on page load, but a day is only ever
-- recorded once. That is what stops an alert storm.
CREATE TABLE IF NOT EXISTS daily_upload_checks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    check_date DATE NOT NULL,
    evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    branches_total INT UNSIGNED NOT NULL DEFAULT 0,
    branches_missing INT UNSIGNED NOT NULL DEFAULT 0,
    notifications_created INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_check_date (check_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: notifications
-- -----------------------------------------------------------
-- Owner-facing alerts. There is no notifications table anywhere else in the
-- schema, so this is the only one.
--
-- WHY THE ROW STORES AN I18N KEY AND NOT TEXT
-- title_key / body_key hold catalogue keys, and body_params holds the JSON
-- placeholders. The string is rendered in whatever language the owner is
-- browsing in at the moment they read the alert. Storing pre-rendered text
-- would freeze an English string into a German owner's inbox the first time the
-- portal was used in English.
--
-- read_at AND resolved_at ARE DIFFERENT THINGS
-- read_at    = the owner has seen it.
-- resolved_at = the underlying condition is no longer true, i.e. the missing
--               bill arrived. An alert the owner cannot dismiss and that never
--               goes away by itself is a broken alert, so the upload endpoint
--               closes these. The unread badge counts rows that are neither read
--               nor resolved, so a condition that resolved itself stops nagging.
--
-- dedupe_key makes re-evaluation a no-op for an unchanged situation. The key
-- includes which types are missing, so "no cash, no card" and later "no card
-- only" are two genuinely different states and each gets its own alert.
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    severity ENUM('info', 'warning', 'danger') NOT NULL DEFAULT 'warning',
    title_key VARCHAR(190) NOT NULL,
    body_key VARCHAR(190) NOT NULL,
    body_params TEXT NULL,
    branch_id INT UNSIGNED NULL,
    bill_date DATE NULL,
    dedupe_key VARCHAR(190) NOT NULL,
    read_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dedupe (dedupe_key),
    KEY idx_user_state (user_id, resolved_at, read_at),
    KEY idx_user_created (user_id, created_at),
    KEY idx_branch_date (branch_id, bill_date),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Settings: daily upload compliance
-- -----------------------------------------------------------
-- Defaults mirror the defaults in app/models/DailyCompliance.php, so a database
-- that never ran this migration still behaves correctly on defaults.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('daily_upload_alert_enabled', '1'),
    ('daily_upload_deadline_time', '23:30'),
    ('daily_upload_deadline_mode', 'time'),
    ('daily_upload_deadline_weekdays', ''),
    ('daily_upload_alert_start_date', ''),
    ('daily_upload_alert_end_date', '');
