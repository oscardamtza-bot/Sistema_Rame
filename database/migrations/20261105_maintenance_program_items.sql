USE rame_it;

CREATE TABLE IF NOT EXISTS maintenance_program_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_year SMALLINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL DEFAULT 0,
    model VARCHAR(140) NOT NULL DEFAULT '',
    serial_number VARCHAR(120) NOT NULL DEFAULT '',
    asset_tag VARCHAR(60) NOT NULL,
    department VARCHAR(140) NOT NULL DEFAULT '',
    monitor_code VARCHAR(60) NOT NULL DEFAULT '',
    planned_months VARCHAR(40) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_item (plan_year, asset_tag),
    INDEX idx_program_department (plan_year, department)
) ENGINE=InnoDB;
