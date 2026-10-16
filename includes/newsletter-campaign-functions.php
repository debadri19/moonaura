<?php
/* ===================================================================
   ADMIN NEWSLETTER CAMPAIGN SENDING + ANALYTICS (Phase 3–4)
   -------------------------------------------------------------------
   Test email, send now, schedule, history, and send analytics.
   Uses existing send_email() / Brevo SMTP. Open/click tracking is
   not configured and is never invented. Unsubscribe HMAC uses only
   NEWSLETTER_UNSUB_SECRET (fail closed). Send-time eligibility
   re-checks Brevo and skips the recipient if that lookup fails.
=================================================================== */

require_once __DIR__ . '/newsletter-admin-functions.php';
require_once __DIR__ . '/mailer.php';

const NEWSLETTER_CAMPAIGN_SEND_BATCH = 20;
const NEWSLETTER_CAMPAIGN_WEB_MAX = 25;
const NEWSLETTER_CAMPAIGN_PAGE_SIZE = 20;
const NEWSLETTER_UNSUB_TOKEN_TTL = 31536000;
const NEWSLETTER_CAMPAIGN_LOCK_SECONDS = 600;
const NEWSLETTER_RECIPIENT_CLAIM_TTL = 900;
const NEWSLETTER_RECIPIENT_UNKNOWN_ERROR = 'Send outcome unknown; not retried automatically.';

function newsletter_campaign_valid_statuses(): array
{
    return ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];
}

function newsletter_unsub_secret_configured(): bool
{
    return defined('NEWSLETTER_UNSUB_SECRET')
        && is_string(NEWSLETTER_UNSUB_SECRET)
        && strlen(NEWSLETTER_UNSUB_SECRET) >= 16;
}

function newsletter_campaign_secret(): string
{
    if (!newsletter_unsub_secret_configured()) {
        error_log('newsletter_campaign_secret: NEWSLETTER_UNSUB_SECRET is missing or too short');
        return '';
    }

    return (string) NEWSLETTER_UNSUB_SECRET;
}

function newsletter_unsubscribe_token(string $email): array
{
    $email = normalize_email($email);
    $secret = newsletter_campaign_secret();
    if ($secret === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [];
    }

    $expiresAt = time() + NEWSLETTER_UNSUB_TOKEN_TTL;
    $signature = hash_hmac('sha256', $email . '|' . $expiresAt, $secret);

    return ['email' => $email, 'exp' => $expiresAt, 'sig' => $signature];
}

function newsletter_verify_unsubscribe_token(string $email, string $expParam, string $sigParam): bool
{
    $email = normalize_email($email);
    $secret = newsletter_campaign_secret();
    if ($secret === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!ctype_digit($expParam) || $sigParam === '') {
        return false;
    }

    $expiresAt = (int) $expParam;
    if ($expiresAt < time()) {
        return false;
    }

    $expected = hash_hmac('sha256', $email . '|' . $expiresAt, $secret);
    return hash_equals($expected, $sigParam);
}

function newsletter_public_base_url(): string
{
    $base = rtrim((string) SITE_URL, '/');
    if ($base === '' || !newsletter_is_public_https_url($base)) {
        return '';
    }

    return $base;
}

function newsletter_unsubscribe_url(string $email): string
{
    $token = newsletter_unsubscribe_token($email);
    $base = newsletter_public_base_url();
    if ($token === [] || $base === '') {
        return '';
    }

    return $base . '/newsletter-unsubscribe.php?email=' . rawurlencode($token['email'])
        . '&exp=' . $token['exp']
        . '&sig=' . rawurlencode($token['sig']);
}

function newsletter_list_unsubscribe_mailto(): string
{
    $candidates = [];
    if (defined('MAIL_REPLY_TO_ADDRESS')) {
        $candidates[] = (string) MAIL_REPLY_TO_ADDRESS;
    }
    if (defined('MAIL_FROM_ADDRESS')) {
        $candidates[] = (string) MAIL_FROM_ADDRESS;
    }

    foreach ($candidates as $address) {
        $address = normalize_email($address);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $address;
        }
    }

    return '';
}

function newsletter_list_unsubscribe_headers(string $email, bool $isTest = false): array
{
    if ($isTest) {
        return [];
    }

    $parts = [];
    $url = newsletter_unsubscribe_url($email);
    if ($url !== '' && newsletter_is_public_https_url($url)) {
        $parts[] = '<' . $url . '>';
    }

    $mailto = newsletter_list_unsubscribe_mailto();
    if ($mailto !== '') {
        $parts[] = '<mailto:' . $mailto . '?subject=' . rawurlencode('unsubscribe') . '>';
    }

    if ($parts === []) {
        return [];
    }

    return ['List-Unsubscribe' => implode(', ', $parts)];
}

function newsletter_campaign_cta_html(array $campaign): string
{
    $text = trim((string) ($campaign['cta_text'] ?? ''));
    $url  = trim((string) ($campaign['cta_url'] ?? ''));
    if ($text === '' || $url === '' || !newsletter_is_public_https_url($url)) {
        return '';
    }

    return '<p style="text-align:center;margin:28px 0 8px;">'
        . '<a href="' . h($url) . '" style="display:inline-block;background:#5B2E91;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:600;line-height:48px;padding:0 28px;border-radius:8px;">'
        . h($text)
        . '</a></p>';
}

