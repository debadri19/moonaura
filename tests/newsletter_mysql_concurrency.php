<?php
/* ===================================================================
   BEHAVIORAL TESTS: newsletter recipient claims against MySQL
   -------------------------------------------------------------------
   Run (after creating a dedicated disposable QA database yourself):

     NLQA_ALLOW_DB_MUTATION=1 php tests/newsletter_mysql_concurrency.php

   Windows PowerShell (from the extracted project root):

     $env:NLQA_ALLOW_DB_MUTATION = "1"
     $env:DB_NAME = "moonaura_qa_20261021"
     php tests\newsletter_mysql_concurrency.php

   SAFETY
     - Requires NLQA_ALLOW_DB_MUTATION=1 (exact).
     - Resolved DB_NAME must match /^moonaura_qa_[A-Za-z0-9][A-Za-z0-9_]{0,48}$/.
     - Refuses production-like names (including the project default "moonaura").
     - Never CREATE / DROP / TRUNCATE / RESET a database.
     - Never applies migrations.
     - Never calls the delivery worker, SMTP, or Brevo.
     - Inserts only synthetic NLQA rows and deletes only those rows.

   Child worker (spawned by the parent; do not invoke by hand):

     NLQA_MODE=claimer php tests/newsletter_mysql_concurrency.php
   =================================================================== */

const NLQA_DB_NAME_PATTERN = '/^moonaura_qa_[A-Za-z0-9][A-Za-z0-9_]{0,48}$/';
const NLQA_CAMPAIGN_PREFIX = 'NLQA concurrency ';
const NLQA_SENDER_EMAIL    = 'nlqa-sender@example.test';

$nlqaMode = getenv('NLQA_MODE');
$nlqaMode = is_string($nlqaMode) ? trim($nlqaMode) : '';

if ($nlqaMode === 'claimer') {
    exit(nlqa_run_claimer());
}

exit(nlqa_run_parent());

/* ===================================================================
   GUARDS
   =================================================================== */

function nlqa_refuse(string $reason): void
{
    fwrite(STDERR, "REFUSED  {$reason}\n");
    exit(2);
}

function nlqa_guard_opt_in(): void
{
    $flag = getenv('NLQA_ALLOW_DB_MUTATION');
    if ($flag !== '1') {
        nlqa_refuse('NLQA_ALLOW_DB_MUTATION=1 is required. This harness will not touch a database without that explicit opt-in.');
    }
}

function nlqa_guard_db_name(string $name): void
{
    $name = trim($name);
    if ($name === '') {
        nlqa_refuse('DB_NAME is empty after loading config. Set it to a dedicated QA database such as moonaura_qa_20261021.');
    }
    $blocked = ['moonaura', 'moonaura_crystals', 'production', 'prod', 'live', 'shop', 'store'];
    if (in_array(strtolower($name), $blocked, true)) {
        nlqa_refuse("DB_NAME '{$name}' is production-like. Use a dedicated name matching moonaura_qa_*.");
    }
    if (preg_match('/(prod|live|customer|backup|dump)/i', $name)) {
        nlqa_refuse("DB_NAME '{$name}' looks production-like. Use a dedicated name matching moonaura_qa_*.");
    }
    if (!preg_match(NLQA_DB_NAME_PATTERN, $name)) {
        nlqa_refuse("DB_NAME '{$name}' does not match the required QA pattern moonaura_qa_<id>.");
    }
}

function nlqa_boot(): PDO
{
    nlqa_guard_opt_in();

    require_once dirname(__DIR__) . '/config/config.php';

    if (!defined('DB_NAME') || !is_string(DB_NAME)) {
        nlqa_refuse('DB_NAME is not defined after loading config.');
    }
    nlqa_guard_db_name((string) DB_NAME);

    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

    try {
        $pdo = db();
    } catch (Throwable $e) {
        nlqa_refuse('Could not open a PDO connection. Check DB_HOST/DB_NAME/DB_USER on the QA instance. No credentials are printed.');
    }

    $actual = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== (string) DB_NAME) {
        nlqa_refuse("Connected database '{$actual}' does not match configured DB_NAME '" . DB_NAME . "'.");
    }
    nlqa_guard_db_name($actual);

    return $pdo;
}

