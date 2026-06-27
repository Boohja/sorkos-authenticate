CREATE TABLE auth_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  display_name VARCHAR(255) NULL,
  avatar_url TEXT NULL,
  preferred_language VARCHAR(8) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  disabled_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  provider_user_id VARCHAR(255) NOT NULL,
  provider_email VARCHAR(255) NULL,
  provider_email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_provider_user (provider, provider_user_id),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_identities_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_hash CHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  user_agent_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  KEY idx_user_id (user_id),
  KEY idx_expires_at (expires_at),
  CONSTRAINT fk_auth_sessions_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(128) NOT NULL UNIQUE,
  client_secret_hash VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  domain VARCHAR(255) NULL,
  default_language VARCHAR(8) NOT NULL DEFAULT 'en',
  allowed_languages VARCHAR(255) NOT NULL DEFAULT 'en,de',
  enabled_providers VARCHAR(255) NOT NULL DEFAULT 'email,google,discord',
  branding_json JSON NULL,
  settings_json JSON NULL,
  is_confidential TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_email_login_codes (
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

CREATE TABLE auth_client_redirect_uris (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  redirect_uri TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_client_id (client_id),
  CONSTRAINT fk_redirect_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_authorization_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL UNIQUE,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  redirect_uri TEXT NOT NULL,
  scope VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY idx_client_id (client_id),
  KEY idx_user_id (user_id),
  KEY idx_expires_at (expires_at),
  CONSTRAINT fk_auth_code_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_auth_code_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_user_client_consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uniq_user_client (user_id, client_id),
  CONSTRAINT fk_consent_user
    FOREIGN KEY (user_id) REFERENCES auth_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_consent_client
    FOREIGN KEY (client_id) REFERENCES auth_clients(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(128) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret_encrypted TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type VARCHAR(32) NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(128) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_id VARCHAR(128) NULL,
  ip_hash CHAR(64) NULL,
  user_agent_hash CHAR(64) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
