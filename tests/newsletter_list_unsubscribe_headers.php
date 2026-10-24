<?php
/* ===================================================================
   BEHAVIORAL TESTS: newsletter_list_unsubscribe_headers()
   -------------------------------------------------------------------
   Run: php tests/newsletter_list_unsubscribe_headers.php

   Exercises the REAL helper with synthetic config only. Does not
   send mail, open SMTP, call Brevo, or make outbound requests.
   This verifies header construction only — not mailbox delivery.

   Config values are PHP constants, so each SITE_URL/secret variant
   runs in a child process (same pattern as the token suite).

   Manual single-scenario runs:
     NLQA_HDR_MODE=public php tests/newsletter_list_unsubscribe_headers.php
     NLQA_HDR_MODE=local  php tests/newsletter_list_unsubscribe_headers.php
     NLQA_HDR_MODE=weak   php tests/newsletter_list_unsubscribe_headers.php
   =================================================================== */

const NLQA_HDR_EMAIL   = 'qa-headers@example.test';
const NLQA_HDR_SECRET  = 'nlqa-synthetic-unsub-secret-headers-0123456789ab';
const NLQA_HDR_PUBLIC  = 'https://qa.example.test';
const NLQA_HDR_LOCAL   = 'http://localhost/Moonaura';
const NLQA_HDR_REPLY   = 'nlqa-reply@example.test';
const NLQA_HDR_FROM    = 'nlqa-from@example.test';

$mode = getenv('NLQA_HDR_MODE');
$mode = is_string($mode) ? trim($mode) : '';
if ($mode !== '') {
    exit(nlqa_hdr_run_scenario($mode));
}

nlqa_hdr_run_parent();

function nlqa_hdr_run_parent(): void
{
    $scenarios = [
        'public' => [
            'NEWSLETTER_UNSUB_SECRET' => NLQA_HDR_SECRET,
            'SITE_URL'                => NLQA_HDR_PUBLIC,
            'MAIL_REPLY_TO_ADDRESS'   => NLQA_HDR_REPLY,
            'MAIL_FROM_ADDRESS'       => NLQA_HDR_FROM,
        ],
        'local'  => [
            'NEWSLETTER_UNSUB_SECRET' => NLQA_HDR_SECRET,
            'SITE_URL'                => NLQA_HDR_LOCAL,
            'MAIL_REPLY_TO_ADDRESS'   => NLQA_HDR_REPLY,
            'MAIL_FROM_ADDRESS'       => NLQA_HDR_FROM,
        ],
        'weak'   => [
            'NEWSLETTER_UNSUB_SECRET' => 'tooshort',
            'SITE_URL'                => NLQA_HDR_PUBLIC,
            'MAIL_REPLY_TO_ADDRESS'   => NLQA_HDR_REPLY,
            'MAIL_FROM_ADDRESS'       => NLQA_HDR_FROM,
        ],
    ];

    fwrite(STDOUT, "Newsletter List-Unsubscribe header construction tests\n");
    fwrite(STDOUT, str_repeat('=', 60) . "\n");
    fwrite(STDOUT, "Scope: helper-level only. No SMTP delivery is claimed.\n");

    $failures = 0;
    $blocked  = 0;

    foreach ($scenarios as $name => $overrides) {
        fwrite(STDOUT, "\n--- scenario: {$name} ---\n");
        $result = nlqa_hdr_spawn($name, $overrides);
        if (!$result['ok']) {
            $blocked++;
            fwrite(STDOUT, "BLOCKED  could not spawn child PHP process for '{$name}'\n");
            continue;
        }
        fwrite(STDOUT, $result['output']);
        if ($result['code'] !== 0) {
            $failures++;
        }
    }

    fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
    if ($blocked > 0) {
        fwrite(STDOUT, "BLOCKED: {$blocked} scenario(s) could not run in a child process.\n");
        fwrite(STDOUT, "Run each with NLQA_HDR_MODE=public|local|weak\n");
        exit(2);
    }
    if ($failures > 0) {
        fwrite(STDERR, "{$failures} scenario(s) had failing checks.\n");
        exit(1);
    }
    fwrite(STDOUT, "All List-Unsubscribe header construction checks passed.\n");
    fwrite(STDOUT, "Not tested: SMTP actually attaching this header to a mailbox.\n");
    exit(0);
}

function nlqa_hdr_spawn(string $mode, array $overrides): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'code' => null, 'output' => ''];
    }
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['ENVIRONMENT']       = 'development';
    $env['NLQA_HDR_MODE']     = $mode;
    $env['BREVO_API_KEY']     = '';
    $env['BREVO_SMTP_HOST']   = '';
    foreach ($overrides as $key => $value) {
        $env[$key] = $value;
    }
    $cmd = [$php, __FILE__];
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
    return ['ok' => true, 'code' => $code, 'output' => (string) $stdout . (string) $stderr];
}

