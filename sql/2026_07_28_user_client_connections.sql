CREATE TABLE IF NOT EXISTS auth_user_client_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(255) NULL,
  connected_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  UNIQUE KEY uniq_user_client (user_id, client_id),
  KEY idx_last_used_at (last_used_at),
  CONSTRAINT fk_connection_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_connection_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
