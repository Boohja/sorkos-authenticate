ALTER TABLE auth_session_clients
  DROP FOREIGN KEY fk_session_clients_session,
  DROP PRIMARY KEY,
  MODIFY session_id BIGINT UNSIGNED NULL,
  ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER session_id,
  ADD COLUMN client_session_token VARCHAR(68) NULL AFTER client_id,
  ADD PRIMARY KEY (id),
  ADD UNIQUE KEY uniq_session_client (session_id, client_id),
  ADD UNIQUE KEY uniq_client_session_token (client_session_token),
  ADD KEY idx_user_client_active (user_id, client_id, revoked_at);

UPDATE auth_session_clients sc
INNER JOIN auth_sessions s ON s.id = sc.session_id
SET sc.user_id = s.user_id,
    sc.client_session_token = CONCAT('cst_', REPLACE(UUID(), '-', ''), REPLACE(UUID(), '-', ''));

ALTER TABLE auth_session_clients
  MODIFY user_id BIGINT UNSIGNED NOT NULL,
  MODIFY client_session_token VARCHAR(68) NOT NULL,
  ADD CONSTRAINT fk_session_clients_session
    FOREIGN KEY (session_id) REFERENCES auth_sessions(id)
    ON DELETE SET NULL,
  ADD CONSTRAINT fk_session_clients_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE;

ALTER TABLE auth_authorization_codes
  ADD COLUMN session_client_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD KEY idx_session_client_id (session_client_id),
  ADD CONSTRAINT fk_auth_code_session_client
    FOREIGN KEY (session_client_id) REFERENCES auth_session_clients(id)
    ON DELETE SET NULL;