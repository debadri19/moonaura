<?php
/* ===================================================================
   CLI: SEND DUE NEWSLETTER CAMPAIGNS (Phase 3)
   -------------------------------------------------------------------
   Resumes interrupted sends, then delivers due scheduled campaigns.
    Confirmed subscribers only. Retry-safe via campaign lock, per-
    recipient claim_token, and recipient rows. Stale claims become
    unknown and are not auto-retried. Does not record open/click
    analytics.

   Usage:
     php scripts/send-newsletter-campaigns.php
=================================================================== */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

@ini_set('max_execution_time', '0');
ignore_user_abort(true);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

if (!newsletter_campaign_tables_ready()) {
    fwrite(STDERR, "Apply database/migration_newsletter_campaign_sending.sql first.\n");
    exit(1);
}

$resumed = newsletter_campaign_resume_sending();
$due = newsletter_campaign_run_due();

fwrite(STDOUT, 'Resumed: ' . (int) $resumed['processed'] . ' processed, ' . (int) $resumed['sent'] . " sent.\n");
fwrite(STDOUT, 'Scheduled: ' . (int) $due['processed'] . ' processed, ' . (int) $due['sent'] . " sent.\n");
exit(0);
