USE rame_it;

ALTER TABLE maintenance
    MODIFY invite_token_hash CHAR(64) NULL;
