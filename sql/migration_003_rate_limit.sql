-- migration_003_rate_limit.sql
-- Tabella per il rate limiting su login, registrazione e reset password
-- Idempotente: può essere rieseguita senza errori

CREATE TABLE IF NOT EXISTS rate_limit (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    bucket     VARCHAR(120)     NOT NULL COMMENT 'es. login:127.0.0.1, register:1.2.3.4',
    hit_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bucket_hit (bucket, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pulizia automatica tramite EVENT (opzionale, richiede event scheduler abilitato)
-- DROP EVENT IF EXISTS evt_clean_rate_limit;
-- CREATE EVENT evt_clean_rate_limit
--   ON SCHEDULE EVERY 1 HOUR
--   DO DELETE FROM rate_limit WHERE hit_at < DATE_SUB(NOW(), INTERVAL 2 HOUR);
