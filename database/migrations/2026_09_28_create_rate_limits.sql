-- ============================================================
-- Rate limiting — protection for the unauthenticated public
-- portal endpoints (studentSaveCard, studentUploadPhoto,
-- lostIdRequest, publicCaptcha).
--
-- Conventions follow the existing schema: InnoDB, utf8mb4,
-- no foreign keys (integrity enforced at application level,
-- matching audit_logs / password_resets style).
--
-- backend/rate_limit.php creates the same tables automatically on
-- first use, so importing this file is optional but recommended so
-- the tables exist with the documented shape from the start.
-- ============================================================

-- One row per (bucket, IP, window). "hits" is incremented with
--   INSERT ... ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits+1)
-- so concurrent requests from one IP can never race past the limit.
-- Buckets are per action, e.g. 'public:studentSaveCard'.
CREATE TABLE IF NOT EXISTS rate_limit_counters (
  bucket VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (bucket, ip_address, window_start),
  KEY idx_rl_counters_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Human verification: extra attempts granted to an IP that solved a
-- captcha challenge. UNIQUE(bucket, ip_address, window_start) makes
-- the grant a one-off per window, so the anonymous limit can never be
-- lifted more than once per window by solving challenges.
CREATE TABLE IF NOT EXISTS rate_limit_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  window_start DATETIME NOT NULL,
  extra_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rl_grant (bucket, ip_address, window_start),
  KEY idx_rl_grants_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Issued captcha challenges. Only the HMAC-SHA256 hash of the answer
-- is stored, together with the issuing IP, an attempt counter and an
-- expiry, so a database leak cannot be used to answer a challenge.
CREATE TABLE IF NOT EXISTS rate_limit_captchas (
  captcha_id CHAR(26) NOT NULL PRIMARY KEY,
  answer_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  bucket VARCHAR(64) NOT NULL DEFAULT '',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rl_captcha_expiry (expires_at),
  KEY idx_rl_captcha_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;