function newsletter_campaign_footer_html(string $unsubscribeUrl, bool $isTest = false): string
{
    $note = $isTest
        ? 'This is a test email. Confirmed subscribers were not contacted.'
        : 'You received this because you confirmed a MoonAura Crystals newsletter subscription.';

    $unsubHtml = $unsubscribeUrl !== ''
        ? '<a href="' . h($unsubscribeUrl) . '" style="color:#5B2E91;">Unsubscribe</a> &middot; '
        : '';

    return '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #ece7f5;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:#8b8678;text-align:center;">'
        . '<p style="margin:0 0 8px;">' . h($note) . '</p>'
        . '<p style="margin:0;">'
        . $unsubHtml . h(SITE_NAME)
        . '</p></div>';
}

function newsletter_build_campaign_html(array $campaign, array $subscriber, bool $isTest = false): string
{
    $body = newsletter_sanitize_campaign_html((string) ($campaign['body_html'] ?? ''));
    $body = newsletter_apply_personalization($body, $subscriber);
    $body .= newsletter_campaign_cta_html($campaign);

    $email = normalize_email((string) ($subscriber['email'] ?? ''));
    $unsub = $email !== '' ? newsletter_unsubscribe_url($email) : '';
    $body .= newsletter_campaign_footer_html($unsub, $isTest);

    $preview = trim((string) ($campaign['preview_text'] ?? ''));
    $previewHtml = $preview !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . h($preview) . '</div>'
        : '';

    return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:0;background:#faf8fc;">'
        . $previewHtml
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#faf8fc;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">'
        . '<tr><td align="center" style="padding:8px 0 20px;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;color:#5B2E91;">' . h(SITE_NAME) . '</td></tr>'
        . '<tr><td style="background:#ffffff;border-radius:10px;padding:28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#222222;">'
        . $body
        . '</td></tr></table></td></tr></table></body></html>';
}

function newsletter_send_eligibility(string $email, ?array $cached): array
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['eligible' => false, 'error' => 'Invalid email address.'];
    }

    if (!$cached || ($cached['status'] ?? '') !== 'confirmed') {
        return ['eligible' => false, 'error' => 'No longer a confirmed subscriber.'];
    }

    if (!newsletter_is_configured()) {
        return ['eligible' => false, 'error' => 'Could not verify subscriber status with Brevo.'];
    }

    $live = newsletter_get_contact($email);
    $httpStatus = (int) ($live['status'] ?? 0);

    if ($httpStatus === 404) {
        return ['eligible' => false, 'error' => 'Contact is no longer present in Brevo.'];
    }

    if ($httpStatus !== 200) {
        return ['eligible' => false, 'error' => 'Could not verify subscriber status with Brevo.'];
    }

    $body = is_array($live['body'] ?? null) ? $live['body'] : [];
    if (newsletter_admin_status_from_contact($body) !== 'confirmed') {
        return ['eligible' => false, 'error' => 'No longer a confirmed subscriber.'];
    }

    return ['eligible' => true, 'error' => ''];
}

function newsletter_eligible_recipients(): array
{
    if (!newsletter_campaign_tables_ready()) {
        return [];
    }

    try {
        $stmt = db()->query(
            "SELECT email, name
             FROM newsletter_subscribers
             WHERE status = 'confirmed'"
        );
        $rows = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_eligible_recipients: ' . $e->getMessage());
        return [];
    }

    $eligible = [];
    foreach ($rows as $row) {
        $email = normalize_email((string) ($row['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $eligible[] = [
            'email' => $email,
            'name'  => (string) ($row['name'] ?? ''),
        ];
    }

    return $eligible;
}

function newsletter_campaign_validate_for_send(array $campaign): array
{
    $errors = [];
    $sender = newsletter_admin_configured_sender();
    $body = trim(strip_tags((string) ($campaign['body_html'] ?? '')));
    $subject = trim((string) ($campaign['subject'] ?? ''));
    $replyTo = normalize_email((string) ($campaign['reply_to'] ?? $sender['reply']));

    if (trim((string) ($campaign['name'] ?? '')) === '') {
        $errors[] = 'Campaign name is required.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required before sending.';
    }
    if ($sender['email'] === '' || !filter_var($sender['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Configured sender email is missing or invalid.';
    }
    if (!mail_is_configured()) {
        $errors[] = 'Email sending is not configured.';
    }
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Reply-To must be a valid email address.';
    }
    if ($body === '') {
        $errors[] = 'Email content is required before sending.';
    }
    if (!newsletter_unsub_secret_configured()) {
        $errors[] = 'NEWSLETTER_UNSUB_SECRET is not configured.';
    }
    if (newsletter_public_base_url() === '') {
        $errors[] = 'SITE_URL must be a public https origin before sending campaigns.';
    }
    $ctaUrl = trim((string) ($campaign['cta_url'] ?? ''));
    if ($ctaUrl !== '' && !newsletter_is_public_https_url($ctaUrl)) {
        $errors[] = 'CTA URL must be a public https address before sending.';
    }

    $recipients = newsletter_eligible_recipients();
    if ($recipients === []) {
        $errors[] = 'There are no confirmed subscribers to send to.';
    }

    return [
        'ok'         => $errors === [],
        'errors'     => $errors,
        'recipients' => $recipients,
        'count'      => count($recipients),
        'sender'     => $sender,
        'reply_to'   => $replyTo !== '' ? $replyTo : $sender['reply'],
    ];
}

function newsletter_campaign_log_send(array $row): int
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO newsletter_campaign_sends
                (campaign_id, campaign_name, status, is_test, recipient_count, sent_count, failed_count,
                 scheduled_at, started_at, completed_at, failure_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $row['campaign_id'],
            (string) $row['campaign_name'],
            (string) $row['status'],
            !empty($row['is_test']) ? 1 : 0,
            (int) ($row['recipient_count'] ?? 0),
            (int) ($row['sent_count'] ?? 0),
            (int) ($row['failed_count'] ?? 0),
            $row['scheduled_at'] ?? null,
            $row['started_at'] ?? null,
            $row['completed_at'] ?? null,
            $row['failure_reason'] ?? null,
        ]);
        return (int) db()->lastInsertId();
    } catch (PDOException $e) {
        error_log('newsletter_campaign_log_send: ' . $e->getMessage());
        return 0;
    }
}