function nlqa_hdr_run_scenario(string $mode): int
{
    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/newsletter-campaign-functions.php';

    global $failures;
    $failures = 0;

    switch ($mode) {
        case 'public':
            nlqa_hdr_public();
            break;
        case 'local':
            nlqa_hdr_local();
            break;
        case 'weak':
            nlqa_hdr_weak();
            break;
        default:
            fwrite(STDERR, "Unknown NLQA_HDR_MODE: {$mode}\n");
            return 2;
    }

    if ($failures > 0) {
        fwrite(STDERR, "  {$failures} check(s) failed in scenario '{$mode}'.\n");
        return 1;
    }
    return 0;
}

function nlqa_hdr_public(): void
{
    $testHeaders = newsletter_list_unsubscribe_headers(NLQA_HDR_EMAIL, true);
    expect_true($testHeaders === [], 'public: test-email mode returns an empty header array');

    $headers = newsletter_list_unsubscribe_headers(NLQA_HDR_EMAIL, false);
    expect_true(isset($headers['List-Unsubscribe']), 'public: campaign mode sets List-Unsubscribe');
    expect_true(!isset($headers['List-Unsubscribe-Post']), 'public: List-Unsubscribe-Post is not emitted');
    $value = (string) ($headers['List-Unsubscribe'] ?? '');
    expect_true($value !== '', 'public: List-Unsubscribe is non-empty');

    expect_true(
        (bool) preg_match('#<https://qa\.example\.test/newsletter-unsubscribe\.php\?[^>]+>#', $value),
        'public: HTTPS unsubscribe URL is present'
    );
    expect_true(
        (bool) preg_match('#<mailto:nlqa-reply@example\.test\?subject=unsubscribe>#', $value),
        'public: mailto target uses the synthetic reply-to address'
    );

    $httpsPart = '';
    if (preg_match('#<(https://[^>]+)>#', $value, $m)) {
        $httpsPart = $m[1];
    }
    expect_true($httpsPart !== '', 'public: captured the HTTPS URL');
    $query = [];
    parse_str((string) parse_url($httpsPart, PHP_URL_QUERY), $query);
    expect_true(($query['email'] ?? '') === NLQA_HDR_EMAIL, 'public: URL carries the email');
    expect_true(ctype_digit((string) ($query['exp'] ?? '')), 'public: URL carries numeric exp');
    expect_true(
        newsletter_verify_unsubscribe_token(
            NLQA_HDR_EMAIL,
            (string) ($query['exp'] ?? ''),
            (string) ($query['sig'] ?? '')
        ),
        'public: URL signature verifies'
    );
    expect_true(
        newsletter_is_public_https_url($httpsPart),
        'public: constructed URL passes the public-https policy'
    );
}

function nlqa_hdr_local(): void
{
    expect_true(newsletter_public_base_url() === '', 'local: non-public SITE_URL yields no base URL');
    expect_true(newsletter_unsubscribe_url(NLQA_HDR_EMAIL) === '', 'local: no usable unsubscribe URL');

    $headers = newsletter_list_unsubscribe_headers(NLQA_HDR_EMAIL, false);
    $value = (string) ($headers['List-Unsubscribe'] ?? '');
    expect_true(!str_contains($value, 'newsletter-unsubscribe.php'), 'local: header has no unsubscribe HTTPS URL');
    expect_true(!preg_match('#<https://#', $value), 'local: header has no https:// target');
    expect_true(!isset($headers['List-Unsubscribe-Post']), 'local: List-Unsubscribe-Post is not emitted');
    expect_true(
        str_contains($value, '<mailto:nlqa-reply@example.test?subject=unsubscribe>'),
        'local: mailto-only header is still allowed'
    );
}

function nlqa_hdr_weak(): void
{
    expect_true(!newsletter_unsub_secret_configured(), 'weak: secret fails closed');
    expect_true(newsletter_unsubscribe_url(NLQA_HDR_EMAIL) === '', 'weak: no unsubscribe URL');
    $headers = newsletter_list_unsubscribe_headers(NLQA_HDR_EMAIL, false);
    $value = (string) ($headers['List-Unsubscribe'] ?? '');
    expect_true(!str_contains($value, 'newsletter-unsubscribe.php'), 'weak: no HTTPS unsubscribe URL without a secret');
    expect_true(!isset($headers['List-Unsubscribe-Post']), 'weak: List-Unsubscribe-Post is not emitted');
}

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