function nlqa_require_claim_schema(PDO $pdo): void
{
    $engine = $pdo->query(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'newsletter_campaign_recipients'"
    )->fetchColumn();
    if ($engine === false) {
        nlqa_refuse('Table newsletter_campaign_recipients is missing. Apply migrations 13-15 to this QA database first (see tests/NEWSLETTER_RUNTIME_QA.md). This harness does not apply them.');
    }
    if (strtolower((string) $engine) !== 'innodb') {
        nlqa_refuse('newsletter_campaign_recipients ENGINE is ' . (string) $engine . ', not InnoDB. Claim atomicity is not proven on this engine.');
    }

    $cols = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM newsletter_campaign_recipients');
    foreach ($stmt->fetchAll() as $col) {
        $cols[(string) $col['Field']] = $col;
    }
    foreach (['status', 'claim_token', 'claimed_at', 'campaign_id', 'email'] as $need) {
        if (!isset($cols[$need])) {
            nlqa_refuse("Column newsletter_campaign_recipients.{$need} is missing. Apply database/migration_newsletter_recipient_claims.sql to this QA database. This harness does not apply it.");
        }
    }
    $statusType = strtolower((string) $cols['status']['Type']);
    foreach (['pending', 'claimed', 'sent', 'failed', 'unknown'] as $enumVal) {
        if (!str_contains($statusType, "'" . $enumVal . "'")) {
            nlqa_refuse("Recipient status ENUM is missing '{$enumVal}' ({$statusType}). Apply migration 15.");
        }
    }

    if (!newsletter_campaign_tables_ready()) {
        nlqa_refuse('newsletter_campaign_tables_ready() is false. Claim columns or sending tables are incomplete.');
    }
}

/* ===================================================================
   PARENT
   =================================================================== */

