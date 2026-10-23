ALTER TABLE responsibility_documents
    ADD COLUMN form_type VARCHAR(30) NOT NULL DEFAULT 'responsibility' AFTER body,
    ADD COLUMN form_data LONGTEXT NULL AFTER form_type,
    ADD COLUMN signed_ip VARCHAR(45) NULL AFTER signed_at,
    ADD COLUMN performed_by_name VARCHAR(140) NULL AFTER signed_ip,
    ADD COLUMN performed_by_signature_data MEDIUMTEXT NULL AFTER performed_by_name,
    ADD COLUMN performed_by_signed_at DATETIME NULL AFTER performed_by_signature_data,
    ADD COLUMN performed_by_signed_ip VARCHAR(45) NULL AFTER performed_by_signed_at;