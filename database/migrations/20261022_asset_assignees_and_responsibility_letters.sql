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

INSERT IGNORE INTO asset_assignees (asset_id, user_id, person_name, assigned_date)
SELECT au.asset_id, au.user_id, u.name, au.assigned_date
FROM asset_users au
JOIN users u ON u.id=au.user_id;

INSERT IGNORE INTO asset_assignees (asset_id, user_id, person_name)
SELECT DISTINCT rl.asset_id, rl.user_id, u.name
FROM responsibility_letters rl
JOIN users u ON u.id=rl.user_id;

ALTER TABLE responsibility_letters
    MODIFY COLUMN user_id INT UNSIGNED NULL,
    ADD COLUMN assignee_id INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN person_name VARCHAR(140) NULL AFTER assignee_id;

UPDATE responsibility_letters rl
JOIN users u ON u.id=rl.user_id
SET rl.person_name=u.name;

UPDATE responsibility_letters rl
JOIN asset_assignees aa ON aa.asset_id=rl.asset_id AND aa.user_id=rl.user_id
SET rl.assignee_id=aa.id;

ALTER TABLE responsibility_letters
    MODIFY COLUMN user_id INT UNSIGNED NULL,
    MODIFY COLUMN person_name VARCHAR(140) NOT NULL;

ALTER TABLE responsibility_letters
    DROP FOREIGN KEY fk_responsibility_letters_user;

ALTER TABLE responsibility_letters
    ADD CONSTRAINT fk_responsibility_letters_user_v2
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE responsibility_letters
    ADD CONSTRAINT fk_responsibility_letters_assignee_v2
        FOREIGN KEY (assignee_id) REFERENCES asset_assignees(id) ON DELETE SET NULL;

ALTER TABLE responsibility_letters
    ADD INDEX idx_responsibility_letters_assignee_v2 (assignee_id);