function nlqa_run_parent(): int
{
    $pdo = nlqa_boot();
    nlqa_require_claim_schema($pdo);

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    fwrite(STDOUT, "Newsletter recipient-claim MySQL tests\n");
    fwrite(STDOUT, str_repeat('=', 60) . "\n");
    fwrite(STDOUT, 'Database : ' . DB_NAME . "\n");
    fwrite(STDOUT, 'Host     : ' . (defined('DB_HOST') ? DB_HOST : '?') . "\n");
    fwrite(STDOUT, 'Server   : ' . $version . "\n");
    fwrite(STDOUT, "Opt-in   : NLQA_ALLOW_DB_MUTATION=1\n");
    fwrite(STDOUT, str_repeat('=', 60) . "\n");

    global $failures;
    $failures = 0;
    $blocked  = 0;
    $runId    = bin2hex(random_bytes(6));
    $created  = ['campaign_ids' => [], 'emails' => []];

    $cleanup = static function () use ($pdo, &$created): void {
        nlqa_cleanup($pdo, $created);
    };
    register_shutdown_function($cleanup);

    try {
        $campaignId = nlqa_insert_campaign($pdo, $runId, 'sending');
        $created['campaign_ids'][] = $campaignId;

        $emailA = nlqa_email($runId, 'alice');
        $emailB = nlqa_email($runId, 'bob');
        $emailC = nlqa_email($runId, 'cara');
        $emailD = nlqa_email($runId, 'drew');
        $emailE = nlqa_email($runId, 'erin');
        foreach ([$emailA, $emailB, $emailC, $emailD, $emailE] as $email) {
            nlqa_insert_recipient($pdo, $campaignId, $email, 'pending');
            $created['emails'][] = $email;
        }

        // 1 + 2. Concurrent (or sequential-blocked) claims on the same pending row.
        $concur = nlqa_two_claimers($campaignId, $emailA);
        if ($concur['blocked']) {
            $blocked++;
            fwrite(STDOUT, "BLOCKED  concurrent claim: could not spawn two independent PHP CLI workers (proc_open). Sequential proof is NOT atomicity. See two-session procedure in tests/NEWSLETTER_RUNTIME_QA.md.\n");
            $one = newsletter_campaign_claim_recipient($campaignId, $emailA);
            expect_true($one !== null, 'sequential fallback: first claim on pending succeeds');
            $two = newsletter_campaign_claim_recipient($campaignId, $emailA);
            expect_true($two === null, 'sequential fallback: second claim on already-claimed row fails');
            $tokenA = is_array($one) ? (string) $one['claim_token'] : '';
        } else {
            $wins = 0;
            $tokenA = '';
            foreach ($concur['results'] as $result) {
                if (!empty($result['claimed'])) {
                    $wins++;
                    $tokenA = (string) $result['token'];
                }
            }
            expect_true($wins === 1, 'concurrent: exactly one of two workers claimed the pending recipient');
            expect_true($tokenA !== '', 'concurrent: winning claim produced a token');
            $row = nlqa_recipient($pdo, $campaignId, $emailA);
            expect_true(($row['status'] ?? '') === 'claimed', 'concurrent: row status is claimed');
            expect_true(($row['claim_token'] ?? '') === $tokenA, 'concurrent: stored token matches the winner');
        }

        $again = newsletter_campaign_claim_recipient($campaignId, $emailA);
        expect_true($again === null, 'repeat claim on already-claimed recipient fails');

        // 3. Only the matching claim token can record a result.
        $claimB = newsletter_campaign_claim_recipient($campaignId, $emailB);
        expect_true($claimB !== null, 'token-scope: claim B succeeds');
        $tokenB = is_array($claimB) ? (string) $claimB['claim_token'] : '';
        expect_true(
            !newsletter_campaign_mark_recipient($campaignId, $emailB, 'sent', '', 'ffffffffffffffffffffffffffffffff'),
            'token-scope: a different token cannot mark sent'
        );
        $rowB = nlqa_recipient($pdo, $campaignId, $emailB);
        expect_true(($rowB['status'] ?? '') === 'claimed', 'token-scope: row stays claimed after wrong token');
        expect_true(
            newsletter_campaign_mark_recipient($campaignId, $emailB, 'sent', '', $tokenB),
            'token-scope: matching token marks sent'
        );
        $rowB = nlqa_recipient($pdo, $campaignId, $emailB);
        expect_true(($rowB['status'] ?? '') === 'sent', 'token-scope: row is sent');
        expect_true(($rowB['claim_token'] ?? null) === null, 'token-scope: claim_token cleared after mark');

        // 4. Stale owner cannot overwrite after the claim is expired / replaced.
        $claimC = newsletter_campaign_claim_recipient($campaignId, $emailC);
        expect_true($claimC !== null, 'stale-owner: claim C succeeds');
        $tokenC = is_array($claimC) ? (string) $claimC['claim_token'] : '';
        $pdo->prepare(
            'UPDATE newsletter_campaign_recipients
             SET claimed_at = ?
             WHERE campaign_id = ? AND email = ? AND status = \'claimed\''
        )->execute([
            date('Y-m-d H:i:s', time() - NEWSLETTER_RECIPIENT_CLAIM_TTL - 5),
            $campaignId,
            $emailC,
        ]);
        $expired = newsletter_campaign_expire_stale_claims($campaignId);
        expect_true($expired >= 1, 'stale-owner: expire_stale_claims affected the backdated row');
        $rowC = nlqa_recipient($pdo, $campaignId, $emailC);
        expect_true(($rowC['status'] ?? '') === 'unknown', 'stale-owner: expired claim became unknown');
        expect_true(($rowC['claim_token'] ?? null) === null, 'stale-owner: claim_token cleared on expire');
        expect_true(
            ($rowC['error_message'] ?? '') === NEWSLETTER_RECIPIENT_UNKNOWN_ERROR,
            'stale-owner: unknown error message set'
        );
        expect_true(
            !newsletter_campaign_mark_recipient($campaignId, $emailC, 'sent', '', $tokenC),
            'stale-owner: original token cannot mark sent after expire'
        );
        $rowC = nlqa_recipient($pdo, $campaignId, $emailC);
        expect_true(($rowC['status'] ?? '') === 'unknown', 'stale-owner: unknown was not overwritten to sent');

        // 5. Stale claims become unknown, never pending (already asserted above).
        expect_true(
            newsletter_campaign_claim_recipient($campaignId, $emailC) === null,
            'stale-claim: unknown is not automatically reclaimable as pending'
        );

        // 6 + 7. Retry resets failed only; sent/unknown stay put.
        nlqa_force_status($pdo, $campaignId, $emailD, 'failed', 'synthetic fail');
        $sentBefore = nlqa_recipient($pdo, $campaignId, $emailB);
        $unknownBefore = nlqa_recipient($pdo, $campaignId, $emailC);
        newsletter_campaign_reset_failed_recipients($campaignId);
        $rowD = nlqa_recipient($pdo, $campaignId, $emailD);
        expect_true(($rowD['status'] ?? '') === 'pending', 'retry: failed row reset to pending');
        expect_true(($rowD['claim_token'] ?? null) === null, 'retry: failed row claim_token cleared');
        $sentAfter = nlqa_recipient($pdo, $campaignId, $emailB);
        $unknownAfter = nlqa_recipient($pdo, $campaignId, $emailC);
        expect_true(($sentAfter['status'] ?? '') === 'sent', 'retry: sent row was not reset');
        expect_true(($unknownAfter['status'] ?? '') === 'unknown', 'retry: unknown row was not reset');
        expect_true(
            ($sentBefore['status'] ?? '') === ($sentAfter['status'] ?? ''),
            'retry: sent status identical before/after'
        );

        // 8. Failed result persistence does not record a successful delivery.
        $claimE = newsletter_campaign_claim_recipient($campaignId, $emailE);
        expect_true($claimE !== null, 'false-delivery: claim E succeeds');
        $tokenE = is_array($claimE) ? (string) $claimE['claim_token'] : '';
        expect_true(
            !newsletter_campaign_mark_recipient($campaignId, $emailE, 'sent', '', ''),
            'false-delivery: empty token is rejected (no sent write)'
        );
        expect_true(
            !newsletter_campaign_mark_recipient($campaignId, $emailE, 'sent', '', '00000000000000000000000000000000'),
            'false-delivery: wrong token is rejected'
        );
        $rowE = nlqa_recipient($pdo, $campaignId, $emailE);
        expect_true(($rowE['status'] ?? '') === 'claimed', 'false-delivery: row remains claimed, not sent');
        $counts = newsletter_campaign_recipient_counts($campaignId);
        expect_true($counts['sent'] === 1, 'false-delivery: counts.sent is only the genuinely marked row');
        expect_true($counts['claimed'] >= 1, 'false-delivery: unmatched mark left a claimed row');
        expect_true(
            newsletter_campaign_mark_recipient($campaignId, $emailE, 'failed', 'synthetic persist ok', $tokenE),
            'false-delivery: matching token can still record failed'
        );

        // 9. Campaign lock expiry cannot bypass recipient-level ownership.
        $pdo->prepare(
            'UPDATE newsletter_campaigns SET locked_at = ?, status = \'sending\' WHERE id = ?'
        )->execute([
            date('Y-m-d H:i:s', time() - NEWSLETTER_CAMPAIGN_LOCK_SECONDS - 5),
            $campaignId,
        ]);
        $inFlight = nlqa_email($runId, 'flynn');
        nlqa_insert_recipient($pdo, $campaignId, $inFlight, 'pending');
        $created['emails'][] = $inFlight;
        $claimF = newsletter_campaign_claim_recipient($campaignId, $inFlight);
        expect_true($claimF !== null, 'lock-expiry: in-flight recipient claimed');
        $tokenF = is_array($claimF) ? (string) $claimF['claim_token'] : '';
        expect_true(newsletter_campaign_lock_for_resume($campaignId), 'lock-expiry: lock_for_resume takes over an expired campaign lock');
        expect_true(
            newsletter_campaign_claim_recipient($campaignId, $inFlight) === null,
            'lock-expiry: resume lock does not let a second worker claim the in-flight recipient'
        );
        $rowF = nlqa_recipient($pdo, $campaignId, $inFlight);
        expect_true(($rowF['status'] ?? '') === 'claimed', 'lock-expiry: in-flight row stays claimed');
        expect_true(($rowF['claim_token'] ?? '') === $tokenF, 'lock-expiry: original claim_token is unchanged');
        expect_true(
            newsletter_campaign_mark_recipient($campaignId, $inFlight, 'failed', 'lock takeover still owned', $tokenF),
            'lock-expiry: original owner token can still record the result'
        );
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR  ' . $e->getMessage() . "\n");
        $failures++;
    }

    $cleanup();
    $created['campaign_ids'] = [];
    $created['emails'] = [];

    fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
    if ($blocked > 0) {
        fwrite(STDOUT, "BLOCKED: {$blocked} concurrency scenario(s) could not use two independent connections.\n");
        fwrite(STDOUT, "Sequential claim/reject ran as a fallback; that is NOT a MySQL atomicity proof.\n");
    }
    if ($failures > 0) {
        fwrite(STDERR, "{$failures} check(s) failed.\n");
        return 1;
    }
    if ($blocked > 0) {
        fwrite(STDOUT, "Remaining single-connection checks passed. Concurrent atomicity is BLOCKED.\n");
        return 2;
    }
    fwrite(STDOUT, "All newsletter recipient-claim MySQL checks passed.\n");
    return 0;
}

