ALTER TABLE assets
    ADD COLUMN phone_category ENUM('administrative', 'operational') NULL AFTER phone_format_status;

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