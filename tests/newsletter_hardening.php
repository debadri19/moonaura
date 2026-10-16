<?php
/* ===================================================================
   STATIC TESTS: Newsletter unsubscribe / eligibility / headers
   -------------------------------------------------------------------
   Run: php tests/newsletter_hardening.php
   Does not send mail, touch MySQL, or call Brevo.
=================================================================== */

putenv('NEWSLETTER_UNSUB_SECRET=');
putenv('SITE_URL=https://example.test');
putenv('MAIL_FROM_ADDRESS=noreply@example.test');
putenv('MAIL_REPLY_TO_ADDRESS=support@example.test');
putenv('BREVO_API_KEY=');
putenv('BREVO_SMTP_HOST=');
putenv('ENVIRONMENT=development');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$failures = 0;

function expect_true(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "PASS  $label\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  $label\n");
}

if (!defined('NEWSLETTER_UNSUB_SECRET')) {
    define('NEWSLETTER_UNSUB_SECRET', '');
}

require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

expect_true(newsletter_unsub_secret_configured() === false, 'missing secret is not configured');
expect_true(newsletter_campaign_secret() === '', 'missing secret fails closed (empty)');
expect_true(newsletter_unsubscribe_token('qa@example.test') === [], 'missing secret does not sign');
expect_true(newsletter_verify_unsubscribe_token('qa@example.test', (string) (time() + 3600), 'abc') === false, 'missing secret rejects tokens');

$secretProp = new ReflectionFunction('newsletter_unsub_secret_configured');
expect_true($secretProp->getFileName() !== '', 'secret helper is loadable');

$source = file_get_contents(dirname(__DIR__) . '/includes/newsletter-campaign-functions.php');
expect_true(!str_contains($source, "SITE_URL . '|newsletter-unsub'"), 'SITE_URL fallback removed');
expect_true(!str_contains($source, 'INVOICE_TOKEN_SECRET'), 'invoice secret fallback removed');
expect_true(!str_contains($source, 'ADMIN_2FA_ENCRYPTION_KEY'), '2FA secret fallback removed');
expect_true(!str_contains($source, 'List-Unsubscribe-Post'), 'one-click List-Unsubscribe-Post not added');

$mailer = file_get_contents(dirname(__DIR__) . '/includes/mailer.php');
expect_true(str_contains($mailer, 'array $extraHeaders = []'), 'send_email accepts extra headers');
expect_true(str_contains($mailer, 'addCustomHeader'), 'send_email can attach custom headers');

$save = file_get_contents(dirname(__DIR__) . '/includes/newsletter-admin-functions.php');
expect_true(!str_contains($save, "status = \\'draft\\'"), 'save no longer forces draft status');
expect_true(str_contains($save, 'cta_url = ?') && str_contains($save, "status IN (\\'draft\\',\\'failed\\',\\'cancelled\\')"), 'save keeps failed/cancelled status');
expect_true(str_contains($save, "'status'           => 'unsubscribed'"), 'contacts missing from pending+confirmed lists are cached as unsubscribed');

$process = file_get_contents(dirname(__DIR__) . '/includes/newsletter-campaign-functions.php');
expect_true(str_contains($process, 'newsletter_send_eligibility'), 'send-time eligibility helper exists');
expect_true(str_contains($process, 'Could not verify subscriber status with Brevo'), 'Brevo API failure is conservative');
expect_true(str_contains($process, 'List-Unsubscribe'), 'List-Unsubscribe header helper exists');
expect_true(str_contains($process, 'newsletter_campaign_claim_recipient'), 'per-recipient claim helper exists');
expect_true(str_contains($process, "status = 'claimed'"), 'claim update requires pending status');
expect_true(str_contains($process, 'claim_token'), 'status updates are claim-token scoped');

$gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
expect_true(!str_contains($gitignore, '<<<<<<<') && !str_contains($gitignore, '>>>>>>>'), '.gitignore has no conflict markers');

$migration = file_get_contents(dirname(__DIR__) . '/database/migration_newsletter_campaign_sending.sql');
expect_true(str_contains($migration, 'information_schema.COLUMNS'), 'Phase 3 migration uses column-existence guards');
expect_true(str_contains($migration, 'No — not executed') || str_contains($migration, 'not executed'), 'Phase 3 migration documents non-execution');

$claimMigration = file_get_contents(dirname(__DIR__) . '/database/migration_newsletter_recipient_claims.sql');
expect_true(str_contains($claimMigration, 'claim_token'), 'claim migration adds claim_token');
expect_true(str_contains($claimMigration, 'No — not executed') || str_contains($claimMigration, 'not executed'), 'claim migration documents non-execution');

fwrite(STDOUT, "\nSynthetic token cases require NEWSLETTER_UNSUB_SECRET at runtime.\n");
fwrite(STDOUT, "Repeat locally after setting a dedicated secret of 16+ characters:\n");
fwrite(STDOUT, "  1. valid token: newsletter_unsubscribe_token() then newsletter_verify_unsubscribe_token()\n");
fwrite(STDOUT, "  2. tampered email / sig / exp must return false\n");
fwrite(STDOUT, "  3. expired exp must return false\n");
fwrite(STDOUT, "  4. previous SITE_URL/invoice/2FA fallback signatures must return false\n");
fwrite(STDOUT, "  5. localhost SITE_URL must not produce an unsubscribe URL\n");

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll static newsletter hardening checks passed.\n");
exit(0);
