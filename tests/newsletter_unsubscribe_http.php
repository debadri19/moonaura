<?php
/* ===================================================================
   HTTP INTEGRATION: public newsletter-unsubscribe.php (loopback)
   -------------------------------------------------------------------
   Run: php tests/newsletter_unsubscribe_http.php

   Starts php -S bound to 127.0.0.1 only, against an isolated copy of
   the app that has no .env / config.local.php / real secrets.
   The HTTP client talks only to that loopback socket and does not
   fetch CSS, fonts, images, or any external URL.

   BREVO_API_KEY and BREVO_SMTP_HOST are empty, so
   newsletter_is_configured() is false and a valid POST cannot reach
   newsletter_brevo_request().

   Does not send mail, apply migrations, or use a hosted database.
   Guest cart/wishlist counts are session-only; if a request still
   opens PDO it fails closed (DB_* points at a non-database).

   Requires: PHP CLI, proc_open, php -S, loopback TCP.
   =================================================================== */

const NLQA_HTTP_EMAIL  = 'qa-unsub-http@example.test';
const NLQA_HTTP_OTHER  = 'qa-unsub-other@example.test';
const NLQA_HTTP_SECRET = 'nlqa-synthetic-unsub-secret-http-0123456789abcdef';
const NLQA_HTTP_SITE   = 'https://qa.example.test';

$failures = 0;
$server   = null;
$copyDir  = '';

$cleanup = static function () use (&$server, &$copyDir): void {
    nlqa_http_stop($server);
    nlqa_http_rmdir($copyDir);
};
register_shutdown_function($cleanup);

fwrite(STDOUT, "Newsletter public unsubscribe HTTP tests (loopback)\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n");

if (!function_exists('proc_open')) {
    fwrite(STDOUT, "BLOCKED  proc_open is unavailable; cannot start php -S\n");
    exit(2);
}

$php = defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
$root = dirname(__DIR__);
$copyDir = nlqa_http_isolate($root);
if ($copyDir === '') {
    fwrite(STDOUT, "BLOCKED  could not create an isolated app copy\n");
    exit(2);
}

$port = nlqa_http_free_port();
if ($port === 0) {
    fwrite(STDOUT, "BLOCKED  no free loopback port found\n");
    exit(2);
}

$server = nlqa_http_start($php, $copyDir, $port);
if ($server === null) {
    fwrite(STDOUT, "BLOCKED  php -S did not start on 127.0.0.1:{$port}\n");
    exit(2);
}

fwrite(STDOUT, "Server   : 127.0.0.1:{$port}\n");
fwrite(STDOUT, "Copy     : {$copyDir}\n");
fwrite(STDOUT, "Brevo    : API key empty (newsletter_is_configured must be false)\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n");

$cookies = [];
$valid = nlqa_http_token(NLQA_HTTP_EMAIL, time() + 3600);

// 1. Valid GET
$getValid = nlqa_http_request($port, 'GET', '/newsletter-unsubscribe.php?' . http_build_query([
    'email' => NLQA_HTTP_EMAIL,
    'exp'   => (string) $valid['exp'],
    'sig'   => $valid['sig'],
]), '', $cookies);
nlqa_http_merge_cookies($cookies, $getValid['set_cookies']);
expect_true($getValid['status'] === 200, 'GET valid token: HTTP 200 (got ' . $getValid['status'] . ')');
expect_true(str_contains($getValid['body'], '>Unsubscribe</h1>'), 'GET valid token: confirmation heading');
expect_true(!str_contains($getValid['body'], "You're unsubscribed"), 'GET valid token: does not claim success');
expect_true(!str_contains($getValid['body'], 'Link expired'), 'GET valid token: not the expired state');
$form = nlqa_http_parse_unsub_form($getValid['body']);
expect_true($form !== null, 'GET valid token: confirmation form is present');
expect_true(($form['email'] ?? '') === NLQA_HTTP_EMAIL, 'GET valid token: form email matches');
expect_true(($form['exp'] ?? '') === (string) $valid['exp'], 'GET valid token: form exp matches');
expect_true(($form['sig'] ?? '') === $valid['sig'], 'GET valid token: form sig matches');
expect_true(($form['csrf_token'] ?? '') !== '', 'GET valid token: CSRF token is in the form');
expect_true($cookies !== [], 'GET valid token: session cookie stored for later POSTs');

// 5. GET does not unsubscribe (no success, no Brevo-config error from POST path)
expect_true(
    !str_contains($getValid['body'], 'Brevo newsletter integration is not configured.'),
    'GET valid token: does not run the POST unsubscribe path'
);