/* ===================================================================
   CONCURRENT WORKERS
   =================================================================== */

function nlqa_run_claimer(): int
{
    $pdo = nlqa_boot();
    $campaignId = (int) getenv('NLQA_CONCUR_CAMPAIGN_ID');
    $email = (string) getenv('NLQA_CONCUR_EMAIL');
    $ready = (string) getenv('NLQA_CONCUR_READY');
    $go    = (string) getenv('NLQA_CONCUR_GO');
    if ($campaignId <= 0 || $email === '' || $ready === '' || $go === '') {
        fwrite(STDERR, json_encode(['ok' => false, 'error' => 'missing worker env']) . "\n");
        return 2;
    }

    @file_put_contents($ready, (string) getmypid());
    $deadline = microtime(true) + 15;
    while (!is_file($go)) {
        if (microtime(true) > $deadline) {
            fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'barrier timeout']) . "\n");
            return 3;
        }
        usleep(20000);
    }

    $claim = newsletter_campaign_claim_recipient($campaignId, $email);
    fwrite(STDOUT, json_encode([
        'ok'      => true,
        'claimed' => $claim !== null,
        'token'   => is_array($claim) ? (string) $claim['claim_token'] : '',
        'email'   => $email,
        'pid'     => getmypid(),
    ]) . "\n");
    return 0;
}

