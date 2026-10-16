<?php
/* ===================================================================
   BEHAVIORAL DB TEST: newsletter recipient result-write failure path
   -------------------------------------------------------------------
   Run: php tests/newsletter_recipient_result_failure.php

   Exercises §6.11 of tests/NEWSLETTER_RUNTIME_QA.md against a LOCAL,
   disposable QA database only. It never calls Brevo, never sends
   mail, and never touches a hosted database.

   A temporary trigger raises a SQL exception on the recipient result
   UPDATE so the failure path can be induced deterministically. The
   trigger is always dropped in a finally block, even if an assertion
   fails.

   Connection is taken from the usual environment variables, with
   QA-specific overrides so the disposable database is never confused
   with production:

     NEWSLETTER_QA_DB    (default moonaura_qa_test)
     NEWSLETTER_QA_USER  (default moonaura_qa)
     NEWSLETTER_QA_PASS  (default qa_local_only_pw)
     NEWSLETTER_QA_HOST  (default localhost)

   Exit codes: 0 = all checks passed, 1 = a check failed,
               3 = BLOCKED (QA database unavailable).
=================================================================== */

$qaDb   = getenv('NEWSLETTER_QA_DB') ?: 'moonaura_qa_test';
$qaUser = getenv('NEWSLETTER_QA_USER') ?: 'moonaura_qa';
$qaPass = getenv('NEWSLETTER_QA_PASS');
$qaPass = ($qaPass === false || $qaPass === '') ? 'qa_local_only_pw' : $qaPass;
$qaHost = getenv('NEWSLETTER_QA_HOST') ?: 'localhost';

/* Keep the application under test pointed at the disposable QA DB and
   with all outbound mail/Brevo delivery disabled. */
putenv('DB_HOST=' . $qaHost);
putenv('DB_NAME=' . $qaDb);
putenv('DB_USER=' . $qaUser);
putenv('DB_PASS=' . $qaPass);
putenv('NEWSLETTER_UNSUB_SECRET=');
putenv('SITE_URL=https://example.test');
putenv('MAIL_FROM_ADDRESS=noreply@example.test');
putenv('MAIL_REPLY_TO_ADDRESS=support@example.test');
putenv('BREVO_API_KEY=');
putenv('BREVO_SMTP_HOST=');
putenv('ENVIRONMENT=development');

/* Pre-flight: a raw probe so an unavailable database is reported as
   BLOCKED instead of dying inside db(). */
try {
    $probe = new PDO(
        'mysql:host=' . $qaHost . ';dbname=' . $qaDb . ';charset=utf8mb4',
        $qaUser,
        $qaPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDOUT, "BLOCKED  QA database '{$qaDb}' is not reachable: " . $e->getMessage() . "\n");
    fwrite(STDOUT, "BLOCKED  Provide an isolated local QA database; do not use a hosted one.\n");
    exit(3);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/newsletter-admin-functions.php';
require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

$failures = 0;
$passes = 0;

function expect_true(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        fwrite(STDOUT, "PASS  $label\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  $label\n");
}

function section(string $title): void
{
    fwrite(STDOUT, "\n-- $title\n");
}

$pdo = db();

$TRIGGER = 'qa_fail_result_before_update';
$FAIL_EMAILS = ['qa-fail-mark@example.test', 'qa-fail-stop@example.test'];
$createdCampaigns = [];

function qa_drop_trigger(PDO $pdo, string $trigger): void
{
    try {
        $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
    } catch (Throwable $e) {
        fwrite(STDERR, "cleanup: could not drop trigger: " . $e->getMessage() . "\n");
    }
}

function qa_create_trigger(PDO $pdo, string $trigger, array $emails): void
{
    qa_drop_trigger($pdo, $trigger);
    $list = implode(', ', array_map(static fn ($e) => $pdo->quote($e), $emails));
    $sql = "CREATE TRIGGER $trigger BEFORE UPDATE ON newsletter_campaign_recipients
            FOR EACH ROW
            BEGIN
                IF NEW.email IN ($list)
                   AND NEW.claim_token IS NULL
                   AND NEW.status IN ('sent', 'failed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA induced result-write failure';
                END IF;
            END";
    $pdo->exec($sql);
}

function qa_campaign(PDO $pdo, string $name, string $status = 'draft'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO newsletter_campaigns (name, subject, sender_name, sender_email, body_html, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, 'QA subject', 'QA Sender', 'qa@example.test', '<p>QA</p>', $status]);
    return (int) $pdo->lastInsertId();
}

function qa_recipient(
    PDO $pdo,
    int $campaignId,
    string $email,
    string $status,
    ?string $token = null,
    ?string $claimedAt = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO newsletter_campaign_recipients (campaign_id, email, name, status, claim_token, claimed_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$campaignId, $email, 'QA', $status, $token, $claimedAt]);
}

