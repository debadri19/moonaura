<?php
/* ===================================================================
   BEHAVIORAL TESTS: sanitizer, public HTTPS URLs, claim helpers
   -------------------------------------------------------------------
   Run: php tests/newsletter_security_fixes.php
   Does not send mail, touch MySQL, call Brevo, or make HTTP requests.
   Database concurrency cases are documented as BLOCKED without MySQL.
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
require_once dirname(__DIR__) . '/includes/newsletter-admin-functions.php';

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

function expect_contains(string $haystack, string $needle, string $label): void
{
    expect_true(str_contains($haystack, $needle), $label);
}

function expect_not_contains(string $haystack, string $needle, string $label): void
{
    expect_true(!str_contains(strtolower($haystack), strtolower($needle)), $label);
}

expect_true(newsletter_is_public_https_url('https://moonauracrystals.in/shop.php'), 'public https hostname is allowed');
expect_true(newsletter_is_public_https_url('https://8.8.8.8/shop.php'), 'public IPv4 is allowed');
expect_true(!newsletter_is_public_https_url('http://moonauracrystals.in/shop.php'), 'http URLs are rejected');
expect_true(!newsletter_is_public_https_url('https://localhost/shop.php'), 'localhost is rejected');
expect_true(!newsletter_is_public_https_url('https://app.localhost/shop.php'), '*.localhost is rejected');
expect_true(!newsletter_is_public_https_url('https://127.0.0.1/shop.php'), '127.0.0.1 is rejected');
expect_true(!newsletter_is_public_https_url('https://[::1]/shop.php'), 'IPv6 loopback is rejected');
expect_true(!newsletter_is_public_https_url('https://[::ffff:127.0.0.1]/shop.php'), 'IPv4-mapped IPv6 loopback is rejected');
expect_true(!newsletter_is_public_https_url('https://10.0.0.8/shop.php'), 'private 10/8 is rejected');
expect_true(!newsletter_is_public_https_url('https://192.168.1.10/shop.php'), 'private 192.168/16 is rejected');
expect_true(!newsletter_is_public_https_url('https://172.16.0.2/shop.php'), 'private 172.16/12 is rejected');
expect_true(!newsletter_is_public_https_url('https://169.254.1.1/shop.php'), 'link-local is rejected');
expect_true(!newsletter_is_public_https_url('https://0.0.0.0/shop.php'), 'unspecified IPv4 is rejected');
expect_true(!newsletter_is_public_https_url('https://224.0.0.1/shop.php'), 'multicast is rejected');
expect_true(!newsletter_is_public_https_url('https://231.2.3.4/shop.php'), 'multicast range 224.0.0.0/4 is rejected');
expect_true(!newsletter_is_public_https_url('https://239.255.255.255/shop.php'), 'multicast upper bound is rejected');
expect_true(!newsletter_is_public_https_url('https://240.0.0.1/shop.php'), 'reserved 240.0.0.0/4 is rejected');
expect_true(!newsletter_is_public_https_url('https://[ff02::1]/shop.php'), 'IPv6 multicast is rejected');
expect_true(!newsletter_is_public_https_url('https://[ff00::]/shop.php'), 'IPv6 multicast ff00::/8 is rejected');
expect_true(!newsletter_is_public_https_url('https://[::ffff:224.0.0.1]/shop.php'), 'IPv4-mapped IPv6 multicast is rejected');
expect_true(!newsletter_is_public_https_url('https://100.64.0.1/shop.php'), 'CGNAT 100.64/10 is rejected');
expect_true(!newsletter_is_public_https_url('https://shop.internal/path'), '.internal hostname is rejected');
expect_true(!newsletter_is_public_https_url('https://user:pass@example.com/shop.php'), 'embedded credentials are rejected');
expect_true(!newsletter_is_public_https_url('https://example.com:8080/shop.php'), 'non-443 ports are rejected');
expect_true(!newsletter_is_public_https_url('not a url'), 'malformed URLs are rejected');
expect_true(!newsletter_is_public_https_url(''), 'empty URL is rejected');
expect_true(!newsletter_is_public_https_url('javascript:alert(1)'), 'javascript: is rejected');
expect_true(!newsletter_is_public_https_url('vbscript:msgbox(1)'), 'vbscript: is rejected');
expect_true(!newsletter_is_public_https_url('data:text/html,hi'), 'data: is rejected');

$safe = newsletter_sanitize_campaign_html(
    '<h2>Hello {{first_name}}</h2><p>New pieces are in.</p><ul><li>One</li></ul><a href="https://moonauracrystals.in/shop.php" title="Shop">Shop</a>'
);
expect_contains($safe, '<h2>', 'safe heading remains');
expect_contains($safe, '<p>', 'safe paragraph remains');
expect_contains($safe, '<ul>', 'safe list remains');
expect_contains($safe, '{{first_name}}', 'personalization token remains');
expect_contains($safe, 'https://moonauracrystals.in/shop.php', 'safe https link remains');

$onerror = newsletter_sanitize_campaign_html('<img src=x onerror=alert(1)>');
expect_not_contains($onerror, 'onerror', 'src=x onerror is removed');
expect_not_contains($onerror, '<img', 'unsafe img without public https src is removed');

$packed = newsletter_sanitize_campaign_html('<img src="x"onerror="alert(1)">');
expect_not_contains($packed, 'onerror', 'packed onerror without space is removed');

$mixed = newsletter_sanitize_campaign_html('<img src="https://example.com/a.jpg" OnError="alert(1)" ONLOAD="alert(2)">');
expect_not_contains($mixed, 'onerror', 'mixed-case OnError is removed');
expect_not_contains($mixed, 'onload', 'uppercase ONLOAD is removed');
expect_contains($mixed, '<img', 'approved https image remains after event strip');

$js = newsletter_sanitize_campaign_html('<a href="javascript:alert(1)">x</a><a href="vbscript:msgbox(1)">y</a><a href="data:text/html,z">z</a>');
expect_not_contains($js, 'javascript', 'javascript: href is removed');
expect_not_contains($js, 'vbscript', 'vbscript: href is removed');
expect_not_contains($js, 'data:', 'data: href is removed');
expect_not_contains($js, '<a', 'unsafe anchors are unwrapped');

$encoded = newsletter_sanitize_campaign_html('<a href="java&#115;cript:alert(1)">x</a><a href="java&#x09;script:alert(1)">y</a>');
expect_not_contains($encoded, 'javascript', 'entity-obfuscated javascript is rejected');

$style = newsletter_sanitize_campaign_html('<p style="background:url(https://evil.test/x); @import url(https://evil.test/x.css);">Hi</p>');
expect_not_contains($style, 'style=', 'style attributes are removed');
expect_not_contains($style, 'url(', 'CSS url() is removed');
expect_not_contains($style, '@import', 'CSS import is removed');
expect_contains($style, 'Hi', 'safe text inside styled paragraph remains');

$malformed = newsletter_sanitize_campaign_html('<p>Hello<script>alert(1)</script><div><h2>Title</h2></div></p><iframe src="https://evil.test"></iframe>');
expect_not_contains($malformed, '<script', 'script tags are dropped');
expect_not_contains($malformed, 'iframe', 'iframe tags are dropped');
expect_contains($malformed, 'Hello', 'text around malformed markup remains');
expect_contains($malformed, 'Title', 'nested heading text remains');

fwrite(STDOUT, "\nDatabase concurrency tests: BLOCKED (MySQL not used by this runner).\n");
fwrite(STDOUT, "Isolated-host procedure after applying database/migration_newsletter_recipient_claims.sql:\n");
fwrite(STDOUT, "  1. Two sessions: both UPDATE ... status='claimed' WHERE status='pending' for the same email; only one rowCount=1.\n");
fwrite(STDOUT, "  2. Second worker claim on already claimed row must return rowCount=0.\n");
fwrite(STDOUT, "  3. Owner UPDATE ... claim_token=? AND status='claimed' to sent; other token must not match.\n");
fwrite(STDOUT, "  4. claimed_at older than NEWSLETTER_RECIPIENT_CLAIM_TTL becomes status=unknown, not pending.\n");
fwrite(STDOUT, "  5. Crash before SMTP: claimed row expires to unknown; do not auto-reset to pending.\n");
fwrite(STDOUT, "  6. Crash after SMTP accept before DB update: stale claimed -> unknown; do not resend.\n");
fwrite(STDOUT, "  7. Retry known failed: reset failed -> pending only; sent rows stay sent.\n");

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll newsletter security-fix checks passed.\n");
exit(0);
