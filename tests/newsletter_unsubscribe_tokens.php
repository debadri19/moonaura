<?php
/* ===================================================================
   BEHAVIORAL TESTS: newsletter unsubscribe HMAC tokens
   -------------------------------------------------------------------
   Run (recommended, all scenarios):  php tests/newsletter_unsubscribe_tokens.php

   Exercises the REAL functions:
     - newsletter_unsub_secret_configured()
     - newsletter_campaign_secret()
     - newsletter_unsubscribe_token()
     - newsletter_verify_unsubscribe_token()
     - newsletter_public_base_url()
     - newsletter_unsubscribe_url()

   Config values (NEWSLETTER_UNSUB_SECRET, SITE_URL) are PHP constants,
   so each configuration is exercised in a child PHP process. The parent
   orchestrates the children and aggregates results.

   Does NOT send mail, touch MySQL, call Brevo, or make outbound requests.
   Uses ONLY a synthetic test secret. No real secret appears in this file.

   Manual single-scenario runs (if the parent cannot spawn children):
     NLQA_MODE=valid   php tests/newsletter_unsubscribe_tokens.php
     NLQA_MODE=weak    php tests/newsletter_unsubscribe_tokens.php
     NLQA_MODE=missing php tests/newsletter_unsubscribe_tokens.php
     NLQA_MODE=local   php tests/newsletter_unsubscribe_tokens.php
   =================================================================== */

/* Synthetic test values. NOT real secrets. */
const NLQA_EMAIL         = 'qa@example.test';
const NLQA_TEST_SECRET   = 'nlqa-synthetic-unsub-secret-0123456789abcdef';
const NLQA_LEGACY_INVOICE = 'nlqa-legacy-invoice-token-secret-value';
const NLQA_LEGACY_2FA     = 'nlqa-legacy-2fa-encryption-key-value';
const NLQA_PUBLIC_SITE   = 'https://moonauracrystals.in';
const NLQA_LOCAL_SITE    = 'http://localhost/Moonaura';

$nlqaMode = getenv('NLQA_MODE');
$nlqaMode = is_string($nlqaMode) ? trim($nlqaMode) : '';

if ($nlqaMode !== '') {
    exit(nlqa_run_scenario($nlqaMode));
}

nlqa_run_parent();

/* ===================================================================
   PARENT ORCHESTRATOR
   =================================================================== */

function nlqa_run_parent(): void
{
    global $failures;

    $scenarios = [
        'valid'   => [
            'NEWSLETTER_UNSUB_SECRET' => NLQA_TEST_SECRET,
            'SITE_URL'                => NLQA_PUBLIC_SITE,
        ],
        'weak'    => [
            'NEWSLETTER_UNSUB_SECRET' => 'tooshort',
            'SITE_URL'                => NLQA_PUBLIC_SITE,
        ],
        'missing' => [
            'NEWSLETTER_UNSUB_SECRET' => '',
            'SITE_URL'                => NLQA_PUBLIC_SITE,
        ],
        'local'   => [
            'NEWSLETTER_UNSUB_SECRET' => NLQA_TEST_SECRET,
            'SITE_URL'                => NLQA_LOCAL_SITE,
        ],
    ];

    fwrite(STDOUT, "Newsletter unsubscribe token behavioral tests\n");
    fwrite(STDOUT, str_repeat('=', 60) . "\n");

    $failures = 0;
    $blocked  = 0;

    foreach ($scenarios as $mode => $overrides) {
        fwrite(STDOUT, "\n--- scenario: {$mode} ---\n");
        $result = nlqa_spawn($mode, $overrides);

        if (!$result['ok']) {
            $blocked++;
            fwrite(STDOUT, "BLOCKED  could not spawn child PHP process for '{$mode}'\n");
            continue;
        }

        fwrite(STDOUT, $result['output']);

        // Child prints "FAIL" lines and exits non-zero on failure.
        if ($result['code'] !== 0) {
            $failures++;
        }
    }

    fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");

    if ($blocked > 0) {
        fwrite(STDOUT, "BLOCKED: {$blocked} scenario(s) could not run in a child process.\n");
        fwrite(STDOUT, "Run each scenario manually with NLQA_MODE set, e.g.:\n");
        fwrite(STDOUT, "  NLQA_MODE=valid php tests/newsletter_unsubscribe_tokens.php\n");
        fwrite(STDOUT, "  NLQA_MODE=weak php tests/newsletter_unsubscribe_tokens.php\n");
        fwrite(STDOUT, "  NLQA_MODE=missing php tests/newsletter_unsubscribe_tokens.php\n");
        fwrite(STDOUT, "  NLQA_MODE=local php tests/newsletter_unsubscribe_tokens.php\n");
        exit(2);
    }

    if ($failures > 0) {
        fwrite(STDERR, "{$failures} scenario(s) had failing checks.\n");
        exit(1);
    }

    fwrite(STDOUT, "All newsletter unsubscribe token checks passed.\n");
    exit(0);
}