function nlqa_two_claimers(int $campaignId, string $email): array
{
    if (!function_exists('proc_open')) {
        return ['blocked' => true, 'results' => []];
    }
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $tag = bin2hex(random_bytes(4));
    $ready1 = $tmp . DIRECTORY_SEPARATOR . "nlqa-{$tag}-1.ready";
    $ready2 = $tmp . DIRECTORY_SEPARATOR . "nlqa-{$tag}-2.ready";
    $go     = $tmp . DIRECTORY_SEPARATOR . "nlqa-{$tag}.go";

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }
    $baseEnv['NLQA_ALLOW_DB_MUTATION'] = '1';
    $baseEnv['NLQA_MODE'] = 'claimer';
    $baseEnv['NLQA_CONCUR_CAMPAIGN_ID'] = (string) $campaignId;
    $baseEnv['NLQA_CONCUR_EMAIL'] = $email;
    $baseEnv['NLQA_CONCUR_GO'] = $go;
    $baseEnv['BREVO_API_KEY'] = '';
    $baseEnv['BREVO_SMTP_HOST'] = '';

    $cmd = [$php, __FILE__];
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cwd = dirname(__DIR__);

    $env1 = $baseEnv;
    $env1['NLQA_CONCUR_READY'] = $ready1;
    $env2 = $baseEnv;
    $env2['NLQA_CONCUR_READY'] = $ready2;

    $p1 = @proc_open($cmd, $desc, $pipes1, $cwd, $env1);
    $p2 = @proc_open($cmd, $desc, $pipes2, $cwd, $env2);
    if (!is_resource($p1) || !is_resource($p2)) {
        return ['blocked' => true, 'results' => []];
    }

    $deadline = microtime(true) + 15;
    while (!(is_file($ready1) && is_file($ready2))) {
        if (microtime(true) > $deadline) {
            @file_put_contents($go, 'timeout');
            nlqa_drain($pipes1, $p1);
            nlqa_drain($pipes2, $p2);
            return ['blocked' => true, 'results' => []];
        }
        usleep(20000);
    }
    file_put_contents($go, 'go');

    $out1 = nlqa_drain($pipes1, $p1);
    $out2 = nlqa_drain($pipes2, $p2);
    @unlink($ready1);
    @unlink($ready2);
    @unlink($go);

    $r1 = json_decode(trim($out1), true);
    $r2 = json_decode(trim($out2), true);
    if (!is_array($r1) || !is_array($r2)) {
        return ['blocked' => true, 'results' => []];
    }

    return ['blocked' => false, 'results' => [$r1, $r2]];
}

