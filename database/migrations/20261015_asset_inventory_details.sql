ALTER TABLE assets
    ADD COLUMN windows_username VARCHAR(190) NULL AFTER serial_number,
    ADD COLUMN department VARCHAR(140) NULL AFTER assigned_date,
    ADD COLUMN terminal VARCHAR(190) NULL AFTER department,
    ADD COLUMN job_title VARCHAR(140) NULL AFTER terminal,
    ADD COLUMN monitor VARCHAR(160) NULL AFTER job_title,
    ADD COLUMN mouse VARCHAR(160) NULL AFTER monitor,
    ADD COLUMN rdp_access VARCHAR(190) NULL AFTER mouse;