function nlqa_spawn(string $mode, array $overrides): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'code' => null, 'output' => ''];
    }

    $php = defined('PHP_BINARY') ? PHP_BINARY : '';
    if ($php === '' || !is_file($php)) {
        $php = 'php';
    }

    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['ENVIRONMENT']       = 'development';
    $env['NLQA_MODE']         = $mode;
    $env['BREVO_API_KEY']     = '';
    $env['BREVO_SMTP_HOST']   = '';
    $env['MAIL_FROM_ADDRESS'] = '';
    foreach ($overrides as $key => $value) {
        $env[$key] = $value;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $proc = @proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => null, 'output' => ''];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [
        'ok'     => true,
        'code'   => $code,
        'output' => (string) $stdout . (string) $stderr,
    ];
}

/* ===================================================================
   CHILD SCENARIOS
   =================================================================== */

function nlqa_run_scenario(string $mode): int
{
    // Child processes receive config through their environment; the
    // parent sets it before spawning. Requiring config here defines
    // the constants from those values.
    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

    global $failures;
    $failures = 0;

    switch ($mode) {
        case 'valid':
            nlqa_scenario_valid();
            break;
        case 'weak':
        case 'missing':
            nlqa_scenario_weak_secret($mode);
            break;
        case 'local':
            nlqa_scenario_local_site();
            break;
        default:
            fwrite(STDERR, "Unknown NLQA_MODE: {$mode}\n");
            return 2;
    }

    if ($failures > 0) {
        fwrite(STDERR, "  {$failures} check(s) failed in scenario '{$mode}'.\n");
        return 1;
    }

    return 0;
}

function nlqa_sign(string $email, int $exp, string $secret): string
{
    // Mirrors the production signing scheme: HMAC-SHA256 over email|exp.
    return hash_hmac('sha256', $email . '|' . $exp, $secret);
}

