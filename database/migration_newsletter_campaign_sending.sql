-- ===================================================================
-- MIGRATION: Newsletter campaign sending (Admin Phase 3)
-- -------------------------------------------------------------------
-- Adds send/schedule fields to newsletter_campaigns and a send-history
-- table. Does not change customers, orders, or storefront newsletter
-- signup tables. Apply after database/migration_newsletter_admin.sql.
-- Repeat-safe on MySQL 5.7+ / MariaDB 10+ via information_schema
-- guards. CREATE TABLE IF NOT EXISTS statements below are idempotent.
-- MODIFY ENUM is safe to re-run with the same values.
-- Do not execute this file from this workspace without an explicit
-- host-side request. Execution status for this task: No — not executed.
-- ===================================================================

ALTER TABLE newsletter_campaigns
    MODIFY status ENUM('draft','scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft';

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'scheduled_at') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN scheduled_at DATETIME NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'started_at') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN started_at DATETIME NULL AFTER scheduled_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'completed_at') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN completed_at DATETIME NULL AFTER started_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'recipient_count') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN recipient_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'sent_count') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN sent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER recipient_count',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'failed_count') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN failed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER sent_count',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'failure_reason') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN failure_reason VARCHAR(255) NULL AFTER failed_count',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND COLUMN_NAME = 'locked_at') = 0,
    'ALTER TABLE newsletter_campaigns ADD COLUMN locked_at DATETIME NULL AFTER failure_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'newsletter_campaigns' AND INDEX_NAME = 'idx_newsletter_campaigns_scheduled') = 0,
    'ALTER TABLE newsletter_campaigns ADD INDEX idx_newsletter_campaigns_scheduled (status, scheduled_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


CREATE TABLE IF NOT EXISTS newsletter_campaign_sends (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT UNSIGNED    NOT NULL,
    campaign_name       VARCHAR(160)    NOT NULL,
    status              ENUM('sending','sent','failed','cancelled') NOT NULL,
    is_test             TINYINT(1)      NOT NULL DEFAULT 0,
    recipient_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    sent_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    scheduled_at        DATETIME        NULL,
    started_at          DATETIME        NULL,
    completed_at        DATETIME        NULL,
    failure_reason      VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_newsletter_sends_campaign (campaign_id),
    INDEX idx_newsletter_sends_created (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS newsletter_campaign_recipients (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT UNSIGNED    NOT NULL,
    email               VARCHAR(190)    NOT NULL,
    name                VARCHAR(160)    NULL,
    status              ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    error_message       VARCHAR(255)    NULL,
    sent_at             DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_newsletter_campaign_recipient (campaign_id, email),
    INDEX idx_newsletter_recipients_status (campaign_id, status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
