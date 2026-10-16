<?php
/* ===================================================================
   ADMIN - NEWSLETTER CAMPAIGN COMPOSER (Phase 2 + Phase 3)
   -------------------------------------------------------------------
   Draft CRUD plus test email, send now, and schedule. Recipients,
   status, and schedule are validated server-side. Saving a draft
   never sends.
=================================================================== */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/newsletter-campaign-functions.php';

require_admin_login();

$admin      = current_admin();
$activePage = 'newsletter';
$sender     = newsletter_admin_configured_sender();

$campaignId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEditing  = $campaignId > 0;

$errors = [];
$campaign = [
    'name'         => '',
    'subject'      => '',
    'preview_text' => '',
    'reply_to'     => $sender['reply'],
    'body_html'    => '<h2>Hello {{first_name}},</h2><p>Share your latest collection with confirmed subscribers.</p>',
    'cta_text'     => 'Shop Now',
    'cta_url'      => newsletter_public_base_url() !== '' ? newsletter_public_base_url() . '/shop.php' : '',
    'status'       => 'draft',
];

if ($isEditing) {
    $found = newsletter_admin_get_campaign($campaignId);
    if (!$found) {
        flash_set('error', 'Campaign not found.');
        redirect('newsletter.php');
    }
    $campaign = array_merge($campaign, $found);
}

$status = (string) ($campaign['status'] ?? 'draft');
$canEdit = in_array($status, ['draft', 'failed', 'cancelled'], true);
$pageTitle = $isEditing
    ? ($canEdit ? 'Edit Campaign' : 'View Campaign')
    : 'Create Campaign';

