ALTER TABLE assets
    MODIFY COLUMN phone_format_status ENUM('active', 'formatted', 'to_format') NULL AFTER status;