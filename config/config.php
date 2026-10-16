<?php
/* ===================================================================
   CONFIGURATION
   -------------------------------------------------------------------
   This is the ONE file you edit when moving the site to a new server
   (local machine, staging, live hosting). Nothing else in the project
   should contain database credentials.

   FUTURE-READY NOTE:
   Every value below is read through env(), which checks (in order):
     1. A .env file in the project root, if one exists (gitignored)
     2. A real system environment variable
     3. The default given as the second argument to env()
   This means you can start using a .env file later (e.g. on live
   hosting) WITHOUT changing a single line below - just create the
   .env file and it will be picked up automatically.

   config.local.php (also gitignored) works the same way: if it
   exists, it runs first and can define any of the constants below
   early - the defined() checks make sure your local values always
   win over the defaults.
=================================================================== */


/* ==========================================
   01. OPTIONAL LOCAL OVERRIDE FILE
   Lets one developer use different local
   settings without touching this file or
   committing their credentials. Safe to
   ignore if you don't need it yet.
========================================== */

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}


/* ==========================================
   02. OPTIONAL .env FILE SUPPORT
   Reads simple KEY=VALUE lines from a .env
   file in the project root, if one exists.
   No external library needed for this.
========================================== */

if (!function_exists('load_env_file')) {

    function load_env_file(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {

            $line = trim($line);

            // Skip comments and malformed lines.
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }
}

load_env_file(__DIR__ . '/../.env');


/* ==========================================
   03. env() HELPER
   Reads a value from (in order): the .env
   file above, a real system environment
   variable, or the given default.
========================================== */

if (!function_exists('env')) {

    function env(string $key, $default = null)
    {
        $value = getenv($key);

        return ($value !== false) ? $value : $default;
    }

}


/* ==========================================
   04. ENVIRONMENT
   Change this to 'production' when the site
   goes live (or set ENVIRONMENT in a .env
   file / config.local.php). It controls
   whether PHP errors are shown on screen
   (dev) or hidden (live).
========================================== */

if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', env('ENVIRONMENT', 'development'));
}

if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}


/* ==========================================
   05. DATABASE SETTINGS
   Update these 4 values to match your MySQL
   setup - or set them in a .env file /
   config.local.php instead, once you have one.
========================================== */

if (!defined('DB_HOST'))    define('DB_HOST', env('DB_HOST', 'localhost'));
if (!defined('DB_NAME'))    define('DB_NAME', env('DB_NAME', 'moonaura'));
if (!defined('DB_USER'))    define('DB_USER', env('DB_USER', 'root'));
if (!defined('DB_PASS'))    define('DB_PASS', env('DB_PASS', ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));


/* ==========================================
   06. SITE SETTINGS
========================================== */

if (!defined('SITE_NAME')) {
    define('SITE_NAME', env('SITE_NAME', 'MoonAura Crystals'));
}

// Base URL of the public storefront, no trailing slash.
// Example live value: 'https://moonauracrystals.in'
if (!defined('SITE_URL')) {
    define('SITE_URL', env('SITE_URL', 'http://localhost/Moonaura'));
}

// Base URL of the admin panel, no trailing slash.
// Local default stays under SITE_URL/dashboard. Live split-host value:
// 'https://admin.moonauracrystals.in'
if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', env('ADMIN_URL', rtrim(SITE_URL, '/') . '/dashboard'));
}


/* ==========================================
   06B. EMAIL / SMTP SETTINGS (Phase 5D)
   -------------------------------------------------
   Transactional email (currently: password reset links) is sent via
   Brevo SMTP through PHPMailer (includes/mailer.php). Every value is
   environment-driven - NOTHING is hardcoded in the codebase.

   The 6 required variables:
     BREVO_SMTP_HOST       e.g. smtp-relay.brevo.com
     BREVO_SMTP_PORT       e.g. 587 (STARTTLS) or 465 (implicit TLS)
     BREVO_SMTP_USERNAME   the Brevo SMTP login (not your login email)
     BREVO_SMTP_PASSWORD   the Brevo SMTP key
     MAIL_FROM_ADDRESS     the "From" address emails are sent as
     MAIL_FROM_NAME        the "From" display name (defaults to SITE_NAME)

   Two OPTIONAL switches (defaults are safe - you normally don't need
   to set them):
     BREVO_SMTP_SECURE     'auto' (default) | 'tls' | 'ssl' | 'none'
                           'auto' picks implicit TLS for port 465 and
                           STARTTLS for 587/25, exactly like standard
                           SMTP clients do.
     MAIL_FROM_NAME        default 'MoonAura Crystals' (SITE_NAME).

   Email is considered "configured" (and sending is enabled) only when
   a host AND a From address are present - see mail_is_configured() in
   includes/mailer.php. With nothing configured the mailer fails
   closed: send_email() returns false and logs one line instead of
   throwing, so the password-reset flow keeps working either way.
========================================== */