function newsletter_campaign_update_log(int $id, array $fields): void
{
    if ($id <= 0 || $fields === []) {
        return;
    }

    $allowed = [
        'status', 'recipient_count', 'sent_count', 'failed_count',
        'scheduled_at', 'started_at', 'completed_at', 'failure_reason',
    ];
    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }
        $sets[] = $column . ' = ?';
        $params[] = $value;
    }
    if ($sets === []) {
        return;
    }
    $params[] = $id;

    try {
        $stmt = db()->prepare('UPDATE newsletter_campaign_sends SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('newsletter_campaign_update_log: ' . $e->getMessage());
    }
}

function newsletter_campaign_send_one(array $campaign, array $subscriber, bool $isTest = false): array
{
    $html = newsletter_build_campaign_html($campaign, $subscriber, $isTest);
    $unsubUrl = newsletter_unsubscribe_url((string) ($subscriber['email'] ?? ''));
    if ($unsubUrl === '') {
        return ['ok' => false, 'error' => 'Cannot generate a secure unsubscribe link.'];
    }

    $subject = (string) ($campaign['subject'] ?? '');
    if ($isTest) {
        $subject = '[TEST] ' . $subject;
    }

    $replyTo = normalize_email((string) ($campaign['reply_to'] ?? ''));
    $reason = '';
    $ok = send_email(
        (string) $subscriber['email'],
        (string) ($subscriber['name'] ?? ''),
        $subject,
        $html,
        null,
        $replyTo !== '' ? $replyTo : null,
        $reason,
        newsletter_list_unsubscribe_headers((string) ($subscriber['email'] ?? ''), $isTest)
    );

    return ['ok' => $ok, 'error' => $ok ? '' : ($reason !== '' ? $reason : 'Send failed.')];
}

function newsletter_campaign_send_test(int $campaignId, string $testEmail): array
{
    if (!newsletter_campaign_tables_ready()) {
        return ['ok' => false, 'error' => 'Apply database/migration_newsletter_campaign_sending.sql before sending a test.'];
    }

    $campaign = newsletter_admin_get_campaign($campaignId);
    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    $testEmail = normalize_email($testEmail);
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid test email address.'];
    }

    $body = trim(strip_tags((string) ($campaign['body_html'] ?? '')));
    $subject = trim((string) ($campaign['subject'] ?? ''));
    if ($subject === '' || $body === '') {
        return ['ok' => false, 'error' => 'Save a subject and email content before sending a test.'];
    }
    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'Email sending is not configured.'];
    }
    if (!newsletter_unsub_secret_configured()) {
        return ['ok' => false, 'error' => 'NEWSLETTER_UNSUB_SECRET is not configured.'];
    }
    if (newsletter_public_base_url() === '') {
        return ['ok' => false, 'error' => 'SITE_URL must be a public https origin before sending campaigns.'];
    }

    $cached = newsletter_admin_get_cached_subscriber($testEmail);
    $subscriber = [
        'email' => $testEmail,
        'name'  => $cached['name'] ?? '',
    ];

    $started = date('Y-m-d H:i:s');
    $result = newsletter_campaign_send_one($campaign, $subscriber, true);
    $completed = date('Y-m-d H:i:s');

    newsletter_campaign_log_send([
        'campaign_id'      => $campaignId,
        'campaign_name'    => (string) $campaign['name'],
        'status'           => $result['ok'] ? 'sent' : 'failed',
        'is_test'          => 1,
        'recipient_count'  => 1,
        'sent_count'       => $result['ok'] ? 1 : 0,
        'failed_count'     => $result['ok'] ? 0 : 1,
        'started_at'       => $started,
        'completed_at'     => $completed,
        'failure_reason'   => $result['ok'] ? null : mb_substr($result['error'], 0, 240),
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => 'Test email could not be sent.'];
    }

    return ['ok' => true, 'error' => ''];
}

