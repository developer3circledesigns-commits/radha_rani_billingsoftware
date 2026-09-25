-- Radha Rani Hotel Portal - Database Schema + Seed
-- Combined init run by MySQL on first container start.

-- -----------------------------------------------------------
-- Table: branches
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_code VARCHAR(20) NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    address TEXT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(190) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branch_code (branch_code),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: users
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(190) NOT NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'branch_admin') NOT NULL DEFAULT 'branch_admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_username (username),
    KEY idx_branch_id (branch_id),
    KEY idx_role (role),
    KEY idx_status (status),
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: bills
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS bills (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    payment_type ENUM('cash', 'card') NOT NULL,
    business_date DATE NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    archived_path VARCHAR(500) NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    storage_status ENUM('both', 'file_only', 'db_only', 'none') NOT NULL DEFAULT 'file_only',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    description VARCHAR(500) NULL,
    pdf_bytes LONGBLOB NULL,
    pdf_hash CHAR(64) NULL,
    status ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMETIME NULL,
    purge_after DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_branch_id (branch_id),
    KEY idx_payment_type (payment_type),
    KEY idx_business_date (business_date),
    KEY idx_uploaded_by (uploaded_by),
    KEY idx_uploaded_at (uploaded_at),
    KEY idx_status (status),
    KEY idx_purge_after (purge_after),
    KEY idx_branch_date_type (branch_id, business_date, payment_type),
    CONSTRAINT fk_bills_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_bills_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: audit_logs
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    branch_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_branch_id (branch_id),
    KEY idx_action (action),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: settings
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('system_name', 'Radha Rani Hotel Portal'),
    ('max_file_size_mb', '20'),
    ('daily_cash_required', '1'),
    ('daily_card_required', '1'),
    ('session_lifetime_min', '30'),
    ('deleted_bill_retention_days', '30');