if (!defined('BREVO_SMTP_HOST'))     define('BREVO_SMTP_HOST', env('BREVO_SMTP_HOST', ''));
if (!defined('BREVO_SMTP_PORT'))     define('BREVO_SMTP_PORT', (int) env('BREVO_SMTP_PORT', 587));
if (!defined('BREVO_SMTP_USERNAME')) define('BREVO_SMTP_USERNAME', env('BREVO_SMTP_USERNAME', ''));
if (!defined('BREVO_SMTP_PASSWORD')) define('BREVO_SMTP_PASSWORD', env('BREVO_SMTP_PASSWORD', ''));
if (!defined('BREVO_SMTP_SECURE'))   define('BREVO_SMTP_SECURE', env('BREVO_SMTP_SECURE', 'auto'));

if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', SITE_NAME));
}

// (Phase 5D Step 2) Reply-To address for transactional emails - the
// address replies actually land on (noreply senders must not be the
// reply target). Defaults to support@moonauracrystals.in; used by
// send_email() in includes/mailer.php.
if (!defined('MAIL_REPLY_TO_ADDRESS')) {
    define('MAIL_REPLY_TO_ADDRESS', env('MAIL_REPLY_TO_ADDRESS', 'support@moonauracrystals.in'));
}


/* ==========================================
   06B.2 NEWSLETTER / BREVO CONTACTS API
   -------------------------------------------------
   Homepage newsletter signup adds the address to
   the pending list via the Brevo Contacts API.
   Double opt-in mail and list moves are handled by
   a Brevo Automation (pending list → confirmed
   list). SMTP settings above are unchanged and
   still used only for transactional mail. The API
   key is env-only - never hardcoded.
========================================== */

if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', env('BREVO_API_KEY', ''));
}

if (!defined('BREVO_NEWSLETTER_PENDING_LIST_ID')) {
    define('BREVO_NEWSLETTER_PENDING_LIST_ID', (int) env('BREVO_NEWSLETTER_PENDING_LIST_ID', 7));
}

if (!defined('BREVO_NEWSLETTER_LIST_ID')) {
    define('BREVO_NEWSLETTER_LIST_ID', (int) env('BREVO_NEWSLETTER_LIST_ID', 6));
}

// HMAC secret for public newsletter unsubscribe links. Dedicated key —
// do not reuse INVOICE_TOKEN_SECRET, ADMIN_2FA_ENCRYPTION_KEY, or SITE_URL.
// Campaign sending and unsubscribe-token verification fail closed when
// this is missing or shorter than 16 characters. Generate with:
//   php -r "echo bin2hex(random_bytes(32));"
// Never commit the real value. Links signed with the previous
// SITE_URL / invoice / 2FA fallbacks will no longer verify.
if (!defined('NEWSLETTER_UNSUB_SECRET')) {
    define('NEWSLETTER_UNSUB_SECRET', env('NEWSLETTER_UNSUB_SECRET', ''));
}


/* ==========================================
   06C. SECURITY / ADMIN 2FA SETTINGS (Phase 5E)
   -------------------------------------------------
   Production-lockdown knobs + the material needed for Admin
   Two-Factor Authentication. Everything is env-driven - no secrets
   are hardcoded.

   ADMIN_2FA_ENCRYPTION_KEY
       Required to enable admin 2FA. The TOTP secret is AES-256-GCM
       encrypted before it is stored in admin_users.two_factor_secret
       (see includes/two-factor.php), so this key must be present and
       STABLE (rotating it locks everyone out of their 2FA secrets).
       Generate with:
         php -r "echo base64_encode(random_bytes(32));"
       Without it, 2FA setup fails closed - the pages refuse to
       operate rather than store a secret in plain text.

   ADMIN_SESSION_IDLE_TIMEOUT
       Minutes an admin session may stay idle before it is destroyed
       (default 30). Re-authentication is required after that.

   ADMIN_2FA_MAX_ATTEMPTS
       How many wrong OTP entries an admin gets per 2FA step (and per
       setup/disable step) before the pending session is invalidated
       and, combined with the shared login lockout, the email is
       locked. Keeps the TOTP step brute-force resistant.
========================================== */

if (!defined('ADMIN_2FA_ENCRYPTION_KEY')) {
    define('ADMIN_2FA_ENCRYPTION_KEY', env('ADMIN_2FA_ENCRYPTION_KEY', ''));
}

