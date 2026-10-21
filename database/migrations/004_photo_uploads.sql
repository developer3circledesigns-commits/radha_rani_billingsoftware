-- Radha Rani Hotel Portal - Migration 004
-- Photo bill uploads.
--
-- A photo is converted to a PDF before storage, so every downstream reader
-- (viewer, download, purge, restore) keeps working on application/pdf. These
-- columns only record where the document came from, for the owner's audit and
-- for the source badge in the UI. Existing rows are PDFs by definition and are
-- backfilled by the column default.

ALTER TABLE bills
    ADD COLUMN upload_source ENUM('pdf', 'photo') NOT NULL DEFAULT 'pdf' AFTER mime_type,
    ADD COLUMN source_mime VARCHAR(100) NULL AFTER upload_source;
