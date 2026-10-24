-- ===================================================================
-- FIXTURE: newsletter tables as of git revision 8a829d7
-- -------------------------------------------------------------------
-- Source: git show 8a829d7:database/schema.sql  (lines 872-977)
-- Commit: 8a829d7 Newsletter Phase 4
--
-- This is the last committed schema BEFORE
-- 658b69d ("Harden newsletter security and recipient claims")
-- added claimed/unknown statuses, claim_token, claimed_at, and
-- idx_newsletter_recipients_claim.
--
-- Use ONLY on a separately created disposable QA database
-- (name matching moonaura_qa_*) to prove that
-- database/migration_newsletter_recipient_claims.sql upgrades a
-- real pre-claim database. Loading current database/schema.sql
-- then applying the migrations does NOT prove upgrade compatibility
-- because schema.sql already contains the post-15 columns.
--
-- Do not execute this file against production. Do not use it as
-- a replacement for database/schema.sql on a fresh install.
-- The CREATE TABLE statements below are copied verbatim from
-- 8a829d7; only this header was added.
-- ===================================================================

CREATE TABLE newsletter_subscribers (

    email               VARCHAR(190)    NOT NULL PRIMARY KEY,
    name                VARCHAR(160)    NULL,
    status              ENUM('pending','confirmed','unsubscribed') NOT NULL,
    source              VARCHAR(40)     NOT NULL DEFAULT 'brevo',
    brevo_id            BIGINT UNSIGNED NULL,
    subscribed_at       DATETIME        NULL,
    confirmed_at        DATETIME        NULL,
    unsubscribed_at     DATETIME        NULL,
    last_modified_at    DATETIME        NULL,
    synced_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_newsletter_subscribers_status (status),
    INDEX idx_newsletter_subscribers_name (name),
    INDEX idx_newsletter_subscribers_synced (synced_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE newsletter_campaigns (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(160)    NOT NULL,
    subject             VARCHAR(200)    NOT NULL DEFAULT '',
    preview_text        VARCHAR(255)    NULL,
    sender_name         VARCHAR(120)    NOT NULL,
    sender_email        VARCHAR(190)    NOT NULL,
    reply_to            VARCHAR(190)    NULL,
    body_html           LONGTEXT        NOT NULL,
    cta_text            VARCHAR(120)    NULL,
    cta_url             VARCHAR(500)    NULL,
    status              ENUM('draft','scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at        DATETIME        NULL,
    started_at          DATETIME        NULL,
    completed_at        DATETIME        NULL,
    recipient_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    sent_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    failure_reason      VARCHAR(255)    NULL,
    locked_at           DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_newsletter_campaigns_status (status),
    INDEX idx_newsletter_campaigns_updated (updated_at),
    INDEX idx_newsletter_campaigns_scheduled (status, scheduled_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE newsletter_campaign_sends (

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


CREATE TABLE newsletter_campaign_recipients (

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
