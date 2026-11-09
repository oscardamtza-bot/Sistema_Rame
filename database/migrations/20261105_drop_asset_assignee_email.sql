USE rame_it;

ALTER TABLE asset_assignees
    DROP COLUMN IF EXISTS email;