if (!defined('ADMIN_SESSION_IDLE_TIMEOUT')) {
    define('ADMIN_SESSION_IDLE_TIMEOUT', max(5, (int) env('ADMIN_SESSION_IDLE_TIMEOUT', 30)));
}

if (!defined('ADMIN_2FA_MAX_ATTEMPTS')) {
    define('ADMIN_2FA_MAX_ATTEMPTS', max(1, (int) env('ADMIN_2FA_MAX_ATTEMPTS', 5)));
}


/* ==========================================
   PAYMENT GATEWAY SETTINGS (Phase 3A, multi-gateway model Phase 4C)
   -------------------------------------------------
   WHICH gateways are enabled/default is NOT here - that's
   database settings (see includes/settings-functions.php
   and PaymentManager.php: 'razorpay_enabled',
   'default_payment_gateway') specifically so they can be
   changed later from an Admin Settings UI with no code change.
   These constants are Razorpay's credentials.

   Test vs. live Razorpay mode is controlled ENTIRELY by
   which key pair you put in .env - a "rzp_test_..." key
   ID means test mode, "rzp_live_..." means live. There is
   no code branch for this; RAZORPAY_MODE below is only
   used to show a "Test Mode" banner on the payment page,
   it never changes what actually happens.
========================================== */

if (!defined('RAZORPAY_KEY_ID')) {
    define('RAZORPAY_KEY_ID', env('RAZORPAY_KEY_ID', ''));
}

if (!defined('RAZORPAY_KEY_SECRET')) {
    define('RAZORPAY_KEY_SECRET', env('RAZORPAY_KEY_SECRET', ''));
}

if (!defined('RAZORPAY_WEBHOOK_SECRET')) {
    define('RAZORPAY_WEBHOOK_SECRET', env('RAZORPAY_WEBHOOK_SECRET', ''));
}

if (!defined('RAZORPAY_MODE')) {
    define('RAZORPAY_MODE', env('RAZORPAY_MODE', 'test'));
}


/* ==========================================
   GUEST INVOICE DOWNLOAD TOKEN (Phase 3B)
   -------------------------------------------------
   Signs the "Download/Print Invoice" links shown to GUEST customers
   on order-success.php (guest-invoice.php verifies them - see its
   own doc comment). Deliberately a separate secret from anything
   else in this file: it authorizes reading one specific order's
   invoice and nothing more, so it shouldn't share a key with
   anything of broader scope (a session secret, a payment credential,
   etc.) - if this one were ever compromised, the blast radius is
   "someone can build a valid link for an order number they already
   know", not anything payment- or account-related.

   MUST be set for guest invoice download to work at all - see
   generate_guest_invoice_token()/verify_guest_invoice_token() in
   includes/invoice-functions.php, which both fail closed (return
   null/false) rather than sign or verify with a blank secret. Set it
   to a long random string, e.g. generated with:
     php -r "echo bin2hex(random_bytes(32));"
========================================== */

if (!defined('INVOICE_TOKEN_SECRET')) {
    define('INVOICE_TOKEN_SECRET', env('INVOICE_TOKEN_SECRET', ''));
}


/* ==========================================
   INVOICE LOGO EMBEDDING (Phase 3B)
   -------------------------------------------------
   register_invoice_logo() (includes/invoice-functions.php) decodes
   the site's WebP logo via GD and embeds it in the invoice PDF. This
   flag existed because an earlier ERR_CONNECTION_RESET on invoice
   download was initially suspected to be a native GD/WebP crash in
   that pipeline - runtime isolation later confirmed the actual cause
   was a local download manager (IDM) intercepting the response on
   one developer's machine, unrelated to this code. Logo embedding is
   confirmed working and enabled by default; the flag is kept as a
   quick kill switch (set INVOICE_LOGO_ENABLED=false in .env) in case
   a future server's GD build ever needs the letterhead to fall back
   to its text-only form (no GD calls, no binary image embedding)
   without a code change.
========================================== */

if (!defined('INVOICE_LOGO_ENABLED')) {
    define('INVOICE_LOGO_ENABLED', env('INVOICE_LOGO_ENABLED', 'true') === 'true');
}


