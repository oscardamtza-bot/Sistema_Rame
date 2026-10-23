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
    status ENUM('assigned', 'available', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    phone_format_status ENUM('active', 'formatted', 'to_format') NULL,
    phone_category ENUM('administrative', 'operational') NULL,
    assigned_user_id INT UNSIGNED NULL,
    purchase_date DATE NULL,
    warranty_until DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assets_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
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
    activations INT UNSIGNED NOT NULL DEFAULT 0,
    expires_on DATE NULL,
    status ENUM('active', 'expiring', 'expired', 'inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    technician_id INT UNSIGNED NULL,
    scheduled_at DATETIME NOT NULL,
    proposed_at DATETIME NULL,
    status ENUM('pending', 'confirmed', 'reschedule_requested', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    invite_token_hash CHAR(64) NOT NULL UNIQUE,
    invitation_sent_at DATETIME NULL,
    notes TEXT NULL,
    completed_at DATETIME NULL,
    signature_data MEDIUMTEXT NULL,
    signed_name VARCHAR(140) NULL,
    signed_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maintenance_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_maintenance_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_maintenance_technician FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS responsibility_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
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
    CONSTRAINT fk_documents_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_documents_user FOREIGN KEY (user_id) REFERENCES users(id)
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