function newsletter_campaign_claim(int $campaignId, array $fromStatuses): bool
{
    if ($fromStatuses === [] || !newsletter_campaign_tables_ready()) {
        return false;
    }

    $allowed = [];
    foreach ($fromStatuses as $status) {
        if (in_array($status, ['draft', 'scheduled', 'failed', 'cancelled'], true)) {
            $allowed[] = $status;
        }
    }
    if ($allowed === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $params = array_merge(['sending', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $campaignId], $allowed);

    try {
        $stmt = db()->prepare(
            'UPDATE newsletter_campaigns
             SET status = ?, started_at = ?, locked_at = ?, failure_reason = NULL
             WHERE id = ? AND status IN (' . $placeholders . ')'
        );
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('newsletter_campaign_claim: ' . $e->getMessage());
        return false;
    }
}

function newsletter_campaign_reset_failed_recipients(int $campaignId): void
{
    $stmt = db()->prepare(
        "UPDATE newsletter_campaign_recipients
         SET status = 'pending', error_message = NULL, sent_at = NULL,
             claimed_at = NULL, claim_token = NULL
         WHERE campaign_id = ? AND status = 'failed'"
    );
    $stmt->execute([$campaignId]);
}

function newsletter_campaign_lock_for_resume(int $campaignId): bool
{
    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaigns
             SET locked_at = ?
             WHERE id = ? AND status = 'sending'
               AND (locked_at IS NULL OR locked_at < ?)"
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $campaignId,
            date('Y-m-d H:i:s', time() - NEWSLETTER_CAMPAIGN_LOCK_SECONDS),
        ]);
        return $stmt->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('newsletter_campaign_lock_for_resume: ' . $e->getMessage());
        return false;
    }
}

function newsletter_campaign_touch_lock(int $campaignId): void
{
    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaigns
             SET locked_at = ?
             WHERE id = ? AND status = 'sending'"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $campaignId]);
    } catch (PDOException $e) {
        error_log('newsletter_campaign_touch_lock: ' . $e->getMessage());
    }
}

function newsletter_campaign_expire_stale_claims(int $campaignId): int
{
    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaign_recipients
             SET status = 'unknown',
                 error_message = ?,
                 sent_at = NULL,
                 claim_token = NULL
             WHERE campaign_id = ?
               AND status = 'claimed'
               AND claimed_at IS NOT NULL
               AND claimed_at < ?"
        );
        $stmt->execute([
            NEWSLETTER_RECIPIENT_UNKNOWN_ERROR,
            $campaignId,
            date('Y-m-d H:i:s', time() - NEWSLETTER_RECIPIENT_CLAIM_TTL),
        ]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('newsletter_campaign_expire_stale_claims: ' . $e->getMessage());
        return 0;
    }
}

function newsletter_campaign_queue_recipients(int $campaignId, array $recipients): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO newsletter_campaign_recipients (campaign_id, email, name, status)
         VALUES (?, ?, ?, \'pending\')'
    );
    foreach ($recipients as $recipient) {
        $stmt->execute([
            $campaignId,
            $recipient['email'],
            $recipient['name'] !== '' ? $recipient['name'] : null,
        ]);
    }
}

function newsletter_campaign_pending_recipients(int $campaignId): array
{
    $stmt = db()->prepare(
        "SELECT email, name FROM newsletter_campaign_recipients
         WHERE campaign_id = ? AND status = 'pending'
         ORDER BY id ASC
         LIMIT " . (int) NEWSLETTER_CAMPAIGN_SEND_BATCH
    );
    $stmt->execute([$campaignId]);
    return $stmt->fetchAll() ?: [];
}

function newsletter_campaign_claim_recipient(int $campaignId, string $email): ?array
{
    $email = normalize_email($email);
    if ($email === '') {
        return null;
    }

    $token = bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaign_recipients
             SET status = 'claimed', claim_token = ?, claimed_at = ?, error_message = NULL, sent_at = NULL
             WHERE campaign_id = ? AND email = ? AND status = 'pending'"
        );
        $stmt->execute([$token, $now, $campaignId, $email]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
    } catch (PDOException $e) {
        error_log('newsletter_campaign_claim_recipient: ' . $e->getMessage());
        return null;
    }

    return [
        'email'        => $email,
        'claim_token'  => $token,
        'claimed_at'   => $now,
    ];
}

function newsletter_campaign_mark_recipient(
    int $campaignId,
    string $email,
    string $status,
    string $error = '',
    string $claimToken = ''
): bool {
    if (!in_array($status, ['sent', 'failed'], true) || $claimToken === '') {
        return false;
    }

    $stmt = db()->prepare(
        "UPDATE newsletter_campaign_recipients
         SET status = ?, error_message = ?, sent_at = ?, claim_token = NULL
         WHERE campaign_id = ? AND email = ? AND claim_token = ? AND status = 'claimed'"
    );
    $stmt->execute([
        $status,
        $error !== '' ? mb_substr($error, 0, 240) : null,
        $status === 'sent' ? date('Y-m-d H:i:s') : null,
        $campaignId,
        normalize_email($email),
        $claimToken,
    ]);

    return $stmt->rowCount() === 1;
}

function newsletter_campaign_recipient_counts(int $campaignId): array
{
    $counts = db()->prepare(
        "SELECT
            SUM(status = 'sent') AS sent_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status = 'pending') AS pending_count,
            SUM(status = 'claimed') AS claimed_count,
            SUM(status = 'unknown') AS unknown_count,
            COUNT(*) AS recipient_count
         FROM newsletter_campaign_recipients
         WHERE campaign_id = ?"
    );
    $counts->execute([$campaignId]);
    $row = $counts->fetch() ?: [];

    return [
        'sent'      => (int) ($row['sent_count'] ?? 0),
        'failed'    => (int) ($row['failed_count'] ?? 0),
        'pending'   => (int) ($row['pending_count'] ?? 0),
        'claimed'   => (int) ($row['claimed_count'] ?? 0),
        'unknown'   => (int) ($row['unknown_count'] ?? 0),
        'total'     => (int) ($row['recipient_count'] ?? 0),
    ];
}

