<?php
/* ===================================================================
   ADMIN - SETTINGS
   -------------------------------------------------------------------
   Phase 4C/4D: the sidebar link was disabled ("Coming in a future
   phase" - see dashboard/includes/admin-sidebar.php) because this page
   didn't exist yet. First thing it controls: payment methods, since
   that's what checkout.php/PaymentManager/manual-upi-payment.php
   were all already built to read from the settings table (see
   includes/settings-functions.php) with no code changes needed here
   - this page is purely a form in front of set_setting() calls.

   One form, one submit - not split into separate forms per section,
   so "prevent disabling every payment method" and "default gateway
   must be one of the enabled ones" can both be validated against the
   SAME submitted state at once, matching how those two rules already
   interact in checkout.php.
=================================================================== */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-functions.php';
require_once __DIR__ . '/../includes/upload-functions.php';
require_once __DIR__ . '/../includes/payments/PaymentManager.php';

require_admin_login();

$admin      = current_admin();
$pageTitle  = 'Settings';
$activePage = 'settings';

$validSettingsPanels = ['payment', 'business', 'integrations', 'appearance'];
$requestedPanel = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['settings_panel'] ?? $_GET['panel'] ?? '')
    : (string) ($_GET['panel'] ?? '');
$activePanel = in_array($requestedPanel, $validSettingsPanels, true) ? $requestedPanel : '';

if ($activePanel === 'payment') {
    $pageTitle = 'Payment Methods';
} elseif ($activePanel === 'business') {
    $pageTitle = 'Business / GST Details';
} elseif ($activePanel === 'appearance') {
    $pageTitle = 'Appearance';
} elseif ($activePanel === 'integrations') {
    $pageTitle = 'Integrations';
    require_once __DIR__ . '/../includes/newsletter-functions.php';
    require_once __DIR__ . '/../includes/mailer.php';
    require_once __DIR__ . '/../includes/analytics-functions.php';
}

// The online gateway PaymentManager actually knows how to process a
// payment through today. 'manual_upi' and 'cod' are real, enabled-
// or-not payment methods too, but they're not PaymentManager
// gateways (no createOrder()/webhook cycle) - handled as their own
// settings below, same distinction checkout.php already makes.
$registeredGateways = PaymentManager::getRegisteredGatewayNames(); // ['razorpay']

$errors  = [];
$success = false;


/* ==========================================
   CURRENT VALUES (used for both the GET display
   and to redisplay the form if POST validation fails)
========================================== */

$enabled = [
    'manual_upi' => get_setting('manual_upi_enabled', '0') === '1',
    'cod'        => get_setting('cod_enabled', '1') === '1',
];

foreach ($registeredGateways as $gatewayName) {
    $enabled[$gatewayName] = get_setting($gatewayName . '_enabled', $gatewayName === 'razorpay' ? '1' : '0') === '1';
}

$defaultGateway  = PaymentManager::getDefaultGatewayName();
$upiId           = get_setting('upi_id', '');
$upiAccountName  = get_setting('upi_account_name', '');
$upiQrImagePath  = get_setting('upi_qr_image_path', '');
$businessState   = get_setting('business_state', '');

// Phase 5G: the Business / GST section below is now the admin UI for
// the exact settings build_invoice_pdf() reads (see get_invoice_
// business_details() in includes/invoice-functions.php) - the defaults
// here mirror that function's, so an untouched settings table and an
// untouched form look identical.
$businessName      = get_setting('business_name', 'MoonAura Crystals');
$businessTradeName = get_setting('business_trade_name', '');
$businessGstin     = get_setting('business_gstin', '');
$businessAddress   = get_setting('business_address', '');
$businessPhone     = get_setting('business_phone', '');
$businessEmail     = get_setting('business_email', '');
$businessWebsite   = get_setting('business_website', '');