// 2. Modified signature
$badSig = $valid['sig'];
$badSig = substr($badSig, 0, -1) . (substr($badSig, -1) === 'a' ? 'b' : 'a');
$getBadSig = nlqa_http_request($port, 'GET', '/newsletter-unsubscribe.php?' . http_build_query([
    'email' => NLQA_HTTP_EMAIL,
    'exp'   => (string) $valid['exp'],
    'sig'   => $badSig,
]), '', []);
expect_true($getBadSig['status'] === 200, 'GET modified sig: HTTP 200 (got ' . $getBadSig['status'] . ')');
expect_true(str_contains($getBadSig['body'], 'Link expired'), 'GET modified sig: expired/invalid-link state');
expect_true(!str_contains($getBadSig['body'], "You're unsubscribed"), 'GET modified sig: no success');
expect_true(nlqa_http_parse_unsub_form($getBadSig['body']) === null, 'GET modified sig: no unsubscribe form');

// 3. Expired token
$expired = nlqa_http_token(NLQA_HTTP_EMAIL, time() - 120);
$getExpired = nlqa_http_request($port, 'GET', '/newsletter-unsubscribe.php?' . http_build_query([
    'email' => NLQA_HTTP_EMAIL,
    'exp'   => (string) $expired['exp'],
    'sig'   => $expired['sig'],
]), '', []);
expect_true($getExpired['status'] === 200, 'GET expired: HTTP 200 (got ' . $getExpired['status'] . ')');
expect_true(str_contains($getExpired['body'], 'Link expired'), 'GET expired: expired-link state');
expect_true(!str_contains($getExpired['body'], "You're unsubscribed"), 'GET expired: no success');
expect_true(nlqa_http_parse_unsub_form($getExpired['body']) === null, 'GET expired: no unsubscribe form');

// 4. Token signed for a different email
$getOther = nlqa_http_request($port, 'GET', '/newsletter-unsubscribe.php?' . http_build_query([
    'email' => NLQA_HTTP_OTHER,
    'exp'   => (string) $valid['exp'],
    'sig'   => $valid['sig'],
]), '', []);
expect_true($getOther['status'] === 200, 'GET other email: HTTP 200 (got ' . $getOther['status'] . ')');
expect_true(str_contains($getOther['body'], 'Link expired'), 'GET other email: token rejected');
expect_true(nlqa_http_parse_unsub_form($getOther['body']) === null, 'GET other email: no unsubscribe form');

// 6. POST missing CSRF (no cookie / no token)
$postNoCsrf = nlqa_http_request(
    $port,
    'POST',
    '/newsletter-unsubscribe.php',
    http_build_query([
        'email' => NLQA_HTTP_EMAIL,
        'exp'   => (string) $valid['exp'],
        'sig'   => $valid['sig'],
    ]),
    []
);
expect_true($postNoCsrf['status'] === 403, 'POST missing CSRF: HTTP 403 (got ' . $postNoCsrf['status'] . ')');
expect_true(
    str_contains($postNoCsrf['body'], 'Security check failed'),
    'POST missing CSRF: security failure body'
);
expect_true(!str_contains($postNoCsrf['body'], "You're unsubscribed"), 'POST missing CSRF: no success');

// 7. POST incorrect CSRF, same session as the valid GET
$postBadCsrf = nlqa_http_request(
    $port,
    'POST',
    '/newsletter-unsubscribe.php',
    http_build_query([
        'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        'email'      => NLQA_HTTP_EMAIL,
        'exp'        => (string) $valid['exp'],
        'sig'        => $valid['sig'],
    ]),
    $cookies
);
expect_true($postBadCsrf['status'] === 403, 'POST wrong CSRF: HTTP 403 (got ' . $postBadCsrf['status'] . ')');
expect_true(
    str_contains($postBadCsrf['body'], 'Security check failed'),
    'POST wrong CSRF: security failure body'
);
expect_true(!str_contains($postBadCsrf['body'], "You're unsubscribed"), 'POST wrong CSRF: no success');

// 8. POST valid CSRF, invalid/expired signature
$postBadSig = nlqa_http_request(
    $port,
    'POST',
    '/newsletter-unsubscribe.php',
    http_build_query([
        'csrf_token' => $form['csrf_token'],
        'email'      => NLQA_HTTP_EMAIL,
        'exp'        => (string) $valid['exp'],
        'sig'        => $badSig,
    ]),
    $cookies
);
expect_true($postBadSig['status'] === 200, 'POST valid CSRF + bad sig: HTTP 200 (got ' . $postBadSig['status'] . ')');
expect_true(
    str_contains($postBadSig['body'], 'Link expired')
    || str_contains($postBadSig['body'], 'invalid or has expired'),
    'POST valid CSRF + bad sig: token rejected'
);
expect_true(!str_contains($postBadSig['body'], "You're unsubscribed"), 'POST valid CSRF + bad sig: no success');
expect_true(
    !str_contains($postBadSig['body'], 'Brevo newsletter integration is not configured.'),
    'POST valid CSRF + bad sig: does not attempt unsubscribe (no Brevo-unconfigured error)'
);