function newsletter_campaign_process(int $campaignId, int $logId = 0, int $maxRecipients = 0): array
{
    $campaign = newsletter_admin_get_campaign($campaignId);
    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    newsletter_campaign_expire_stale_claims($campaignId);
    newsletter_campaign_touch_lock($campaignId);

    $processed = 0;

    while (true) {
        if ($maxRecipients > 0 && $processed >= $maxRecipients) {
            break;
        }

        $batch = newsletter_campaign_pending_recipients($campaignId);
        if ($batch === []) {
            break;
        }

        $claimedAny = false;
        foreach ($batch as $recipient) {
            if ($maxRecipients > 0 && $processed >= $maxRecipients) {
                break 2;
            }

            $claim = newsletter_campaign_claim_recipient($campaignId, (string) $recipient['email']);
            if ($claim === null) {
                continue;
            }
            $claimedAny = true;

            newsletter_campaign_touch_lock($campaignId);

            $fresh = newsletter_admin_get_cached_subscriber($claim['email']);
            $eligibility = newsletter_send_eligibility($claim['email'], $fresh);
            if (!$eligibility['eligible']) {
                newsletter_campaign_mark_recipient(
                    $campaignId,
                    $claim['email'],
                    'failed',
                    $eligibility['error'] !== '' ? $eligibility['error'] : 'No longer a confirmed subscriber.',
                    $claim['claim_token']
                );
                $processed++;
                continue;
            }

            $result = newsletter_campaign_send_one($campaign, [
                'email' => $claim['email'],
                'name'  => (string) ($fresh['name'] ?? $recipient['name'] ?? ''),
            ], false);

            if ($result['ok']) {
                newsletter_campaign_mark_recipient($campaignId, $claim['email'], 'sent', '', $claim['claim_token']);
            } else {
                newsletter_campaign_mark_recipient($campaignId, $claim['email'], 'failed', $result['error'], $claim['claim_token']);
            }

            $processed++;
            usleep(50000);
        }

        if (!$claimedAny) {
            break;
        }
    }

    $row = newsletter_campaign_recipient_counts($campaignId);
    if ($row['pending'] > 0 || $row['claimed'] > 0) {
        $lockSql = $row['claimed'] > 0
            ? 'UPDATE newsletter_campaigns SET sent_count = ?, failed_count = ?, recipient_count = ?, locked_at = ? WHERE id = ? AND status = \'sending\''
            : 'UPDATE newsletter_campaigns SET sent_count = ?, failed_count = ?, recipient_count = ?, locked_at = NULL WHERE id = ? AND status = \'sending\'';
        $update = db()->prepare($lockSql);
        if ($row['claimed'] > 0) {
            $update->execute([$row['sent'], $row['failed'], $row['total'], date('Y-m-d H:i:s'), $campaignId]);
        } else {
            $update->execute([$row['sent'], $row['failed'], $row['total'], $campaignId]);
        }

        if ($logId > 0) {
            newsletter_campaign_update_log($logId, [
                'sent_count'      => $row['sent'],
                'failed_count'    => $row['failed'],
                'recipient_count' => $row['total'],
            ]);
        }

        return [
            'ok'      => true,
            'queued'  => true,
            'error'   => '',
            'sent'    => $row['sent'],
            'failed'  => $row['failed'],
        ];
    }

    $sentCount = $row['sent'];
    $failedCount = $row['failed'];
    $total = $row['total'];
    $finalStatus = $sentCount > 0 ? 'sent' : 'failed';
    $reason = $sentCount > 0 ? null : 'No emails were delivered.';
    $completed = date('Y-m-d H:i:s');

    $update = db()->prepare(
        'UPDATE newsletter_campaigns
         SET status = ?, completed_at = ?, sent_count = ?, failed_count = ?, recipient_count = ?,
             failure_reason = ?, locked_at = NULL
         WHERE id = ?'
    );
    $update->execute([$finalStatus, $completed, $sentCount, $failedCount, $total, $reason, $campaignId]);

    if ($logId > 0) {
        newsletter_campaign_update_log($logId, [
            'status'          => $finalStatus,
            'sent_count'      => $sentCount,
            'failed_count'    => $failedCount,
            'recipient_count' => $total,
            'completed_at'    => $completed,
            'failure_reason'  => $reason,
        ]);
    }

    return [
        'ok'     => $finalStatus === 'sent',
        'error'  => $reason ?? '',
        'sent'   => $sentCount,
        'failed' => $failedCount,
    ];
}

function newsletter_campaign_start_send(int $campaignId, array $fromStatuses): array
{
    if (!newsletter_campaign_tables_ready()) {
        return ['ok' => false, 'error' => 'Apply database/migration_newsletter_campaign_sending.sql before sending.'];
    }

    $campaign = newsletter_admin_get_campaign($campaignId);
    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    $validation = newsletter_campaign_validate_for_send($campaign);
    if (!$validation['ok']) {
        return ['ok' => false, 'error' => implode(' ', $validation['errors'])];
    }

    if (!newsletter_campaign_claim($campaignId, $fromStatuses)) {
        return ['ok' => false, 'error' => 'This campaign is not eligible to send, or another send is already in progress.'];
    }

    try {
        newsletter_campaign_reset_failed_recipients($campaignId);
        newsletter_campaign_queue_recipients($campaignId, $validation['recipients']);
    } catch (PDOException $e) {
        error_log('newsletter_campaign_start_send queue: ' . $e->getMessage());
        db()->prepare(
            'UPDATE newsletter_campaigns SET status = ?, failure_reason = ?, locked_at = NULL WHERE id = ?'
        )->execute(['failed', 'Could not queue recipients.', $campaignId]);
        return ['ok' => false, 'error' => 'Could not queue recipients.'];
    }

    $logId = newsletter_campaign_log_send([
        'campaign_id'     => $campaignId,
        'campaign_name'   => (string) $campaign['name'],
        'status'          => 'sending',
        'is_test'         => 0,
        'recipient_count' => $validation['count'],
        'scheduled_at'    => $campaign['scheduled_at'] ?? null,
        'started_at'      => date('Y-m-d H:i:s'),
    ]);

    $updateCount = db()->prepare('UPDATE newsletter_campaigns SET recipient_count = ? WHERE id = ?');
    $updateCount->execute([$validation['count'], $campaignId]);

    $max = PHP_SAPI === 'cli' ? 0 : NEWSLETTER_CAMPAIGN_WEB_MAX;
    return newsletter_campaign_process($campaignId, $logId, $max);
}