/* ==========================================
   HANDLE FORM SUBMISSION
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($activePanel, ['payment', 'business'], true)) {

    csrf_verify();

    // Take whatever was submitted so the form can redisplay it if
    // there are errors, same pattern as dashboard/category-form.php.
    $enabled['manual_upi'] = isset($_POST['manual_upi_enabled']);
    $enabled['cod']        = isset($_POST['cod_enabled']);

    foreach ($registeredGateways as $gatewayName) {
        $enabled[$gatewayName] = isset($_POST[$gatewayName . '_enabled']);
    }

    $defaultGateway = trim($_POST['default_payment_gateway'] ?? '');
    $upiId          = trim($_POST['upi_id'] ?? '');
    $upiAccountName = trim($_POST['upi_account_name'] ?? '');
    $businessState  = trim($_POST['business_state'] ?? '');
    $businessName      = trim($_POST['business_name'] ?? '');
    $businessTradeName = trim($_POST['business_trade_name'] ?? '');
    $businessGstin     = strtoupper(trim($_POST['business_gstin'] ?? ''));
    $businessAddress   = trim($_POST['business_address'] ?? '');
    $businessPhone     = trim($_POST['business_phone'] ?? '');
    $businessEmail     = trim($_POST['business_email'] ?? '');
    $businessWebsite   = trim($_POST['business_website'] ?? '');

    /* ---------- Validation ---------- */

    // Rule: never let every payment method end up disabled - the
    // same last-resort guard checkout.php has at runtime, but this
    // is the actual UI-level prevention that guard's comment always
    // meant to eventually exist.
    if (!in_array(true, $enabled, true)) {
        $errors[] = 'At least one payment method must remain enabled - customers would have no way to check out otherwise.';
    }

    // Rule: default gateway must be one of the gateways being saved
    // as enabled right now (not "enabled at page-load time").
    $enabledRegisteredNow = array_filter(
        $registeredGateways,
        fn (string $name): bool => $enabled[$name]
    );

    if (empty($enabledRegisteredNow)) {
        // No online gateway enabled at all (COD/Manual UPI only is a
        // valid configuration) - there's nothing to set as default.
        $defaultGateway = '';
    } elseif (!in_array($defaultGateway, $enabledRegisteredNow, true)) {
        $errors[] = 'Default Payment Gateway must be one of the enabled online gateways.';
    }

    if ($enabled['manual_upi']) {
        if ($upiId === '') {
            $errors[] = 'UPI ID is required while Manual UPI QR Payment is enabled.';
        }

        if ($upiAccountName === '') {
            $errors[] = 'Account Name is required while Manual UPI QR Payment is enabled.';
        }
    }

    // Seller's registered state - free-text (matches checkout's free-text
    // shipping state), optional, max length guard only. Used by the
    // Phase 5F GST layer to resolve intra-state (CGST+SGST) vs
    // inter-state (IGST). While empty, orders store tax_type = NULL and
    // split conservatively as CGST+SGST (see includes/tax-functions.php).
    if (mb_strlen($businessState) > 100) {
        $errors[] = 'Business State must be 100 characters or fewer.';
    }

    // Phase 5G Business / GST fields - these are printed verbatim on
    // every invoice (see get_invoice_business_details()), so each one
    // gets a length guard plus format validation where a format
    // exists. All optional except business_name (a nameless invoice is
    // not a useful document) and, like the rest of this page, they're
    // length-capped rather than free-form-restricted.
    if ($businessName === '') {
        $errors[] = 'Business Name is required - it appears at the top of every invoice.';
    }
    if (mb_strlen($businessName) > 120) {
        $errors[] = 'Business Name must be 120 characters or fewer.';
    }
    if (mb_strlen($businessTradeName) > 120) {
        $errors[] = 'Trade Name must be 120 characters or fewer.';
    }
    if ($businessGstin !== '') {
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $businessGstin)) {
            $errors[] = 'GSTIN must be a valid 15-character GST identification number (e.g. 29ABCDE1234F1Z5).';
        }
    }
    if (mb_strlen($businessAddress) > 300) {
        $errors[] = 'Business Address must be 300 characters or fewer.';
    }
    if (mb_strlen($businessPhone) > 30) {
        $errors[] = 'Business Phone must be 30 characters or fewer.';
    }
    if ($businessEmail !== '' && !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Business Email must be a valid email address.';
    }
    if (mb_strlen($businessEmail) > 120) {
        $errors[] = 'Business Email must be 120 characters or fewer.';
    }

    // Website is optional; when provided it is normalized to an
    // absolute https:// URL (an admin typing "moonauracrystals.in"
    // gets "https://moonauracrystals.in" stored) so the invoice's
    // "Website:" line is always clickable as a full URL.
    if ($businessWebsite !== '') {
        if (!preg_match('#^https?://#i', $businessWebsite)) {
            $businessWebsite = 'https://' . $businessWebsite;
        }
        if (!filter_var($businessWebsite, FILTER_VALIDATE_URL)) {
            $errors[] = 'Business Website must be a valid URL (e.g. https://moonauracrystals.in).';
        } elseif (mb_strlen($businessWebsite) > 200) {
            $errors[] = 'Business Website must be 200 characters or fewer.';
        }
    }

    /* ---------- QR image upload (optional - keep existing if none chosen) ---------- */

    if (empty($errors) && !empty($_FILES['qr_image']) && $_FILES['qr_image']['error'] !== UPLOAD_ERR_NO_FILE) {

        $result = save_uploaded_image(
            $_FILES['qr_image'],
            __DIR__ . '/../assets/uploads/qr-codes',
            'qr'
        );

        if ($result['error'] !== null) {
            $errors[] = $result['error'];
        } else {
            $upiQrImagePath = 'assets/uploads/qr-codes/' . $result['path'];
        }
    }

    /* ---------- Save ---------- */

    if (empty($errors)) {

        set_setting('manual_upi_enabled', $enabled['manual_upi'] ? '1' : '0');
        set_setting('cod_enabled', $enabled['cod'] ? '1' : '0');

        foreach ($registeredGateways as $gatewayName) {
            set_setting($gatewayName . '_enabled', $enabled[$gatewayName] ? '1' : '0');
        }

        set_setting('default_payment_gateway', $defaultGateway);
        set_setting('upi_id', $upiId);
        set_setting('upi_account_name', $upiAccountName);
        set_setting('upi_qr_image_path', $upiQrImagePath);
        set_setting('business_state', $businessState);
        set_setting('business_name', $businessName);
        set_setting('business_trade_name', $businessTradeName);
        set_setting('business_gstin', $businessGstin);
        set_setting('business_address', $businessAddress);
        set_setting('business_phone', $businessPhone);
        set_setting('business_email', $businessEmail);
        set_setting('business_website', $businessWebsite);

        flash_set('success', 'Settings updated successfully.');
        redirect($activePanel !== '' ? 'settings.php?panel=' . $activePanel : 'settings.php');
    }
}

