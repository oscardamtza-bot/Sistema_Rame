ALTER TABLE responsibility_letters
    ADD COLUMN asset_id INT UNSIGNED NULL AFTER id,
    ADD CONSTRAINT fk_responsibility_letters_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    ADD INDEX idx_responsibility_letters_asset_user_date (asset_id, user_id, created_at);