function newsletter_campaign_schedule(int $campaignId, string $scheduledAt): array
{
    if (!newsletter_campaign_tables_ready()) {
        return ['ok' => false, 'error' => 'Apply database/migration_newsletter_campaign_sending.sql before scheduling.'];
    }

    $campaign = newsletter_admin_get_campaign($campaignId);
    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    $status = (string) ($campaign['status'] ?? '');
    if (!in_array($status, ['draft', 'failed', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'Only draft, failed, or cancelled campaigns can be scheduled.'];
    }

    $stamp = strtotime($scheduledAt);
    if ($stamp === false) {
        return ['ok' => false, 'error' => 'Please choose a valid date and time.'];
    }
    if ($stamp <= time()) {
        return ['ok' => false, 'error' => 'Scheduled time must be in the future.'];
    }

    $validation = newsletter_campaign_validate_for_send($campaign);
    if (!$validation['ok']) {
        return ['ok' => false, 'error' => implode(' ', $validation['errors'])];
    }

    $when = date('Y-m-d H:i:s', $stamp);

    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaigns
             SET status = 'scheduled', scheduled_at = ?, recipient_count = ?, failure_reason = NULL,
                 started_at = NULL, completed_at = NULL, locked_at = NULL
             WHERE id = ? AND status IN ('draft','failed','cancelled')"
        );
        $stmt->execute([$when, $validation['count'], $campaignId]);
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Could not schedule this campaign.'];
        }
    } catch (PDOException $e) {
        error_log('newsletter_campaign_schedule: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not schedule this campaign.'];
    }

    return ['ok' => true, 'error' => '', 'scheduled_at' => $when, 'count' => $validation['count']];
}

function newsletter_campaign_cancel_schedule(int $campaignId): array
{
    try {
        $stmt = db()->prepare(
            "UPDATE newsletter_campaigns
             SET status = 'cancelled', locked_at = NULL
             WHERE id = ? AND status = 'scheduled'"
        );
        $stmt->execute([$campaignId]);
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'This campaign is not scheduled.'];
        }
    } catch (PDOException $e) {
        error_log('newsletter_campaign_cancel_schedule: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not cancel the schedule.'];
    }

    $campaign = newsletter_admin_get_campaign($campaignId);
    newsletter_campaign_log_send([
        'campaign_id'     => $campaignId,
        'campaign_name'   => (string) ($campaign['name'] ?? ''),
        'status'          => 'cancelled',
        'is_test'         => 0,
        'recipient_count' => (int) ($campaign['recipient_count'] ?? 0),
        'scheduled_at'    => $campaign['scheduled_at'] ?? null,
        'completed_at'    => date('Y-m-d H:i:s'),
        'failure_reason'  => 'Cancelled before send.',
    ]);

    return ['ok' => true, 'error' => ''];
}

