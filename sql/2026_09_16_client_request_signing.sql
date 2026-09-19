ALTER TABLE auth_clients
  ADD COLUMN request_signing_key CHAR(64) NULL AFTER client_secret_hash;
