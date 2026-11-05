ALTER TABLE assets
    ADD COLUMN antivirus_product VARCHAR(160) NULL AFTER warranty_until,
    ADD COLUMN antivirus_expires_on DATE NULL AFTER antivirus_product,
    ADD COLUMN source_assignee_name VARCHAR(140) NULL AFTER antivirus_expires_on;

ALTER TABLE licenses
    ADD COLUMN seats_confirmed TINYINT(1) NOT NULL DEFAULT 1 AFTER seats;

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