$successMessage = flash_get('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/admin-theme-boot.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="<?= asset_url('assets/images/icons/favicon/favicon.webp') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('assets/images/icons/favicon/apple-touch-icon.webp') ?>">
    <title><?= h($pageTitle) ?> | MoonAura Admin</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= versioned_asset('dashboard/assets/css/admin.css', 'assets/css/admin.css') ?>">
</head>
<body class="admin-body">

    <div class="admin-wrapper">

        <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

        <div class="admin-main">

            <?php include __DIR__ . '/includes/admin-header.php'; ?>

            <div class="admin-content">

                <?php if ($successMessage): ?>
                    <div class="admin-alert admin-alert-success"><?= h($successMessage) ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="admin-alert admin-alert-error">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($activePanel === ''): ?>
                <div class="admin-placeholder-grid settings-hub-grid">

                    <a href="settings.php?panel=payment" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-credit-card"></i>
                            <h3>Payment Methods</h3>
                            <p>Gateways, default gateway &amp; Manual UPI</p>
                        </div>
                    </a>

                    <a href="settings.php?panel=business" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-building"></i>
                            <h3>Business / GST Details</h3>
                            <p>Invoice business details &amp; GSTIN</p>
                        </div>
                    </a>

                    <a href="2fa-setup.php" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-shield-halved"></i>
                            <h3>Security</h3>
                            <p>Admin two-factor authentication</p>
                        </div>
                    </a>

                    <a href="invoice-designer.php" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-file-invoice"></i>
                            <h3>Invoice Designer</h3>
                            <p>Logo, watermark &amp; layout for invoice PDFs</p>
                        </div>
                    </a>

                    <a href="settings.php?panel=integrations" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-plug"></i>
                            <h3>Integrations</h3>
                            <p>Third-party service configuration status</p>
                        </div>
                    </a>

                    <a href="settings.php?panel=appearance" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-moon"></i>
                            <h3>Appearance</h3>
                            <p>Admin Light, Dark, or System theme</p>
                        </div>
                    </a>

                </div>
                <?php endif; ?>

                <?php if ($activePanel !== ''): ?>
                <div class="settings-subpage">
                <div class="admin-toolbar settings-back-row admin-toolbar-end admin-nav-toolbar">
                    <a href="settings.php" class="admin-btn-secondary">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to Settings
                    </a>
                </div>

                <?php if ($activePanel === 'integrations'): ?>
                <?php
                    $brevoApiConfigured = defined('BREVO_API_KEY') && BREVO_API_KEY !== '';
                    $brevoPendingListId = defined('BREVO_NEWSLETTER_PENDING_LIST_ID') ? (int) BREVO_NEWSLETTER_PENDING_LIST_ID : 0;
                    $brevoFinalListId = defined('BREVO_NEWSLETTER_LIST_ID') ? (int) BREVO_NEWSLETTER_LIST_ID : 0;
                    $brevoReady = function_exists('newsletter_is_configured')
                        ? newsletter_is_configured()
                        : ($brevoApiConfigured && $brevoPendingListId > 0 && $brevoFinalListId > 0);

                    $razorpayKeyId = defined('RAZORPAY_KEY_ID') ? (string) RAZORPAY_KEY_ID : '';
                    $razorpaySecretConfigured = defined('RAZORPAY_KEY_SECRET') && RAZORPAY_KEY_SECRET !== '';
                    $razorpayWebhookConfigured = defined('RAZORPAY_WEBHOOK_SECRET') && RAZORPAY_WEBHOOK_SECRET !== '';
                    $razorpayMode = defined('RAZORPAY_MODE') ? (string) RAZORPAY_MODE : '';
                    $razorpayEnabled = !empty($enabled['razorpay']);
                    $razorpayReady = PaymentManager::isGatewayConfigured('razorpay');
                    $razorpayKeyIdConfigured = $razorpayKeyId !== '';

                    $smtpHost = defined('BREVO_SMTP_HOST') ? (string) BREVO_SMTP_HOST : '';
                    $smtpPort = defined('BREVO_SMTP_PORT') ? (string) BREVO_SMTP_PORT : '';
                    $smtpSecure = defined('BREVO_SMTP_SECURE') ? (string) BREVO_SMTP_SECURE : '';
                    $smtpUserConfigured = defined('BREVO_SMTP_USERNAME') && BREVO_SMTP_USERNAME !== '';
                    $smtpPasswordConfigured = defined('BREVO_SMTP_PASSWORD') && BREVO_SMTP_PASSWORD !== '';
                    $mailFromAddress = defined('MAIL_FROM_ADDRESS') ? (string) MAIL_FROM_ADDRESS : '';
                    $mailFromName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : '';
                    $mailReplyTo = defined('MAIL_REPLY_TO_ADDRESS') ? (string) MAIL_REPLY_TO_ADDRESS : '';
                    $smtpReady = function_exists('mail_is_configured') ? mail_is_configured() : ($smtpHost !== '' && $mailFromAddress !== '');
                    $newsletterUnsubConfigured = defined('NEWSLETTER_UNSUB_SECRET') && is_string(NEWSLETTER_UNSUB_SECRET) && strlen(NEWSLETTER_UNSUB_SECRET) >= 16;

                    $whatsappNumber = '919242319596';
                    $whatsappConfigured = $whatsappNumber !== '';
                ?>

                <p class="admin-field-hint settings-integrations-intro">
                    Configuration is read from server environment variables. Secrets are never shown here and cannot be saved from this page.
                </p>

                <div class="settings-integrations-grid">

                    <div class="admin-form-card settings-integration-card">
                        <h3>Brevo</h3>
                        <p class="admin-field-hint">Newsletter contacts API: pending list, confirmation, and final list.</p>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($brevoReady): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="admin-detail-row">
                            <span>API Key</span>
                            <span><?= $brevoApiConfigured ? '••••••••' : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Pending List ID</span>
                            <span><?= $brevoPendingListId > 0 ? (int) $brevoPendingListId : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Final List ID</span>
                            <span><?= $brevoFinalListId > 0 ? (int) $brevoFinalListId : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Unsubscribe Secret</span>
                            <span><?= $newsletterUnsubConfigured ? 'Configured' : 'Not Configured' ?></span>
                        </div>
                    </div>

                    <div class="admin-form-card settings-integration-card">
                        <h3>Razorpay</h3>
                        <p class="admin-field-hint">Online checkout payments. Credentials stay in environment configuration.</p>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($razorpayReady): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Enabled</span>
                            <span><?= $razorpayEnabled ? 'Yes' : 'No' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Mode</span>
                            <span><?= $razorpayMode !== '' ? h($razorpayMode) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Key ID</span>
                            <span><?= $razorpayKeyIdConfigured ? 'Configured' : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Key Secret</span>
                            <span><?= $razorpaySecretConfigured ? '••••••••' : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Webhook Secret</span>
                            <span><?= $razorpayWebhookConfigured ? 'Configured' : 'Not Configured' ?></span>
                        </div>
                    </div>

                    <div class="admin-form-card settings-integration-card">
                        <h3>SMTP / Email</h3>
                        <p class="admin-field-hint">Transactional mail through the existing PHPMailer / Brevo SMTP relay.</p>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($smtpReady): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="admin-detail-row">
                            <span>SMTP Host</span>
                            <span><?= $smtpHost !== '' ? h($smtpHost) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Port</span>
                            <span><?= $smtpPort !== '' ? h($smtpPort) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Secure mode</span>
                            <span><?= $smtpSecure !== '' ? h($smtpSecure) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Username</span>
                            <span><?= $smtpUserConfigured ? 'Configured' : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Password</span>
                            <span><?= $smtpPasswordConfigured ? '••••••••' : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>From address</span>
                            <span><?= $mailFromAddress !== '' ? h($mailFromAddress) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>From name</span>
                            <span><?= $mailFromName !== '' ? h($mailFromName) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Reply-to</span>
                            <span><?= $mailReplyTo !== '' ? h($mailReplyTo) : 'Not Configured' ?></span>
                        </div>
                    </div>

                    <div class="admin-form-card settings-integration-card">
                        <h3>WhatsApp</h3>
                        <p class="admin-field-hint">Storefront chat destination used by the existing WhatsApp links. No API provider is configured.</p>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($whatsappConfigured): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Destination</span>
                            <span><?= $whatsappConfigured ? 'wa.me/' . h($whatsappNumber) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Provider API</span>
                            <span>Not Configured</span>
                        </div>
                    </div>

                    <div class="admin-form-card settings-integration-card">
                        <h3>Analytics</h3>
                        <p class="admin-field-hint">Google Analytics 4 Standard. Measurement ID enables storefront tracking. Reporting API credentials stay in environment configuration and are never shown here.</p>
                        <?php
                            $ga4MeasurementId = ga4_measurement_id();
                            $ga4TrackingReady = ga4_is_configured();
                            $ga4ReportingReady = ga4_reporting_is_configured();
                        ?>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($ga4TrackingReady): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Provider</span>
                            <span>Google Analytics 4</span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Measurement ID</span>
                            <span><?= $ga4TrackingReady ? h($ga4MeasurementId) : 'Not Configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Reporting API</span>
                            <span>
                                <?php if ($ga4ReportingReady): ?>
                                    <span class="admin-badge admin-badge-active">Configured</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-inactive">Not Configured</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                </div>
                <?php elseif ($activePanel === 'appearance'): ?>

                <div class="admin-form-card">
                    <h3>Admin Theme</h3>
                    <p class="invoice-designer-hint">Applies only to the Admin panel. Storefront theme is unchanged. Your choice is saved in this browser.</p>
                    <div class="admin-theme-picker" role="group" aria-label="Admin theme">
                        <button type="button" data-admin-theme-mode="light">
                            <i class="fa-solid fa-sun"></i>
                            Light
                        </button>
                        <button type="button" data-admin-theme-mode="dark">
                            <i class="fa-solid fa-moon"></i>
                            Dark
                        </button>
                        <button type="button" data-admin-theme-mode="system">
                            <i class="fa-solid fa-desktop"></i>
                            System
                        </button>
                    </div>
                </div>

                <?php else: ?>

                <div class="admin-form-card">

                    <form method="post" enctype="multipart/form-data" action="settings.php?panel=<?= h($activePanel) ?>">

                        <?= csrf_field() ?>
                        <input type="hidden" name="settings_panel" value="<?= h($activePanel) ?>">

                        <div class="settings-panel" <?= $activePanel !== 'payment' ? 'hidden' : '' ?>>
                        <h3 id="section-payment-methods">Payment Methods</h3>

                        <?php foreach ($registeredGateways as $gatewayName): ?>
                            <label class="admin-checkbox-label">
                                <input
                                    type="checkbox"
                                    name="<?= h($gatewayName) ?>_enabled"
                                    class="js-gateway-toggle"
                                    data-gateway="<?= h($gatewayName) ?>"
                                    <?= $enabled[$gatewayName] ? 'checked' : '' ?>
                                >
                                <?= h(PaymentManager::getGatewayLabel($gatewayName)) ?>
                                <?php if (!empty($enabled[$gatewayName]) && !PaymentManager::isGatewayConfigured($gatewayName)): ?>
                                    <span class="admin-gateway-config-warning"><?= h(PaymentManager::getGatewayLabel($gatewayName)) ?> is enabled but API credentials are incomplete.</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>

                        <label class="admin-checkbox-label">
                            <input
                                type="checkbox"
                                name="manual_upi_enabled"
                                id="manualUpiEnabled"
                                <?= $enabled['manual_upi'] ? 'checked' : '' ?>
                            >
                            Manual UPI QR Payment
                        </label>

                        <label class="admin-checkbox-label">
                            <input type="checkbox" name="cod_enabled" <?= $enabled['cod'] ? 'checked' : '' ?>>
                            Cash on Delivery
                        </label>

                        <h3 class="admin-form-section-title">Default Payment Gateway</h3>

                        <label for="default_payment_gateway">
                            Pre-selected at checkout
                            <span class="admin-field-hint">(only lets an admin pick among the gateways enabled above)</span>
                        </label>
                        <select id="default_payment_gateway" name="default_payment_gateway">
                            <?php foreach ($registeredGateways as $gatewayName): ?>
                                <option
                                    value="<?= h($gatewayName) ?>"
                                    class="js-default-option"
                                    data-gateway="<?= h($gatewayName) ?>"
                                    <?= !$enabled[$gatewayName] ? 'disabled' : '' ?>
                                    <?= $defaultGateway === $gatewayName ? 'selected' : '' ?>
                                >
                                    <?= h(PaymentManager::getGatewayLabel($gatewayName)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <h3 class="admin-form-section-title">Manual UPI Settings</h3>

                        <label for="upi_id">UPI ID</label>
                        <input
                            type="text"
                            id="upi_id"
                            name="upi_id"
                            value="<?= h($upiId) ?>"
                            placeholder="e.g. moonaura@upi"
                        >

                        <label for="upi_account_name">Account Name</label>
                        <input
                            type="text"
                            id="upi_account_name"
                            name="upi_account_name"
                            value="<?= h($upiAccountName) ?>"
                            placeholder="e.g. MoonAura Crystals"
                        >

                        <label for="qr_image">
                            QR Code Image
                            <span class="admin-field-hint">(JPG, PNG or WEBP, max 5&nbsp;MB - leave blank to keep the current one)</span>
                        </label>

                        <?php if ($upiQrImagePath !== ''): ?>
                            <div class="admin-current-qr">
                                <img src="<?= h(asset_url($upiQrImagePath)) ?>" alt="Current UPI QR code">
                                <span class="admin-field-hint">Current QR code</span>
                            </div>
                        <?php endif; ?>

                        <input type="file" id="qr_image" name="qr_image" accept="image/jpeg,image/png,image/webp">

                        </div>

                        <div class="settings-panel" <?= $activePanel !== 'business' ? 'hidden' : '' ?>>
                        <h3 class="admin-form-section-title" id="section-business-gst">Business / GST</h3>

                        <p class="admin-field-hint" style="margin-bottom: 12px;">
                            Printed on every generated invoice (see <code>includes/invoice-functions.php</code>).
                            All fields except Business Name are optional - leave them blank to use the built-in defaults.
                        </p>

                        <label for="business_name">Business Name</label>
                        <input
                            type="text"
                            id="business_name"
                            name="business_name"
                            value="<?= h($businessName) ?>"
                            maxlength="120"
                            placeholder="e.g. MoonAura Crystals"
                        >

                        <label for="business_trade_name">Trade Name</label>
                        <input
                            type="text"
                            id="business_trade_name"
                            name="business_trade_name"
                            value="<?= h($businessTradeName) ?>"
                            maxlength="120"
                            placeholder="e.g. MoonAura (only shown if different from the name)"
                        >

                        <label for="business_gstin">
                            GSTIN
                            <span class="admin-field-hint">(15-character format, e.g. 29ABCDE1234F1Z5)</span>
                        </label>
                        <input
                            type="text"
                            id="business_gstin"
                            name="business_gstin"
                            value="<?= h($businessGstin) ?>"
                            maxlength="15"
                            placeholder="e.g. 29ABCDE1234F1Z5"
                            style="text-transform: uppercase;"
                        >

                        <label for="business_address">Business Address</label>
                        <textarea
                            id="business_address"
                            name="business_address"
                            rows="3"
                            maxlength="300"
                            placeholder="Registered / principal place of business"
                        ><?= h($businessAddress) ?></textarea>

                        <label for="business_phone">Business Phone</label>
                        <input
                            type="text"
                            id="business_phone"
                            name="business_phone"
                            value="<?= h($businessPhone) ?>"
                            maxlength="30"
                            placeholder="e.g. +91 92423 19596"
                        >

                        <label for="business_email">Business Email</label>
                        <input
                            type="email"
                            id="business_email"
                            name="business_email"
                            value="<?= h($businessEmail) ?>"
                            maxlength="120"
                            placeholder="e.g. support@moonauracrystals.in"
                        >

                        <div class="admin-form-field">
                            <label for="business_website">
                                Business Website
                                <span class="admin-field-hint">(shown as a "Website:" line on invoices; "https://" is added automatically if omitted)</span>
                            </label>
                            <input
                                type="url"
                                id="business_website"
                                name="business_website"
                                value="<?= h($businessWebsite) ?>"
                                maxlength="200"
                                placeholder="e.g. https://moonauracrystals.in"
                            >
                        </div>

                        <div class="admin-form-field">
                            <label for="business_state">
                                Business State
                                <span class="admin-field-hint">
                                    Your registered state, used with the customer's shipping state to decide
                                    intra-state (CGST + SGST) vs inter-state (IGST) on orders. Leave blank until
                                    configured - orders then store an undetermined tax type and split GST as
                                    CGST + SGST.
                                </span>
                            </label>
                            <input
                                type="text"
                                id="business_state"
                                name="business_state"
                                value="<?= h($businessState) ?>"
                                placeholder="e.g. Karnataka"
                            >
                        </div>

                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="admin-btn-primary">Save Settings</button>
                        </div>

                    </form>

                </div>
                <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>

        </div>

    </div>

    <script>
        if (!window.location.search) {
            if (window.location.hash === '#section-payment-methods') {
                window.location.replace('settings.php?panel=payment');
            } else if (window.location.hash === '#section-business-gst') {
                window.location.replace('settings.php?panel=business');
            }
        }

        // Client-side UX only, mirroring what settings.php already
        // enforces server-side either way: an admin can't select a
        // gateway as default while its own "enabled" checkbox is
        // unchecked. Kept deliberately small - not a validation
        // framework, just keeps the dropdown in sync as checkboxes
        // are toggled before submitting.
        document.querySelectorAll('.js-gateway-toggle').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var gateway = this.dataset.gateway;
                var option  = document.querySelector('.js-default-option[data-gateway="' + gateway + '"]');

                if (!option) {
                    return;
                }

                option.disabled = !this.checked;

                if (!this.checked && option.selected) {
                    var stillEnabled = document.querySelector('.js-default-option:not(:disabled)');
                    if (stillEnabled) {
                        stillEnabled.selected = true;
                    }
                }
            });
        });
    </script>

</body>
</html>