function nlqa_scenario_valid(): void
{
    $email  = NLQA_EMAIL;
    $future = time() + 3600;

    expect_true(newsletter_unsub_secret_configured(), 'valid: dedicated secret is configured');
    expect_true(newsletter_campaign_secret() !== '', 'valid: campaign secret is non-empty');

    // 1. Correctly generated, unexpired token verifies.
    $token = newsletter_unsubscribe_token($email);
    expect_true(is_array($token) && isset($token['email'], $token['exp'], $token['sig']), 'valid: token has email/exp/sig');
    expect_true($token !== [] && $token['email'] === $email, 'valid: token email matches');
    expect_true(is_int($token['exp']) && $token['exp'] > time(), 'valid: token expiry is in the future');
    expect_true(
        newsletter_verify_unsubscribe_token($email, (string) $token['exp'], (string) $token['sig']),
        'valid: generated token verifies'
    );

    // 2. Changing the email invalidates the token.
    expect_true(
        !newsletter_verify_unsubscribe_token('someone-else@example.test', (string) $token['exp'], (string) $token['sig']),
        'valid: different email is rejected'
    );

    // 3. Tampering with the signature invalidates the token.
    $tamperedSig = $token['sig'];
    $lastChar    = substr($tamperedSig, -1);
    $tamperedSig = substr($tamperedSig, 0, -1) . ($lastChar === 'a' ? 'b' : 'a');
    expect_true($tamperedSig !== $token['sig'], 'valid: signature tamper actually changed the value');
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) $token['exp'], $tamperedSig),
        'valid: tampered signature is rejected'
    );

    // 4. Tampering with the expiry invalidates the token (still future).
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) ($token['exp'] + 60), (string) $token['sig']),
        'valid: tampered expiry is rejected'
    );

    // 5. An expired token is rejected (forged with the real secret, past exp).
    $secret  = newsletter_campaign_secret();
    $expired = time() - 100;
    $expiredSig = nlqa_sign($email, $expired, $secret);
    expect_true($expiredSig !== '', 'valid: expired fixture signature built');
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) $expired, $expiredSig),
        'valid: expired token is rejected'
    );

    // 6. Malformed or missing tokens are rejected.
    expect_true(!newsletter_verify_unsubscribe_token('', '', ''), 'valid: empty email/exp/sig rejected');
    expect_true(!newsletter_verify_unsubscribe_token($email, '', (string) $token['sig']), 'valid: missing exp rejected');
    expect_true(!newsletter_verify_unsubscribe_token($email, (string) $future, ''), 'valid: missing sig rejected');
    expect_true(!newsletter_verify_unsubscribe_token($email, 'not-a-number', (string) $token['sig']), 'valid: non-numeric exp rejected');
    expect_true(!newsletter_verify_unsubscribe_token($email, '-1', (string) $token['sig']), 'valid: negative exp rejected');
    expect_true(!newsletter_verify_unsubscribe_token('not-an-email', (string) $future, (string) $token['sig']), 'valid: invalid email rejected');
    expect_true(!newsletter_verify_unsubscribe_token($email, (string) $future, str_repeat('a', 64)), 'valid: plausible-length wrong sig rejected');

    // 8. Legacy signatures based on other keys are rejected.
    $legacySiteUrlKey = SITE_URL . '|newsletter-unsub';
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) $future, nlqa_sign($email, $future, $legacySiteUrlKey)),
        'valid: SITE_URL-derived legacy signature rejected'
    );
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) $future, nlqa_sign($email, $future, NLQA_LEGACY_INVOICE)),
        'valid: invoice-secret legacy signature rejected'
    );
    expect_true(
        !newsletter_verify_unsubscribe_token($email, (string) $future, nlqa_sign($email, $future, NLQA_LEGACY_2FA)),
        'valid: 2FA-key legacy signature rejected'
    );

    // 9a. A valid public SITE_URL yields a usable, verifiable unsubscribe URL.
    expect_true(newsletter_public_base_url() === NLQA_PUBLIC_SITE, 'valid: public base URL accepted');
    $url = newsletter_unsubscribe_url($email);
    expect_true($url !== '', 'valid: unsubscribe URL built for public origin');
    expect_true(str_starts_with($url, NLQA_PUBLIC_SITE . '/newsletter-unsubscribe.php?'), 'valid: URL points at the handler');
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect_true(($query['email'] ?? '') === $email, 'valid: URL carries the email');
    expect_true(ctype_digit((string) ($query['exp'] ?? '')), 'valid: URL carries numeric exp');
    expect_true(
        newsletter_verify_unsubscribe_token($email, (string) ($query['exp'] ?? ''), (string) ($query['sig'] ?? '')),
        'valid: token parsed from the URL verifies'
    );
}

function nlqa_scenario_weak_secret(string $mode): void
{
    $label  = $mode === 'weak' ? 'weak' : 'missing';
    $future = time() + 3600;

    expect_true(!newsletter_unsub_secret_configured(), "{$label}: secret is not configured");
    expect_true(newsletter_campaign_secret() === '', "{$label}: campaign secret fails closed (empty)");
    expect_true(newsletter_unsubscribe_token(NLQA_EMAIL) === [], "{$label}: no token is produced");
    expect_true(
        !newsletter_verify_unsubscribe_token(NLQA_EMAIL, (string) $future, str_repeat('a', 64)),
        "{$label}: verification rejects tokens"
    );
    expect_true(newsletter_unsubscribe_url(NLQA_EMAIL) === '', "{$label}: no unsubscribe URL is produced");
}

function nlqa_scenario_local_site(): void
{
    expect_true(newsletter_unsub_secret_configured(), 'local: secret is configured');
    expect_true(SITE_URL === NLQA_LOCAL_SITE, 'local: SITE_URL fixture is the local origin');
    expect_true(newsletter_public_base_url() === '', 'local: non-public SITE_URL yields no base URL');
    expect_true(newsletter_unsubscribe_url(NLQA_EMAIL) === '', 'local: no usable public unsubscribe URL is produced');
}

/* ===================================================================
   ASSERTION HELPERS
   =================================================================== */

function expect_true(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "PASS  {$label}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  {$label}\n");
}
