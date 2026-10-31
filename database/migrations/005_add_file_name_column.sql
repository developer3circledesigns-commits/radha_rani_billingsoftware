-- Radha Rani Hotel Portal - Add file_name column to bills table
-- Allows editable display name for uploaded files

ALTER TABLE bills ADD COLUMN file_name VARCHAR(255) NULL AFTER original_filename;

-- Set initial file_name values to match original_filename for existing records
UPDATE bills SET file_name = original_filename WHERE file_name IS NULL;