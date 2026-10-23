ALTER TABLE assets
    ADD COLUMN phone_format_status ENUM('formatted', 'to_format') NULL AFTER status;