-- ===================================================================
-- MIGRATION: Newsletter recipient claim ownership
-- -------------------------------------------------------------------
-- Adds claimed/unknown recipient statuses plus claim_token/claimed_at
-- so workers can take exclusive ownership before SMTP. Does not change
-- customers, orders, or storefront signup tables.
-- Apply after database/migration_newsletter_campaign_sending.sql.
-- Repeat-safe on MySQL 5.7+ / MariaDB 10+ via information_schema
-- guards. MODIFY ENUM is safe to re-run with the same values.
-- Do not execute this file from this workspace without an explicit
-- host-side request. Execution status for this task: No — not executed.
-- ===================================================================

ALTER TABLE newsletter_campaign_recipients
    MODIFY status ENUM('pending','claimed','sent','failed','unknown') NOT NULL DEFAULT 'pending';

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaign_recipients' AND COLUMN_NAME = 'claimed_at') = 0,
    'ALTER TABLE newsletter_campaign_recipients ADD COLUMN claimed_at DATETIME NULL AFTER sent_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaign_recipients' AND COLUMN_NAME = 'claim_token') = 0,
    'ALTER TABLE newsletter_campaign_recipients ADD COLUMN claim_token CHAR(32) NULL AFTER claimed_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaign_recipients' AND INDEX_NAME = 'idx_newsletter_recipients_claim') = 0,
    'ALTER TABLE newsletter_campaign_recipients ADD INDEX idx_newsletter_recipients_claim (campaign_id, status, claimed_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
