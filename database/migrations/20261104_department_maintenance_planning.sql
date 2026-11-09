-- Planning is keyed by the department text already stored in assets.department.
-- This migration creates history and request structures without changing assets,
-- users, maintenance records, or existing department assignments.

CREATE TABLE IF NOT EXISTS maintenance_department_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_year SMALLINT UNSIGNED NOT NULL,
    plan_month TINYINT UNSIGNED NOT NULL,
    department VARCHAR(140) NOT NULL,
    recipient_email VARCHAR(190) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maintenance_department_plan (plan_year, plan_month, department),
    INDEX idx_maintenance_department_plan_month (plan_year, plan_month, is_active),
    CONSTRAINT fk_maintenance_department_plan_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_maintenance_department_plan_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_plan_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NULL,
    plan_year SMALLINT UNSIGNED NOT NULL,
    plan_month TINYINT UNSIGNED NOT NULL,
    department VARCHAR(140) NOT NULL,
    action ENUM('added', 'removed', 'recipient_updated') NOT NULL,
    previous_email VARCHAR(190) NULL,
    recipient_email VARCHAR(190) NULL,
    changed_by INT UNSIGNED NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maintenance_department_plan_history (plan_year, plan_month, department, changed_at),
    CONSTRAINT fk_maintenance_department_plan_history_plan FOREIGN KEY (plan_id) REFERENCES maintenance_department_plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_department_plan_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    status ENUM('notification_pending', 'email_sent', 'confirmed', 'cancelled', 'expired') NOT NULL DEFAULT 'notification_pending',
    recipient_email VARCHAR(190) NULL,
    scheduled_start DATETIME NULL,
    confirmed_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maintenance_department_request_plan (plan_id),
    CONSTRAINT fk_maintenance_department_request_plan FOREIGN KEY (plan_id) REFERENCES maintenance_department_plans(id),
    CONSTRAINT fk_maintenance_department_request_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_request_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    recipient_email VARCHAR(190) NOT NULL,
    expires_at DATETIME NOT NULL,
    status ENUM('sending', 'sent', 'failed', 'revoked', 'confirmed', 'expired') NOT NULL DEFAULT 'sending',
    failure_message VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    INDEX idx_maintenance_department_request_token (request_id, status, expires_at),
    CONSTRAINT fk_maintenance_department_request_token_request FOREIGN KEY (request_id) REFERENCES maintenance_department_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_department_request_token_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_request_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    token_id BIGINT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NULL,
    asset_tag_snapshot VARCHAR(80) NOT NULL,
    assigned_people_snapshot TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maintenance_department_request_asset_tag (token_id, asset_tag_snapshot),
    INDEX idx_maintenance_department_request_asset (request_id, asset_id),
    CONSTRAINT fk_maintenance_department_request_asset_request FOREIGN KEY (request_id) REFERENCES maintenance_department_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_department_request_asset_token FOREIGN KEY (token_id) REFERENCES maintenance_department_request_tokens(id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_department_request_asset_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_request_services (
    request_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    maintenance_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (request_id, asset_id),
    UNIQUE KEY uq_maintenance_department_request_service (maintenance_id),
    CONSTRAINT fk_maintenance_department_request_service_request FOREIGN KEY (request_id) REFERENCES maintenance_department_requests(id),
    CONSTRAINT fk_maintenance_department_request_service_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_maintenance_department_request_service_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_department_request_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    details TEXT NULL,
    actor_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maintenance_department_request_history (request_id, created_at),
    CONSTRAINT fk_maintenance_department_request_history_request FOREIGN KEY (request_id) REFERENCES maintenance_department_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_department_request_history_user FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
