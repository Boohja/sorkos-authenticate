CREATE TABLE IF NOT EXISTS auth_email_login_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  selector_hash CHAR(64) NOT NULL UNIQUE,
  code_hash CHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  KEY idx_email_expires (email, expires_at),
  KEY idx_client_id (client_id),
  CONSTRAINT fk_email_login_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE auth_clients
  ALTER enabled_providers SET DEFAULT 'email,google,discord';

-- Run this for specific local test clients that should show the E-Mail option:
-- UPDATE auth_clients
-- SET enabled_providers = CONCAT('email,', enabled_providers)
-- WHERE client_id = 'your-client-id'
-- AND FIND_IN_SET('email', enabled_providers) = 0;
