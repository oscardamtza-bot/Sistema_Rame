CREATE DATABASE IF NOT EXISTS rame_it CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rame_it;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(140) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    role ENUM('admin', 'technician', 'user') NOT NULL DEFAULT 'user',
    department VARCHAR(140) NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(80) NOT NULL UNIQUE,
    type ENUM('computer', 'phone', 'tablet', 'other') NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(120) NOT NULL,
    serial_number VARCHAR(150) NULL,
    windows_username VARCHAR(190) NULL,
    windows_password TEXT NULL,
    windows_password_ciphertext TEXT NULL,
    status ENUM('assigned', 'available', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    phone_format_status ENUM('active', 'formatted', 'to_format') NULL,
    phone_category ENUM('administrative', 'operational') NULL,
    assigned_user_id INT UNSIGNED NULL,
    assigned_date DATE NULL,
    department VARCHAR(140) NULL,
    terminal VARCHAR(190) NULL,
    job_title VARCHAR(140) NULL,
    monitor VARCHAR(160) NULL,
    mouse VARCHAR(160) NULL,
    rdp_access VARCHAR(190) NULL,
    purchase_date DATE NULL,
    warranty_until DATE NULL,
    antivirus_product VARCHAR(160) NULL,
    antivirus_expires_on DATE NULL,
    source_assignee_name VARCHAR(800) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assets_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_users (
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id, user_id),
    INDEX idx_asset_users_user_asset (user_id, asset_id),
    CONSTRAINT fk_asset_users_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_assignees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    person_name VARCHAR(140) NOT NULL,
    department VARCHAR(140) NULL,
    assigned_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_assignees_asset_name (asset_id, person_name),
    INDEX idx_asset_assignees_user_asset (user_id, asset_id),
    CONSTRAINT fk_asset_assignees_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS responsibility_letters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    assignee_id INT UNSIGNED NULL,
    person_name VARCHAR(140) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(36) NOT NULL UNIQUE,
    mime_type VARCHAR(80) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_responsibility_letters_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_responsibility_letters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_responsibility_letters_assignee FOREIGN KEY (assignee_id) REFERENCES asset_assignees(id) ON DELETE SET NULL,
    CONSTRAINT fk_responsibility_letters_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_responsibility_letters_asset_user_date (asset_id, user_id, created_at),
    INDEX idx_responsibility_letters_assignee (assignee_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(140) NOT NULL,
    username VARCHAR(190) NULL,
    secret_ciphertext TEXT NOT NULL,
    asset_id INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_credentials_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS licenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product VARCHAR(160) NOT NULL,
    vendor VARCHAR(140) NULL,
    license_key_ciphertext TEXT NULL,
    seats INT UNSIGNED NOT NULL DEFAULT 1,
    seats_confirmed TINYINT(1) NOT NULL DEFAULT 1,
    activations INT UNSIGNED NOT NULL DEFAULT 0,
    expires_on DATE NULL,
    status ENUM('active', 'expiring', 'expired', 'inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS license_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NULL,
    asset_serial_snapshot VARCHAR(150) NULL,
    assigned_name VARCHAR(140) NULL,
    department VARCHAR(140) NULL,
    installed_at DATE NULL,
    source_status VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_license_assignments_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_license_assignments_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    INDEX idx_license_assignments_asset (asset_id),
    INDEX idx_license_assignments_license (license_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    technician_id INT UNSIGNED NULL,
    scheduled_at DATETIME NOT NULL,
    proposed_at DATETIME NULL,
    status ENUM('pending', 'confirmed', 'reschedule_requested', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    reserved_slot DATETIME GENERATED ALWAYS AS (CASE WHEN status='cancelled' THEN NULL WHEN status='reschedule_requested' AND proposed_at IS NOT NULL THEN proposed_at ELSE scheduled_at END) STORED,
    invite_token_hash CHAR(64) NULL UNIQUE,
    invitation_sent_at DATETIME NULL,
    notes TEXT NULL,
    completed_at DATETIME NULL,
    signature_data MEDIUMTEXT NULL,
    signed_name VARCHAR(140) NULL,
    signed_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maintenance_reserved_slot (reserved_slot),
    CONSTRAINT fk_maintenance_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_maintenance_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_maintenance_technician FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS responsibility_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    maintenance_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    form_type VARCHAR(30) NOT NULL DEFAULT 'responsibility',
    form_data LONGTEXT NULL,
    signed_name VARCHAR(140) NULL,
    signature_data MEDIUMTEXT NULL,
    signed_at DATETIME NULL,
    signed_ip VARCHAR(45) NULL,
    performed_by_name VARCHAR(140) NULL,
    performed_by_signature_data MEDIUMTEXT NULL,
    performed_by_signed_at DATETIME NULL,
    performed_by_signed_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_documents_maintenance (maintenance_id),
    CONSTRAINT fk_documents_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_documents_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_documents_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    event_type ENUM('repair', 'failure', 'inspection', 'other') NOT NULL DEFAULT 'other',
    component VARCHAR(160) NULL,
    details TEXT NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_asset_history_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_asset_history_asset_date (asset_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_program_meta (
  plan_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  revision VARCHAR(20) NOT NULL DEFAULT 'P',
  issue_date VARCHAR(60) NOT NULL DEFAULT '25 de Abril del 2023',
  revision_date VARCHAR(60) NOT NULL DEFAULT '',
  updated_by_name VARCHAR(140) NOT NULL DEFAULT '',
  updated_on VARCHAR(60) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS technician_signatures (
  name VARCHAR(140) NOT NULL PRIMARY KEY,
  signature_data MEDIUMTEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
