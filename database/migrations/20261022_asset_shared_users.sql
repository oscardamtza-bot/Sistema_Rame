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

INSERT IGNORE INTO asset_users (asset_id, user_id, assigned_date)
SELECT id, assigned_user_id, assigned_date
FROM assets
WHERE assigned_user_id IS NOT NULL;