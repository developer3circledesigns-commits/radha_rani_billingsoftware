-- Radha Rani Hotel Portal - Migration 002
-- Dual PDF storage: filesystem (primary) + database blob (safety copy),
-- plus reversible (archived) soft-deletes with automatic purge.
--
-- All additions are nullable/defaulted, so existing rows and current code
-- keep working unchanged after this migration.

ALTER TABLE bills
    ADD COLUMN pdf_bytes LONGBLOB NULL AFTER description,
    ADD COLUMN pdf_hash CHAR(64) NULL AFTER pdf_bytes,
    ADD COLUMN storage_status ENUM('both', 'file_only', 'db_only', 'none') NOT NULL DEFAULT 'file_only' AFTER mime_type,
    ADD COLUMN archived_path VARCHAR(500) NULL AFTER file_path,
    ADD COLUMN purge_after DATETIME NULL AFTER deleted_at;

-- Fast lookup of what still needs to be purged.
ALTER TABLE bills
    ADD KEY idx_purge_after (purge_after);

-- Default retention window for reversible deletes (30 days).
INSERT INTO settings (setting_key, setting_value)
VALUES ('deleted_bill_retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
