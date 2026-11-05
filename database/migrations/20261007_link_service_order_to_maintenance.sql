ALTER TABLE responsibility_documents
    ADD COLUMN maintenance_id INT UNSIGNED NULL AFTER user_id,
    ADD UNIQUE KEY uq_documents_maintenance (maintenance_id),
    ADD CONSTRAINT fk_documents_maintenance
        FOREIGN KEY (maintenance_id) REFERENCES maintenance(id) ON DELETE SET NULL;