// 9. POST valid token + valid CSRF, Brevo unconfigured -> fail closed
$postValid = nlqa_http_request(
    $port,
    'POST',
    '/newsletter-unsubscribe.php',
    http_build_query([
        'csrf_token' => $form['csrf_token'],
        'email'      => $form['email'],
        'exp'        => $form['exp'],
        'sig'        => $form['sig'],
    ]),
    $cookies
);
expect_true($postValid['status'] === 200, 'POST valid + unconfigured Brevo: HTTP 200 (got ' . $postValid['status'] . ')');
expect_true(
    str_contains($postValid['body'], 'Brevo newsletter integration is not configured.'),
    'POST valid + unconfigured Brevo: user-facing configuration error'
);
expect_true(!str_contains($postValid['body'], "You're unsubscribed"), 'POST valid + unconfigured Brevo: no success');
expect_true(
    str_contains($postValid['body'], '>Unsubscribe</h1>'),
    'POST valid + unconfigured Brevo: confirmation page remains (no state change claimed)'
);

$log = nlqa_http_server_log($server);
expect_true(
    !preg_match('/api\.brevo\.com|smtp-relay\.brevo|BREVO_API_KEY=[^\\s]+./i', $log),
    'server log: no Brevo host or secret material'
);

fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
$cleanup();
$server = null;
$copyDir = '';

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed.\n");
    exit(1);
}
fwrite(STDOUT, "All public unsubscribe HTTP checks passed.\n");
fwrite(STDOUT, "Not tested: successful remote unsubscribe (requires authorized Brevo/staging).\n");
fwrite(STDOUT, "DB-state verification: BLOCKED (no isolated QA database used).\n");
exit(0);

/* ===================================================================
   ISOLATED COPY / SERVER
   =================================================================== */

function nlqa_http_isolate(string $root): string
{
    $dest = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nlqa-unsub-http-' . bin2hex(random_bytes(4));
    if (!@mkdir($dest, 0700, true)) {
        return '';
    }
    if (!nlqa_http_copy_tree($root, $dest)) {
        nlqa_http_rmdir($dest);
        return '';
    }
    if (is_file($dest . '/.env') || is_file($dest . '/config/config.local.php')) {
        nlqa_http_rmdir($dest);
        return '';
    }
    return $dest;
}

function nlqa_http_skip_copy(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '.git' || str_starts_with($rel, '.git/')) {
        return true;
    }
    if ($rel === '.env' || str_starts_with($rel, '.env.') || $rel === 'config/config.local.php') {
        return true;
    }
    if (str_starts_with($rel, 'assets/images/') || $rel === 'assets/images') {
        return true;
    }
    if (str_starts_with($rel, 'assets/uploads/') || $rel === 'assets/uploads') {
        return true;
    }
    return false;
}

function nlqa_http_copy_tree(string $src, string $dest): bool
{
    $src = rtrim($src, '/\\');
    $dest = rtrim($dest, '/\\');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        $full = $file->getPathname();
        $rel = substr($full, strlen($src) + 1);
        if ($rel === false || nlqa_http_skip_copy($rel)) {
            continue;
        }
        $target = $dest . DIRECTORY_SEPARATOR . $rel;
        if ($file->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0700, true)) {
                return false;
            }
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
            return false;
        }
        if (!@copy($full, $target)) {
            return false;
        }
    }
    return true;
}

function nlqa_http_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($sock)) {
        return 0;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name) || !str_contains($name, ':')) {
        return 0;
    }
    return (int) substr($name, strrpos($name, ':') + 1);
}

function nlqa_http_start(string $php, string $docroot, int $port): ?array
{
    $log = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nlqa-unsub-http-' . $port . '.log';
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['ENVIRONMENT'] = 'production';
    $env['NEWSLETTER_UNSUB_SECRET'] = NLQA_HTTP_SECRET;
    $env['SITE_URL'] = NLQA_HTTP_SITE;
    $env['BREVO_API_KEY'] = '';
    $env['BREVO_SMTP_HOST'] = '';
    $env['BREVO_SMTP_USERNAME'] = '';
    $env['BREVO_SMTP_PASSWORD'] = '';
    $env['MAIL_FROM_ADDRESS'] = 'nlqa-from@example.test';
    $env['MAIL_REPLY_TO_ADDRESS'] = 'nlqa-reply@example.test';
    $env['GA4_MEASUREMENT_ID'] = '';
    $env['META_PIXEL_ID'] = '';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_NAME'] = 'nlqa_http_must_not_connect';
    $env['DB_USER'] = 'nlqa_http_must_not_connect';
    $env['DB_PASS'] = '';

    $cmd = [
        $php,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $docroot,
        $docroot . DIRECTORY_SEPARATOR . 'router.php',
    ];
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];
    $proc = @proc_open($cmd, $desc, $pipes, $docroot, $env);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 8;
    while (microtime(true) < $deadline) {
        $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            return ['proc' => $proc, 'log' => $log, 'port' => $port];
        }
        usleep(50000);
    }
    proc_terminate($proc);
    proc_close($proc);
    return null;
}