function nlqa_drain(array $pipes, $proc): string
{
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return (string) $stdout;
}

/* ===================================================================
   FIXTURES / CLEANUP
   =================================================================== */

function nlqa_email(string $runId, string $who): string
{
    return 'nlqa-' . $runId . '-' . $who . '@example.test';
}

function nlqa_insert_campaign(PDO $pdo, string $runId, string $status): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO newsletter_campaigns
            (name, subject, sender_name, sender_email, body_html, status, started_at, locked_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        NLQA_CAMPAIGN_PREFIX . $runId,
        'NLQA concurrency subject',
        'NLQA Sender',
        NLQA_SENDER_EMAIL,
        '<p>nlqa-synthetic</p>',
        $status,
        $now,
        $now,
    ]);
    return (int) $pdo->lastInsertId();
}

function nlqa_insert_recipient(PDO $pdo, int $campaignId, string $email, string $status): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO newsletter_campaign_recipients (campaign_id, email, name, status)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$campaignId, $email, 'NLQA', $status]);
}

function nlqa_force_status(PDO $pdo, int $campaignId, string $email, string $status, string $error): void
{
    $pdo->prepare(
        'UPDATE newsletter_campaign_recipients
         SET status = ?, error_message = ?, claim_token = NULL, claimed_at = NULL, sent_at = NULL
         WHERE campaign_id = ? AND email = ?'
    )->execute([$status, $error, $campaignId, $email]);
}

function nlqa_recipient(PDO $pdo, int $campaignId, string $email): array
{
    $stmt = $pdo->prepare(
        'SELECT email, status, claim_token, claimed_at, sent_at, error_message
         FROM newsletter_campaign_recipients
         WHERE campaign_id = ? AND email = ? LIMIT 1'
    );
    $stmt->execute([$campaignId, $email]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function nlqa_cleanup(PDO $pdo, array $created): void
{
    $ids = array_values(array_unique(array_map('intval', $created['campaign_ids'] ?? [])));
    if ($ids === []) {
        return;
    }
    $keep = [];
    $check = $pdo->prepare(
        'SELECT id, name FROM newsletter_campaigns WHERE id = ? LIMIT 1'
    );
    foreach ($ids as $id) {
        $check->execute([$id]);
        $row = $check->fetch();
        if (is_array($row) && str_starts_with((string) $row['name'], NLQA_CAMPAIGN_PREFIX)) {
            $keep[] = $id;
        }
    }
    foreach ($keep as $id) {
        $pdo->prepare('DELETE FROM newsletter_campaign_recipients WHERE campaign_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM newsletter_campaign_sends WHERE campaign_id = ?')->execute([$id]);
        $pdo->prepare(
            'DELETE FROM newsletter_campaigns WHERE id = ? AND name LIKE ?'
        )->execute([$id, NLQA_CAMPAIGN_PREFIX . '%']);
    }
}

/* ===================================================================
   ASSERTIONS
   =================================================================== */

function expect_true(bool $ok, string $label): void
{
    global $failures;
    if (!isset($failures)) {
        $failures = 0;
    }
    if ($ok) {
        fwrite(STDOUT, "PASS  {$label}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  {$label}\n");
}
