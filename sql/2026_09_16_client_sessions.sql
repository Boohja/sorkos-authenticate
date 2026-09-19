ALTER TABLE auth_sessions
  ADD COLUMN session_description VARCHAR(120) NULL AFTER user_agent_hash;

CREATE TABLE IF NOT EXISTS auth_session_clients (
  session_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (session_id, client_id),
  KEY idx_client_active (client_id, revoked_at),
  CONSTRAINT fk_session_clients_session
    FOREIGN KEY (session_id) REFERENCES auth_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_session_clients_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