function qa_fetch(PDO $pdo, int $campaignId, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT status, claim_token, claimed_at, sent_at, error_message
         FROM newsletter_campaign_recipients WHERE campaign_id = ? AND email = ? LIMIT 1'
    );
    $stmt->execute([$campaignId, $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function qa_campaign_row(PDO $pdo, int $campaignId): ?array
{
    $stmt = $pdo->prepare('SELECT status, locked_at, sent_count, failed_count FROM newsletter_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$tokenA = str_repeat('a', 32);
$tokenB = str_repeat('b', 32);

try {
    /* ---------------------------------------------------------------
       1 and 2: induced DB exception -> false, row stays claimed.
    --------------------------------------------------------------- */
    section('1-2. mark_recipient failure is contained and preserves the claim');
    $c1 = qa_campaign($pdo, 'QA_RESULT_mark_fail', 'sending');
    $createdCampaigns[] = $c1;
    qa_recipient($pdo, $c1, 'qa-fail-mark@example.test', 'claimed', $tokenA, date('Y-m-d H:i:s'));

    qa_create_trigger($pdo, $TRIGGER, $FAIL_EMAILS);

    $threw = false;
    $result = null;
    try {
        $result = newsletter_campaign_mark_recipient($c1, 'qa-fail-mark@example.test', 'sent', '', $tokenA);
    } catch (Throwable $e) {
        $threw = true;
    }

    expect_true(!$threw, 'induced DB exception does not escape mark_recipient as an uncaught throw');
    expect_true($result === false, 'mark_recipient returns false when the UPDATE raises');

    $row = qa_fetch($pdo, $c1, 'qa-fail-mark@example.test');
    expect_true($row !== null && $row['status'] === 'claimed', 'recipient remains claimed after the failed result update');
    expect_true($row !== null && $row['claim_token'] === $tokenA, 'claim token is not cleared after the failed update');

    /* ---------------------------------------------------------------
       3: wrong claim token cannot update the recipient.
    --------------------------------------------------------------- */
    section('3. wrong claim token cannot update the recipient');
    $c3 = qa_campaign($pdo, 'QA_RESULT_wrong_token', 'sending');
    $createdCampaigns[] = $c3;
    qa_recipient($pdo, $c3, 'qa-wrong-token@example.test', 'claimed', $tokenA, date('Y-m-d H:i:s'));

    $wrong = newsletter_campaign_mark_recipient($c3, 'qa-wrong-token@example.test', 'sent', '', $tokenB);
    expect_true($wrong === false, 'wrong claim token returns false');
    $row = qa_fetch($pdo, $c3, 'qa-wrong-token@example.test');
    expect_true($row !== null && $row['status'] === 'claimed', 'wrong-token update leaves the row claimed');
    expect_true($row !== null && $row['claim_token'] === $tokenA, 'wrong-token update does not change the stored token');

    /* ---------------------------------------------------------------
       4: stale-claim cleanup changes the row to unknown, never pending.
    --------------------------------------------------------------- */
    section('4. stale-claim cleanup yields unknown, not pending');
    qa_drop_trigger($pdo, $TRIGGER);
    $c4 = qa_campaign($pdo, 'QA_RESULT_stale', 'sending');
    $createdCampaigns[] = $c4;
    qa_recipient($pdo, $c4, 'qa-stale@example.test', 'claimed', $tokenA, date('Y-m-d H:i:s', time() - 1000));
    qa_recipient($pdo, $c4, 'qa-recent@example.test', 'claimed', $tokenB, date('Y-m-d H:i:s'));

    $expired = newsletter_campaign_expire_stale_claims($c4);
    expect_true($expired >= 1, 'stale-claim cleanup reports at least one expired row');

    $stale = qa_fetch($pdo, $c4, 'qa-stale@example.test');
    expect_true($stale !== null && $stale['status'] === 'unknown', 'stale claimed row becomes unknown');
    expect_true($stale !== null && $stale['status'] !== 'pending', 'stale claimed row is never reset to pending');
    expect_true($stale !== null && $stale['claim_token'] === null, 'stale cleanup clears the claim token');

    $recent = qa_fetch($pdo, $c4, 'qa-recent@example.test');
    expect_true($recent !== null && $recent['status'] === 'claimed', 'fresh claim is untouched by stale cleanup');

    /* ---------------------------------------------------------------
       5: campaign processing stops when a result cannot be persisted.
    --------------------------------------------------------------- */
    section('5. campaign processing stops without sending the next recipient');
    $c5 = qa_campaign($pdo, 'QA_RESULT_process_stop', 'sending');
    $createdCampaigns[] = $c5;
    qa_recipient($pdo, $c5, 'qa-fail-stop@example.test', 'pending');
    qa_recipient($pdo, $c5, 'qa-second@example.test', 'pending');

    qa_create_trigger($pdo, $TRIGGER, $FAIL_EMAILS);

    $process = newsletter_campaign_process($c5, 0, 0);
    expect_true(isset($process['ok']) && $process['ok'] === false, 'process returns a failure result when a result cannot be recorded');
    expect_true(
        ($process['error'] ?? '') === NEWSLETTER_RECIPIENT_RESULT_PERSIST_ERROR,
        'process returns the explicit persistence-failure message'
    );
    expect_true(($process['sent'] ?? -1) === 0 && ($process['failed'] ?? -1) === 0, 'no sent/failed counts are reported for the aborted run');
    expect_true(empty($process['queued']), 'no success/queued flag is set for the aborted run');

    $first = qa_fetch($pdo, $c5, 'qa-fail-stop@example.test');
    expect_true($first !== null && $first['status'] === 'claimed', 'first recipient stays claimed (no fallback status write)');

    $second = qa_fetch($pdo, $c5, 'qa-second@example.test');
    expect_true($second !== null && $second['status'] === 'pending', 'second recipient is never claimed or sent');

    $camp = qa_campaign_row($pdo, $c5);
    expect_true($camp !== null && $camp['status'] === 'sending', 'campaign is not finalized as sent/failed');
    expect_true($camp !== null && $camp['locked_at'] !== null, 'campaign lock is preserved for a later resume');

    /* ---------------------------------------------------------------
       6: successful sent and failed result updates still work.
    --------------------------------------------------------------- */
    section('6. successful sent and failed result updates still work');
    qa_drop_trigger($pdo, $TRIGGER);
    $c6 = qa_campaign($pdo, 'QA_RESULT_success', 'sending');
    $createdCampaigns[] = $c6;
    qa_recipient($pdo, $c6, 'qa-ok-sent@example.test', 'claimed', $tokenA, date('Y-m-d H:i:s'));
    qa_recipient($pdo, $c6, 'qa-ok-failed@example.test', 'claimed', $tokenB, date('Y-m-d H:i:s'));

    $sentOk = newsletter_campaign_mark_recipient($c6, 'qa-ok-sent@example.test', 'sent', '', $tokenA);
    expect_true($sentOk === true, 'sent result update succeeds');
    $srow = qa_fetch($pdo, $c6, 'qa-ok-sent@example.test');
    expect_true($srow !== null && $srow['status'] === 'sent', 'sent row stores status sent');
    expect_true($srow !== null && $srow['claim_token'] === null, 'successful sent update clears the claim token');
    expect_true($srow !== null && $srow['sent_at'] !== null, 'successful sent update stamps sent_at');

    $failedOk = newsletter_campaign_mark_recipient($c6, 'qa-ok-failed@example.test', 'failed', 'SMTP rejected', $tokenB);
    expect_true($failedOk === true, 'failed result update succeeds');
    $frow = qa_fetch($pdo, $c6, 'qa-ok-failed@example.test');
    expect_true($frow !== null && $frow['status'] === 'failed', 'failed row stores status failed');
    expect_true($frow !== null && $frow['sent_at'] === null, 'failed row keeps sent_at null');
    expect_true($frow !== null && $frow['error_message'] === 'SMTP rejected', 'failed row stores the error message');

    /* ---------------------------------------------------------------
       7: claim ownership and retry protections.
    --------------------------------------------------------------- */
    section('7. claim ownership and retry protections');
    $c7 = qa_campaign($pdo, 'QA_RESULT_claims', 'sending');
    $createdCampaigns[] = $c7;
    qa_recipient($pdo, $c7, 'qa-claim@example.test', 'pending');

    $claim1 = newsletter_campaign_claim_recipient($c7, 'qa-claim@example.test');
    expect_true(is_array($claim1) && ($claim1['claim_token'] ?? '') !== '', 'first claim takes ownership');
    $claim2 = newsletter_campaign_claim_recipient($c7, 'qa-claim@example.test');
    expect_true($claim2 === null, 'second claim on the same recipient is refused (single owner)');

    $raw = $pdo->prepare("UPDATE newsletter_campaign_recipients SET status='claimed', claim_token=? WHERE campaign_id=? AND email=? AND status='pending'");
    $raw->execute([$tokenB, $c7, 'qa-claim@example.test']);
    expect_true($raw->rowCount() === 0, 'conditional claim UPDATE affects no row once already claimed');

    $draft = qa_campaign($pdo, 'QA_RESULT_campaign_claim', 'draft');
    $createdCampaigns[] = $draft;
    expect_true(newsletter_campaign_claim($draft, ['draft']) === true, 'campaign-level claim succeeds once');
    expect_true(newsletter_campaign_claim($draft, ['draft']) === false, 'second campaign-level claim is refused');

    $c8 = qa_campaign($pdo, 'QA_RESULT_reset', 'sending');
    $createdCampaigns[] = $c8;
    qa_recipient($pdo, $c8, 'qa-reset-failed@example.test', 'failed');
    qa_recipient($pdo, $c8, 'qa-reset-sent@example.test', 'sent', null, null);
    qa_recipient($pdo, $c8, 'qa-reset-claimed@example.test', 'claimed', $tokenA, date('Y-m-d H:i:s'));
    qa_recipient($pdo, $c8, 'qa-reset-unknown@example.test', 'unknown');

    newsletter_campaign_reset_failed_recipients($c8);
    expect_true((qa_fetch($pdo, $c8, 'qa-reset-failed@example.test')['status'] ?? '') === 'pending', 'retry reset moves failed -> pending');
    expect_true((qa_fetch($pdo, $c8, 'qa-reset-sent@example.test')['status'] ?? '') === 'sent', 'retry reset leaves sent untouched');
    expect_true((qa_fetch($pdo, $c8, 'qa-reset-claimed@example.test')['status'] ?? '') === 'claimed', 'retry reset leaves claimed untouched');
    expect_true((qa_fetch($pdo, $c8, 'qa-reset-unknown@example.test')['status'] ?? '') === 'unknown', 'retry reset leaves unknown untouched');
} finally {
    qa_drop_trigger($pdo, $TRIGGER);

    /* Remove only the synthetic rows created by this test. */
    if ($createdCampaigns !== []) {
        $ids = implode(',', array_map('intval', $createdCampaigns));
        $pdo->exec('DELETE FROM newsletter_campaign_recipients WHERE campaign_id IN (' . $ids . ')');
        $pdo->exec('DELETE FROM newsletter_campaigns WHERE id IN (' . $ids . ')');
    }
}

fwrite(STDOUT, "\n------------------------------------------------------------\n");
fwrite(STDOUT, "Recipient result-failure QA: $passes passed, $failures failed.\n");

if ($failures > 0) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}

exit(0);