$sendReady = newsletter_campaign_tables_ready();
$unsubReady = newsletter_unsub_secret_configured();
$publicOrigin = newsletter_public_base_url() !== '';
$recipientCount = $sendReady ? count(newsletter_eligible_recipients()) : 0;
$sendStats = $isEditing ? newsletter_campaign_send_stats($campaignId) : [
    'recipients' => 0,
    'pending'    => 0,
    'accepted'   => 0,
    'failed'     => 0,
    'failures'   => [],
    'logs'       => [],
];
$testEmailDefault = normalize_email((string) ($admin['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = (string) ($_POST['campaign_action'] ?? 'save');

    if (!$isEditing && $action !== 'save') {
        flash_set('error', 'Save the campaign draft before sending or scheduling.');
        redirect('newsletter-campaign-form.php');
    }

    if ($action === 'cancel_schedule') {
        $result = newsletter_campaign_cancel_schedule($campaignId);
        flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Scheduled send cancelled.' : $result['error']);
        redirect('newsletter-campaign-form.php?id=' . $campaignId);
    }

    if (!$canEdit && $action !== 'cancel_schedule') {
        flash_set('error', 'This campaign can no longer be edited or sent again.');
        redirect('newsletter-campaign-form.php?id=' . $campaignId);
    }

    $campaign['name']         = trim((string) ($_POST['name'] ?? ''));
    $campaign['subject']      = trim((string) ($_POST['subject'] ?? ''));
    $campaign['preview_text'] = trim((string) ($_POST['preview_text'] ?? ''));
    $campaign['reply_to']     = trim((string) ($_POST['reply_to'] ?? ''));
    $campaign['body_html']    = (string) ($_POST['body_html'] ?? '');
    $campaign['cta_text']     = trim((string) ($_POST['cta_text'] ?? ''));
    $campaign['cta_url']      = trim((string) ($_POST['cta_url'] ?? ''));

    $saved = newsletter_admin_save_campaign($campaign, $isEditing ? $campaignId : null);
    if (!$saved['ok']) {
        $errors[] = $saved['error'] !== '' ? $saved['error'] : 'Could not save the campaign draft.';
    } else {
        $campaignId = (int) $saved['id'];
        $isEditing = true;
        $fresh = newsletter_admin_get_campaign($campaignId);
        if ($fresh) {
            $campaign = array_merge($campaign, $fresh);
        }

        if ($action === 'save') {
            $savedStatus = (string) ($campaign['status'] ?? 'draft');
            $flash = $savedStatus === 'draft'
                ? 'Campaign draft saved. No emails were sent.'
                : 'Campaign saved. Status remains ' . $savedStatus . ' and was not queued for sending.';
            flash_set('success', $flash);
            redirect('newsletter-campaign-form.php?id=' . $campaignId);
        }

        if ($action === 'send_test') {
            $testEmail = normalize_email((string) ($_POST['test_email'] ?? $testEmailDefault));
            $result = newsletter_campaign_send_test($campaignId, $testEmail);
            flash_set(
                $result['ok'] ? 'success' : 'error',
                $result['ok'] ? 'Test email sent to ' . $testEmail . '. Confirmed subscribers were not contacted.' : $result['error']
            );
            redirect('newsletter-campaign-form.php?id=' . $campaignId);
        }

        if ($action === 'send_now') {
            $result = newsletter_campaign_start_send($campaignId, ['draft', 'failed', 'cancelled']);
            if (!empty($result['queued'])) {
                flash_set('success', 'Campaign sending started (' . (int) $result['sent'] . ' sent so far). Remaining recipients will be delivered by the cron worker.');
            } elseif ($result['ok']) {
                flash_set('success', 'Campaign sent to ' . (int) $result['sent'] . ' confirmed subscriber' . ((int) $result['sent'] === 1 ? '' : 's') . '.');
            } else {
                flash_set('error', $result['error'] !== '' ? $result['error'] : 'Campaign could not be sent.');
            }
            redirect('newsletter-campaign-form.php?id=' . $campaignId);
        }

        if ($action === 'schedule') {
            $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
            $result = newsletter_campaign_schedule($campaignId, $scheduledAt);
            if ($result['ok']) {
                flash_set('success', 'Campaign scheduled for ' . newsletter_admin_format_datetime($result['scheduled_at']) . '.');
            } else {
                flash_set('error', $result['error'] !== '' ? $result['error'] : 'Could not schedule this campaign.');
            }
            redirect('newsletter-campaign-form.php?id=' . $campaignId);
        }

        $errors[] = 'Unknown campaign action.';
    }
}

$successMessage = flash_get('success');
$errorMessage   = flash_get('error');
$readonly = $canEdit ? '' : 'readonly';
$minSchedule = date('Y-m-d\TH:i', time() + 300);
$scheduleValue = '';
if (!empty($campaign['scheduled_at'])) {
    $stamp = strtotime((string) $campaign['scheduled_at']);
    if ($stamp !== false) {
        $scheduleValue = date('Y-m-d\TH:i', $stamp);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/admin-theme-boot.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/webp" href="<?= asset_url('assets/images/icons/favicon/favicon.webp') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('assets/images/icons/favicon/apple-touch-icon.webp') ?>">
    <title><?= h($pageTitle) ?> | MoonAura Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
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

                <?php if ($errorMessage): ?>
                    <div class="admin-alert admin-alert-error"><?= h($errorMessage) ?></div>
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

                <?php if (!$sendReady): ?>
                    <div class="admin-alert admin-alert-warning">
                        Apply database/migration_newsletter_campaign_sending.sql before sending or scheduling campaigns.
                    </div>
                <?php endif; ?>

                <?php if (!$unsubReady): ?>
                    <div class="admin-alert admin-alert-warning">
                        NEWSLETTER_UNSUB_SECRET is not configured. Test, send, and schedule stay blocked until a dedicated secret is set.
                    </div>
                <?php elseif (!$publicOrigin): ?>
                    <div class="admin-alert admin-alert-warning">
                        SITE_URL must be a public https origin before this campaign can be sent.
                    </div>
                <?php endif; ?>

                <div class="admin-toolbar admin-toolbar-end admin-product-form-toolbar">
                    <a href="newsletter.php" class="admin-btn-secondary">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to Newsletter
                    </a>
                    <?php if ($canEdit): ?>
                        <button type="submit" form="newsletter-campaign-form" name="campaign_action" value="save" class="admin-btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Draft
                        </button>
                    <?php endif; ?>
                </div>

                <form
                    method="post"
                    action="newsletter-campaign-form.php<?= $isEditing ? ('?id=' . (int) $campaignId) : '' ?>"
                    id="newsletter-campaign-form"
                    class="newsletter-campaign-layout"
                >
                    <?= csrf_field() ?>

                    <div class="admin-form-card">
                        <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Campaign</h3>

                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <span class="admin-badge <?= h(newsletter_campaign_badge_class($status)) ?>">
                                    <?= h(ucfirst($status)) ?>
                                </span>
                            </span>
                        </div>
                        <?php if (in_array($status, ['failed', 'cancelled'], true)): ?>
                            <p class="admin-field-hint">Saving content keeps this campaign <?= h($status) ?>. It is not queued until you click Send Now or Schedule.</p>
                        <?php endif; ?>
                        <div class="admin-detail-row">
                            <span>Confirmed recipients</span>
                            <span><?= (int) $recipientCount ?></span>
                        </div>
                        <?php if (!empty($campaign['scheduled_at'])): ?>
                            <div class="admin-detail-row">
                                <span>Scheduled</span>
                                <span><?= h(newsletter_admin_format_datetime((string) $campaign['scheduled_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($campaign['started_at'])): ?>
                            <div class="admin-detail-row">
                                <span>Started</span>
                                <span><?= h(newsletter_admin_format_datetime((string) $campaign['started_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($campaign['completed_at'])): ?>
                            <div class="admin-detail-row">
                                <span>Completed</span>
                                <span><?= h(newsletter_admin_format_datetime((string) $campaign['completed_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($sendStats['recipients'] > 0 || in_array($status, ['sent', 'failed', 'sending'], true)): ?>
                            <div class="admin-detail-row">
                                <span>Accepted sends</span>
                                <span><?= (int) $sendStats['accepted'] ?> / <?= (int) $sendStats['recipients'] ?></span>
                            </div>
                            <div class="admin-detail-row">
                                <span>Failed recipient sends</span>
                                <span><?= (int) $sendStats['failed'] ?></span>
                            </div>
                            <?php if ($sendStats['pending'] > 0): ?>
                                <div class="admin-detail-row">
                                    <span>Pending</span>
                                    <span><?= (int) $sendStats['pending'] ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="admin-detail-row">
                            <span>Opens / clicks / bounces</span>
                            <span>Tracking not configured</span>
                        </div>
                        <?php if (!empty($campaign['failure_reason'])): ?>
                            <p class="admin-field-hint"><?= h((string) $campaign['failure_reason']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($sendStats['failures'])): ?>
                            <p class="admin-field-hint">Failure reasons are grouped. Individual recipient emails are not listed.</p>
                            <?php foreach ($sendStats['failures'] as $failure): ?>
                                <div class="admin-detail-row">
                                    <span><?= h($failure['reason']) ?></span>
                                    <span><?= (int) $failure['count'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <label for="campaign-name">Internal name</label>
                        <input type="text" id="campaign-name" name="name" maxlength="160" value="<?= h((string) $campaign['name']) ?>" required <?= $readonly ?>>
                        <p class="admin-field-hint">Admin only. Example: October Crystal Collection.</p>

                        <label for="campaign-subject">Subject</label>
                        <input type="text" id="campaign-subject" name="subject" maxlength="200" value="<?= h((string) $campaign['subject']) ?>" <?= $readonly ?>>
                        <p class="admin-field-hint">Required before sending. Aim for a clear subject under 80 characters.</p>

                        <label for="campaign-preview">Preview text</label>
                        <input type="text" id="campaign-preview" name="preview_text" maxlength="255" value="<?= h((string) ($campaign['preview_text'] ?? '')) ?>" <?= $readonly ?>>

                        <label for="campaign-sender">Sender</label>
                        <input
                            type="text"
                            id="campaign-sender"
                            value="<?= h(trim($sender['name'] . ' <' . $sender['email'] . '>')) ?>"
                            readonly
                        >
                        <p class="admin-field-hint">Uses the configured store sender. Arbitrary From addresses are not allowed.</p>

                        <label for="campaign-reply">Reply-To</label>
                        <input type="email" id="campaign-reply" name="reply_to" value="<?= h((string) $campaign['reply_to']) ?>" <?= $readonly ?>>

                        <label for="campaign-cta-text">CTA button text</label>
                        <input type="text" id="campaign-cta-text" name="cta_text" maxlength="120" value="<?= h((string) ($campaign['cta_text'] ?? '')) ?>" <?= $readonly ?>>

                        <label for="campaign-cta-url">CTA destination URL</label>
                        <input type="url" id="campaign-cta-url" name="cta_url" value="<?= h((string) ($campaign['cta_url'] ?? '')) ?>" placeholder="https://" <?= $readonly ?>>
                    </div>

                    <div class="admin-form-card newsletter-composer-card">
                        <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Email content</h3>
                        <p class="admin-field-hint">Use {{first_name}} for personalization. Missing names fall back to “there”. Every campaign email includes a signed unsubscribe link.</p>

                        <?php if ($canEdit): ?>
                            <div class="newsletter-editor-toolbar" role="toolbar" aria-label="Email formatting">
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="formatBlock" data-editor-value="h2">H2</button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="formatBlock" data-editor-value="h3">H3</button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="formatBlock" data-editor-value="p">P</button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="bold"><i class="fa-solid fa-bold"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="italic"><i class="fa-solid fa-italic"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="insertUnorderedList"><i class="fa-solid fa-list-ul"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="insertOrderedList"><i class="fa-solid fa-list-ol"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="justifyLeft"><i class="fa-solid fa-align-left"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="justifyCenter"><i class="fa-solid fa-align-center"></i></button>
                                <button type="button" class="admin-btn-secondary" data-editor-cmd="createLink"><i class="fa-solid fa-link"></i></button>
                                <button type="button" class="admin-btn-secondary" id="newsletter-insert-image"><i class="fa-solid fa-image"></i></button>
                                <button type="button" class="admin-btn-secondary" id="newsletter-insert-cta">CTA</button>
                                <button type="button" class="admin-btn-secondary" id="newsletter-insert-first-name">{{first_name}}</button>
                            </div>
                            <input type="file" id="newsletter-image-input" accept="image/jpeg,image/png,image/webp" hidden>
                            <div id="newsletter-editor" class="newsletter-editor" contenteditable="true"><?= newsletter_sanitize_campaign_html((string) $campaign['body_html']) ?></div>
                            <textarea id="campaign-body" name="body_html" hidden><?= h((string) $campaign['body_html']) ?></textarea>
                        <?php else: ?>
                            <div class="newsletter-editor newsletter-editor-readonly"><?= newsletter_sanitize_campaign_html((string) $campaign['body_html']) ?></div>
                            <textarea id="campaign-body" name="body_html" hidden><?= h((string) $campaign['body_html']) ?></textarea>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($isEditing): ?>
                    <div class="admin-form-card newsletter-send-card">
                        <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Send</h3>
                        <p class="admin-field-hint">Only confirmed subscribers are contacted. Pending and unsubscribed addresses are skipped. Open and click analytics are not recorded. Saving a draft never sends.</p>

                        <?php if ($canEdit): ?>
                            <div class="newsletter-send-block">
                                <label for="campaign-test-email">Test email</label>
                                <input
                                    type="email"
                                    id="campaign-test-email"
                                    name="test_email"
                                    form="newsletter-campaign-form"
                                    value="<?= h($testEmailDefault) ?>"
                                    <?= ($sendReady && $unsubReady && $publicOrigin) ? '' : 'disabled' ?>
                                >
                                <div class="admin-form-actions">
                                    <button
                                        type="submit"
                                        form="newsletter-campaign-form"
                                        name="campaign_action"
                                        value="send_test"
                                        class="admin-btn-secondary"
                                        <?= ($sendReady && $unsubReady && $publicOrigin) ? '' : 'disabled' ?>
                                    >
                                        <i class="fa-solid fa-paper-plane"></i>
                                        Send Test
                                    </button>
                                </div>
                            </div>

                            <div class="newsletter-send-block">
                                <div class="admin-form-actions">
                                    <button
                                        type="submit"
                                        form="newsletter-campaign-form"
                                        name="campaign_action"
                                        value="send_now"
                                        class="admin-btn-primary"
                                        data-send-confirm="Send this campaign now to <?= (int) $recipientCount ?> confirmed subscriber<?= $recipientCount === 1 ? '' : 's' ?>? This cannot be undone."
                                        <?= ($sendReady && $unsubReady && $publicOrigin && $recipientCount > 0) ? '' : 'disabled' ?>
                                    >
                                        <i class="fa-solid fa-bolt"></i>
                                        Send Now
                                    </button>
                                </div>
                            </div>

                            <div class="newsletter-send-block">
                                <label for="campaign-schedule">Schedule send</label>
                                <input
                                    type="datetime-local"
                                    id="campaign-schedule"
                                    name="scheduled_at"
                                    form="newsletter-campaign-form"
                                    min="<?= h($minSchedule) ?>"
                                    value="<?= h($scheduleValue) ?>"
                                    <?= ($sendReady && $unsubReady && $publicOrigin && $recipientCount > 0) ? '' : 'disabled' ?>
                                >
                                <p class="admin-field-hint">Uses server time. Run scripts/send-newsletter-campaigns.php from cron to deliver due campaigns.</p>
                                <div class="admin-form-actions">
                                    <button
                                        type="submit"
                                        form="newsletter-campaign-form"
                                        name="campaign_action"
                                        value="schedule"
                                        class="admin-btn-secondary"
                                        <?= ($sendReady && $unsubReady && $publicOrigin && $recipientCount > 0) ? '' : 'disabled' ?>
                                    >
                                        <i class="fa-solid fa-clock"></i>
                                        Schedule
                                    </button>
                                </div>
                            </div>
                        <?php elseif ($status === 'scheduled'): ?>
                            <form
                                method="post"
                                action="newsletter-campaign-form.php?id=<?= (int) $campaignId ?>"
                                onsubmit="return confirm('Cancel this scheduled send?');"
                            >
                                <?= csrf_field() ?>
                                <input type="hidden" name="campaign_action" value="cancel_schedule">
                                <div class="admin-form-actions">
                                    <button type="submit" class="admin-btn-danger">
                                        <i class="fa-solid fa-ban"></i>
                                        Cancel Schedule
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="admin-field-hint">This campaign is <?= h($status) ?> and cannot be sent again from this screen.</p>
                        <?php endif; ?>

                        <?php if (!empty($sendStats['logs'])): ?>
                            <div class="newsletter-send-block">
                                <h3 style="margin-top: 0; color: var(--admin-link);">Send log</h3>
                                <p class="admin-field-hint">Accepted send means SMTP accepted the message. Delivery and engagement are not recorded.</p>
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Accepted / Targeted</th>
                                            <th>Started</th>
                                            <th>Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sendStats['logs'] as $log): ?>
                                            <tr>
                                                <td><?= !empty($log['is_test']) ? 'Test' : 'Campaign' ?></td>
                                                <td>
                                                    <span class="admin-badge <?= h(newsletter_campaign_badge_class((string) $log['status'])) ?>">
                                                        <?= h(ucfirst((string) $log['status'])) ?>
                                                    </span>
                                                    <?php if (!empty($log['failure_reason'])): ?>
                                                        <div class="admin-field-hint"><?= h((string) $log['failure_reason']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) ($log['sent_count'] ?? 0) ?> / <?= (int) ($log['recipient_count'] ?? 0) ?></td>
                                                <td><?= h(newsletter_admin_format_datetime($log['started_at'] ?? null)) ?></td>
                                                <td><?= h(newsletter_admin_format_datetime($log['completed_at'] ?? null)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>

        </div>

    </div>

    <?php if ($canEdit): ?>
        <script src="<?= versioned_asset('dashboard/assets/js/newsletter-composer.js', 'assets/js/newsletter-composer.js') ?>" defer></script>
    <?php endif; ?>
</body>
</html>