function newsletter_campaign_resume_sending(): array
{
    $results = ['processed' => 0, 'sent' => 0, 'failed' => 0];
    if (!newsletter_campaign_tables_ready()) {
        return $results;
    }

    try {
        $stmt = db()->query(
            "SELECT id FROM newsletter_campaigns
             WHERE status = 'sending'
             ORDER BY started_at ASC
             LIMIT 3"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_resume_sending: ' . $e->getMessage());
        return $results;
    }

    foreach ($ids as $id) {
        $campaignId = (int) $id;
        if (!newsletter_campaign_lock_for_resume($campaignId)) {
            continue;
        }

        $results['processed']++;
        $campaign = newsletter_admin_get_campaign($campaignId);
        $logId = 0;
        try {
            $logStmt = db()->prepare(
                "SELECT id FROM newsletter_campaign_sends
                 WHERE campaign_id = ? AND is_test = 0 AND status = 'sending'
                 ORDER BY id DESC LIMIT 1"
            );
            $logStmt->execute([$campaignId]);
            $logId = (int) ($logStmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('newsletter_campaign_resume_sending log: ' . $e->getMessage());
        }

        $send = newsletter_campaign_process($campaignId, $logId);
        if ($send['ok']) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
        unset($campaign);
    }

    return $results;
}

function newsletter_campaign_run_due(): array
{
    $results = ['processed' => 0, 'sent' => 0, 'failed' => 0];
    if (!newsletter_campaign_tables_ready()) {
        return $results;
    }

    try {
        $stmt = db()->prepare(
            "SELECT id FROM newsletter_campaigns
             WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?
             ORDER BY scheduled_at ASC
             LIMIT 5"
        );
        $stmt->execute([date('Y-m-d H:i:s')]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_run_due: ' . $e->getMessage());
        return $results;
    }

    foreach ($ids as $id) {
        $results['processed']++;
        $send = newsletter_campaign_start_send((int) $id, ['scheduled']);
        if ($send['ok']) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
    }

    return $results;
}

function newsletter_campaign_history(int $limit = 20): array
{
    if (!newsletter_campaign_tables_ready()) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, campaign_id, campaign_name, status, is_test, recipient_count, sent_count,
                    failed_count, scheduled_at, started_at, completed_at, failure_reason
             FROM newsletter_campaign_sends
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_history: ' . $e->getMessage());
        return [];
    }
}

function newsletter_campaign_badge_class(string $status): string
{
    return match ($status) {
        'sent'       => 'admin-badge-paid',
        'sending'    => 'admin-badge-processing',
        'scheduled'  => 'admin-badge-pending',
        'failed'     => 'admin-badge-failed',
        'cancelled'  => 'admin-badge-cancelled',
        default      => 'admin-badge-draft',
    };
}

function newsletter_campaign_list_sorts(): array
{
    return [
        'updated_at'   => 'Updated',
        'created_at'   => 'Created',
        'completed_at' => 'Sent',
        'name'         => 'Name',
        'status'       => 'Status',
    ];
}

function newsletter_campaign_parse_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $stamp = strtotime($value . ' 00:00:00');
    if ($stamp === false) {
        return null;
    }
    return date('Y-m-d', $stamp);
}

function newsletter_campaign_date_clause(?string $from, ?string $to, string $column = 'created_at'): array
{
    $where = [];
    $params = [];
    if ($from !== null) {
        $where[] = $column . ' >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $where[] = $column . ' <= ?';
        $params[] = $to . ' 23:59:59';
    }
    return ['where' => $where, 'params' => $params];
}

function newsletter_campaign_analytics(?string $from = null, ?string $to = null): array
{
    $empty = [
        'total'            => 0,
        'draft'            => 0,
        'scheduled'        => 0,
        'sending'          => 0,
        'sent'             => 0,
        'failed'           => 0,
        'cancelled'        => 0,
        'recipients'       => 0,
        'accepted_sends'   => 0,
        'failed_sends'     => 0,
        'pending_sends'    => 0,
        'test_sends'       => 0,
        'from'             => $from,
        'to'               => $to,
        'ready'            => false,
    ];

    if (!newsletter_campaign_tables_ready()) {
        return $empty;
    }

    $dates = newsletter_campaign_date_clause($from, $to, 'c.created_at');
    $sqlWhere = $dates['where'] === [] ? '' : (' WHERE ' . implode(' AND ', $dates['where']));

    try {
        $stmt = db()->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(c.status = 'draft') AS draft,
                SUM(c.status = 'scheduled') AS scheduled,
                SUM(c.status = 'sending') AS sending,
                SUM(c.status = 'sent') AS sent,
                SUM(c.status = 'failed') AS failed,
                SUM(c.status = 'cancelled') AS cancelled
             FROM newsletter_campaigns c"
            . $sqlWhere
        );
        $stmt->execute($dates['params']);
        $row = $stmt->fetch() ?: [];

        $recWhere = $dates['where'];
        $recWhere[] = "c.status IN ('sending','sent','failed')";
        $recSql = ' WHERE ' . implode(' AND ', $recWhere);

        $recipients = db()->prepare(
            "SELECT
                COUNT(*) AS recipients,
                 SUM(r.status = 'sent') AS accepted_sends,
                 SUM(r.status = 'failed') AS failed_sends,
                 SUM(r.status IN ('pending','claimed')) AS pending_sends
             FROM newsletter_campaign_recipients r
             INNER JOIN newsletter_campaigns c ON c.id = r.campaign_id"
            . $recSql
        );
        $recipients->execute($dates['params']);
        $rec = $recipients->fetch() ?: [];

        $tests = db()->prepare(
            "SELECT COUNT(*) FROM newsletter_campaign_sends s
             INNER JOIN newsletter_campaigns c ON c.id = s.campaign_id
             WHERE s.is_test = 1"
            . ($dates['where'] === [] ? '' : (' AND ' . implode(' AND ', $dates['where'])))
        );
        $tests->execute($dates['params']);

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'draft'          => (int) ($row['draft'] ?? 0),
            'scheduled'      => (int) ($row['scheduled'] ?? 0),
            'sending'        => (int) ($row['sending'] ?? 0),
            'sent'           => (int) ($row['sent'] ?? 0),
            'failed'         => (int) ($row['failed'] ?? 0),
            'cancelled'      => (int) ($row['cancelled'] ?? 0),
            'recipients'     => (int) ($rec['recipients'] ?? 0),
            'accepted_sends' => (int) ($rec['accepted_sends'] ?? 0),
            'failed_sends'   => (int) ($rec['failed_sends'] ?? 0),
            'pending_sends'  => (int) ($rec['pending_sends'] ?? 0),
            'test_sends'     => (int) $tests->fetchColumn(),
            'from'           => $from,
            'to'             => $to,
            'ready'          => true,
        ];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_analytics: ' . $e->getMessage());
        return $empty;
    }
}