/* ==========================================
   06D. GOOGLE ANALYTICS 4 (STANDARD)
   -------------------------------------------------
   Storefront tracking uses the Measurement ID only.
   Admin dashboard visitor counts need the Data API
   (Property ID + service-account credentials).
   Nothing is hardcoded - set these in .env /
   config.local.php. Never store the private key
   in the database or show it in Admin UI.

     GA4_MEASUREMENT_ID     e.g. G-XXXXXXXXXX
     GA4_PROPERTY_ID        numeric GA4 property id
                            (with or without a
                            "properties/" prefix)
      GA4_CREDENTIALS_PATH   optional absolute or relative
                             path to the service-account
                             JSON file (Laragon local path
                             is fine). If unset or not
                             readable, the runtime looks
                             for account-root
                             private/moonaura-ga4.json by
                             walking up from DOCUMENT_ROOT
                             / the project root. The file
                             must stay outside the public
                             web root.
      GA4_CREDENTIALS_JSON   optional raw JSON string
                             if a file path is not used

    Tracking is enabled when GA4_MEASUREMENT_ID is set.
    The Admin Visitors card stays "Not Configured" until
    Property ID is set, then "Unavailable" if the
    credentials file cannot be read.
========================================== */

if (!defined('GA4_MEASUREMENT_ID')) {
    define('GA4_MEASUREMENT_ID', env('GA4_MEASUREMENT_ID', ''));
}

if (!defined('GA4_PROPERTY_ID')) {
    define('GA4_PROPERTY_ID', env('GA4_PROPERTY_ID', ''));
}

if (!defined('GA4_CREDENTIALS_PATH')) {
    define('GA4_CREDENTIALS_PATH', env('GA4_CREDENTIALS_PATH', ''));
}

if (!defined('GA4_CREDENTIALS_JSON')) {
    define('GA4_CREDENTIALS_JSON', env('GA4_CREDENTIALS_JSON', ''));
}


/* ==========================================
   06E. META (FACEBOOK) PIXEL
   -------------------------------------------------
   Phase 1 - infrastructure only. The Pixel stays
   completely disabled until a real numeric Pixel ID
   is supplied here. Nothing is hardcoded - set this
   in .env / config.local.php (both gitignored) or a
   system environment variable.

     META_PIXEL_ID   numeric Pixel ID from Meta Events Manager
                     (a 5-20 digit number - no value is shipped here)

   When META_PIXEL_ID is empty or not a valid numeric
   ID, no Pixel script is emitted and no PageView
   event fires - the storefront behaves exactly as it
   did before this feature existed.
========================================== */

if (!defined('META_PIXEL_ID')) {
    define('META_PIXEL_ID', env('META_PIXEL_ID', ''));
}


/* ==========================================
   06F. META CONVERSIONS API (PHASE 4)
   -------------------------------------------------
   Server-side Conversions API (CAPI) foundation.
   CAPI stays completely inactive until a real
   access token is supplied here; the browser
   Pixel (Phases 1-3) is unaffected either way.

     META_CAPI_ACCESS_TOKEN   server-side token from
                              Meta Events Manager. Empty
                              by default - no value is
                              shipped in this repository.
     META_CAPI_API_VERSION    Graph API version used
                              for the events endpoint
                              (default v21.0).

   The token is read only through env() (a .env file,
   a system environment variable, or config.local.php,
   all of which are gitignored) and is never emitted
   to HTML/JS, logged, or committed. When the token is
   empty the helper no-ops and sends no request.
========================================== */

if (!defined('META_CAPI_ACCESS_TOKEN')) {
    define('META_CAPI_ACCESS_TOKEN', env('META_CAPI_ACCESS_TOKEN', ''));
}

if (!defined('META_CAPI_API_VERSION')) {
    define('META_CAPI_API_VERSION', env('META_CAPI_API_VERSION', 'v21.0'));
}


/* ==========================================
   07. SESSION SETUP
   Starts the PHP session once, project-wide.
   Needed for admin login, customer login, and
   the cart.

   SECURITY (Phase 3 audit):
   - httponly   - JavaScript can never read the session cookie,
                  closing off a common XSS-to-session-theft path.
   - samesite   - "Lax" stops the cookie being sent on cross-site
                  requests (basic CSRF-in-depth, on top of the
                  csrf_verify() checks already used everywhere).
   - secure     - only sent over HTTPS, auto-detected below so this
                  still works correctly on local HTTP development.
========================================== */

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* ==========================================
   08. SECURITY HEADERS (Phase 5E)
   -------------------------------------------------
   Sent on every page once (guarded by a constant). Cheap, widely
   recommended protections that make the storefront and admin panel
   harder to clickjack / sniff / misuse as a referrer vector. Only
   applied when no headers have been sent yet, so it never breaks a
   redirect() or a download.
========================================== */

if (!defined('SECURITY_HEADERS_SENT') && !headers_sent()) {

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    define('SECURITY_HEADERS_SENT', true);
}
