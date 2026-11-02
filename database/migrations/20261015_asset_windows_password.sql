ALTER TABLE assets
    ADD COLUMN windows_password_ciphertext TEXT NULL AFTER windows_username;