function newsletter_campaign_list(string $search = '', string $status = '', string $sort = 'updated_at', int $page = 1, ?string $from = null, ?string $to = null): array
{
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'sort' => 'updated_at'];
    if (!newsletter_admin_ensure_tables()) {
        return $empty;
    }

    $sorts = newsletter_campaign_list_sorts();
    if (!isset($sorts[$sort])) {
        $sort = 'updated_at';
    }
    $page = max(1, $page);
    $offset = ($page - 1) * NEWSLETTER_CAMPAIGN_PAGE_SIZE;

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(c.name LIKE ? OR c.subject LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '' && in_array($status, newsletter_campaign_valid_statuses(), true)) {
        $where[] = 'c.status = ?';
        $params[] = $status;
    }
    $dates = newsletter_campaign_date_clause($from, $to, 'c.created_at');
    $where = array_merge($where, $dates['where']);
    $params = array_merge($params, $dates['params']);
    $sqlWhere = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

    $order = match ($sort) {
        'name'         => 'c.name ASC, c.id DESC',
        'status'       => 'c.status ASC, c.updated_at DESC',
        'created_at'   => 'c.created_at DESC, c.id DESC',
        'completed_at' => 'c.completed_at DESC, c.id DESC',
        default        => 'c.updated_at DESC, c.id DESC',
    };

    $select = newsletter_campaign_tables_ready()
        ? 'c.id, c.name, c.subject, c.status, c.scheduled_at, c.recipient_count, c.sent_count,
           c.failed_count, c.updated_at, c.created_at, c.started_at, c.completed_at, c.failure_reason'
        : 'c.id, c.name, c.subject, c.status, c.updated_at, c.created_at';

    try {
        $count = db()->prepare('SELECT COUNT(*) FROM newsletter_campaigns c' . $sqlWhere);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / NEWSLETTER_CAMPAIGN_PAGE_SIZE));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * NEWSLETTER_CAMPAIGN_PAGE_SIZE;
        }

        $stmt = db()->prepare(
            'SELECT ' . $select . '
             FROM newsletter_campaigns c'
            . $sqlWhere . '
             ORDER BY ' . $order . '
             LIMIT ' . (int) NEWSLETTER_CAMPAIGN_PAGE_SIZE . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        return [
            'rows'        => $stmt->fetchAll() ?: [],
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'sort'        => $sort,
        ];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_list: ' . $e->getMessage());
        return $empty;
    }
}

function newsletter_campaign_send_stats(int $campaignId): array
{
    $empty = [
        'recipients' => 0,
        'pending'    => 0,
        'accepted'   => 0,
        'failed'     => 0,
        'unknown'    => 0,
        'failures'   => [],
        'logs'       => [],
    ];
    if ($campaignId <= 0 || !newsletter_campaign_tables_ready()) {
        return $empty;
    }

    try {
        $counts = newsletter_campaign_recipient_counts($campaignId);
        $failStmt = db()->prepare(
            "SELECT error_message, COUNT(*) AS total
             FROM newsletter_campaign_recipients
             WHERE campaign_id = ? AND status = 'failed'
             GROUP BY error_message
             ORDER BY total DESC
             LIMIT 8"
        );
        $failStmt->execute([$campaignId]);
        $failures = [];
        foreach ($failStmt->fetchAll() ?: [] as $row) {
            $failures[] = [
                'reason' => trim((string) ($row['error_message'] ?? '')) !== ''
                    ? (string) $row['error_message']
                    : 'Send failed.',
                'count'  => (int) $row['total'],
            ];
        }

        $logStmt = db()->prepare(
            'SELECT id, status, is_test, recipient_count, sent_count, failed_count,
                    scheduled_at, started_at, completed_at, failure_reason
             FROM newsletter_campaign_sends
             WHERE campaign_id = ?
             ORDER BY id DESC
             LIMIT 12'
        );
        $logStmt->execute([$campaignId]);

        return [
            'recipients' => $counts['total'],
            'pending'    => $counts['pending'] + $counts['claimed'],
            'accepted'   => $counts['sent'],
            'failed'     => $counts['failed'],
            'unknown'    => $counts['unknown'],
            'failures'   => $failures,
            'logs'       => $logStmt->fetchAll() ?: [],
        ];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_send_stats: ' . $e->getMessage());
        return $empty;
    }
}

function newsletter_campaign_history_page(int $page = 1, bool $includeTests = true): array
{
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
    if (!newsletter_campaign_tables_ready()) {
        return $empty;
    }

    $page = max(1, $page);
    $offset = ($page - 1) * NEWSLETTER_CAMPAIGN_PAGE_SIZE;
    $where = $includeTests ? '' : ' WHERE is_test = 0';

    try {
        $total = (int) db()->query('SELECT COUNT(*) FROM newsletter_campaign_sends' . $where)->fetchColumn();
        $totalPages = max(1, (int) ceil($total / NEWSLETTER_CAMPAIGN_PAGE_SIZE));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * NEWSLETTER_CAMPAIGN_PAGE_SIZE;
        }

        $stmt = db()->prepare(
            'SELECT id, campaign_id, campaign_name, status, is_test, recipient_count, sent_count,
                    failed_count, scheduled_at, started_at, completed_at, failure_reason
             FROM newsletter_campaign_sends'
            . $where . '
             ORDER BY id DESC
             LIMIT ' . (int) NEWSLETTER_CAMPAIGN_PAGE_SIZE . ' OFFSET ' . (int) $offset
        );
        $stmt->execute();

        return [
            'rows'        => $stmt->fetchAll() ?: [],
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
        ];
    } catch (PDOException $e) {
        error_log('newsletter_campaign_history_page: ' . $e->getMessage());
        return $empty;
    }
}