function nlqa_http_stop(?array $server): void
{
    if ($server === null || !isset($server['proc']) || !is_resource($server['proc'])) {
        return;
    }
    proc_terminate($server['proc']);
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $st = proc_get_status($server['proc']);
        if (empty($st['running'])) {
            break;
        }
        usleep(50000);
    }
    proc_close($server['proc']);
}

function nlqa_http_server_log(?array $server): string
{
    if ($server === null || empty($server['log']) || !is_file($server['log'])) {
        return '';
    }
    $raw = file_get_contents($server['log']);
    return is_string($raw) ? $raw : '';
}

function nlqa_http_rmdir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if (!str_starts_with($dir, $base . DIRECTORY_SEPARATOR . 'nlqa-unsub-http-')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

/* ===================================================================
   LOOPBACK HTTP CLIENT (no external DNS / no asset fetches)
   =================================================================== */

function nlqa_http_request(int $port, string $method, string $path, string $body, array $cookies): array
{
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
    if (!is_resource($fp)) {
        return ['status' => 0, 'headers' => '', 'body' => '', 'set_cookies' => []];
    }
    stream_set_timeout($fp, 5);
    $cookieHeader = '';
    if ($cookies !== []) {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
    }
    $length = strlen($body);
    $req = $method . ' ' . $path . " HTTP/1.0\r\n"
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "Connection: close\r\n"
        . "User-Agent: NLQA-Unsubscribe-HTTP/1.0\r\n"
        . $cookieHeader;
    if ($method === 'POST') {
        $req .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $req .= 'Content-Length: ' . $length . "\r\n";
    }
    $req .= "\r\n" . $body;
    fwrite($fp, $req);
    $raw = stream_get_contents($fp);
    fclose($fp);
    $raw = is_string($raw) ? $raw : '';
    $split = strpos($raw, "\r\n\r\n");
    $headerText = $split === false ? $raw : substr($raw, 0, $split);
    $respBody = $split === false ? '' : substr($raw, $split + 4);
    $status = 0;
    if (preg_match('#^HTTP/\\d\\.\\d\\s+(\\d{3})#', $headerText, $m)) {
        $status = (int) $m[1];
    }
    $set = [];
    foreach (preg_split("/\r\n/", $headerText) ?: [] as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($line, strlen('Set-Cookie:')));
            $pair = explode(';', $cookie, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $set[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
    }
    return ['status' => $status, 'headers' => $headerText, 'body' => $respBody, 'set_cookies' => $set];
}

function nlqa_http_merge_cookies(array &$cookies, array $set): void
{
    foreach ($set as $name => $value) {
        $cookies[$name] = $value;
    }
}

function nlqa_http_token(string $email, int $exp): array
{
    $email = strtolower(trim($email));
    return [
        'email' => $email,
        'exp'   => $exp,
        'sig'   => hash_hmac('sha256', $email . '|' . $exp, NLQA_HTTP_SECRET),
    ];
}

function nlqa_http_parse_unsub_form(string $html): ?array
{
    if (!preg_match('#<form[^>]*action="newsletter-unsubscribe\\.php"[^>]*>(.*?)</form>#si', $html, $block)) {
        return null;
    }
    $inner = $block[1];
    $fields = [];
    if (preg_match_all('#<input\\b[^>]*>#i', $inner, $inputs)) {
        foreach ($inputs[0] as $tag) {
            $name = nlqa_http_attr($tag, 'name');
            $value = nlqa_http_attr($tag, 'value');
            if ($name !== '') {
                $fields[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }
    if (!isset($fields['email'], $fields['exp'], $fields['sig'], $fields['csrf_token'])) {
        return null;
    }
    return $fields;
}

function nlqa_http_attr(string $tag, string $attr): string
{
    if (preg_match('/' . preg_quote($attr, '/') . '\\s*=\\s*"([^"]*)"/i', $tag, $m)) {
        return $m[1];
    }
    if (preg_match('/' . preg_quote($attr, '/') . "\\s*=\\s*'([^']*)'/i", $tag, $m)) {
        return $m[1];
    }
    return '';
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
