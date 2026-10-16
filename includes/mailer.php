<?php
/* ===================================================================
   EMAIL MAILER (Phase 5D)
   -------------------------------------------------------------------
   The ONE place in the project that sends email. Wraps PHPMailer
   (vendored under includes/lib/PHPMailer) and talks to Brevo SMTP.

   WHY A SINGLE HELPER:
   - Every transactional email goes through send_email(), so SMTP
     credentials, TLS behaviour, timeouts and error handling live in
     exactly one file instead of being duplicated across pages.
   - Callers never touch PHPMailer directly and never see an SMTP
     exception - send_email() always returns a bool.

   PRODUCTION-SAFE BEHAVIOUR:
   - Everything is configured from env() via config.php. There are NO
     credentials anywhere in this file.
   - If SMTP is not configured (mail_is_configured() == false) or the
     send fails, send_email() returns false and writes ONE line to
     PHP's error log. It never throws and never breaks the page that
     called it (the password-reset flow, for example, must keep
     working even when Brevo is down or misconfigured).
   - SMTP debug output is always OFF - connection chatter (including
     auth handshakes) must never leak into a page's HTML or the log.
   - Recipients are validated before any network call.

   TLS:
   - BREVO_SMTP_SECURE is 'auto' by default: implicit TLS for port
     465, STARTTLS for 587/25, plain otherwise. SMTPAutoTLS is left
     on, so a server advertising STARTTLS is still upgraded even when
     an explicit port/secure mix would suggest plain.
=================================================================== */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';


/* ==========================================
   IS EMAIL ACTUALLY CONFIGURED?
   Fails closed: we only consider email enabled
   once a host AND a From address exist. All the
   other fields (port, credentials, name) default
   to sensible values, but a site that hasn't
   been given SMTP settings yet must simply not
   attempt to connect anywhere.
========================================== */

function mail_is_configured(): bool
{
    return BREVO_SMTP_HOST !== '' && MAIL_FROM_ADDRESS !== '';
}


/* ==========================================
   RESOLVED SMTP SECURITY MODE
   'auto' (default) -> 'ssl' on port 465,
   'tls' on 587/25, '' otherwise. Any explicit
   BREVO_SMTP_SECURE value ('tls'/'ssl'/'none')
   wins over the auto guess.
========================================== */

function mailer_smtp_secure(): string
{
    $secure = strtolower((string) BREVO_SMTP_SECURE);

    if (in_array($secure, ['tls', 'ssl', 'none'], true)) {
        return $secure === 'none' ? '' : $secure;
    }

    if ((int) BREVO_SMTP_PORT === 465) {
        return 'ssl';
    }

    if (in_array((int) BREVO_SMTP_PORT, [25, 587], true)) {
        return 'tls';
    }

    return '';
}


/* ==========================================
   CRUDE HTML -> PLAIN-TEXT FALLBACK
   Used when a caller provides HTML but no
   separate plain-text body: strips tags,
   decodes entities and collapses whitespace so
   a text-only mail client still gets readable
   content (the MIME alternative PHPMailer
   attaches as AltBody).
========================================== */

function html_to_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text ?? '');
    $text = strip_tags($text ?? '');
    $text = html_entity_decode($text ?? '', ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text ?? '');
    $text = preg_replace('/\n[ \t]+/', "\n", $text ?? '');
    $text = preg_replace('/\n{3,}/', "\n\n", $text ?? '');

    return trim($text ?? '');
}


/* ==========================================
   SEND AN EMAIL
   -------------------------------------------------------------------
   $toAddress  - recipient email (validated here).
   $toName     - recipient display name (optional).
   $subject    - subject line.
   $htmlBody   - HTML message body.
   $textBody   - optional plain-text body; if omitted, one is derived
                 from the HTML via html_to_text().
    $replyToAddress - optional Reply-To override. When null/empty, the
                 configured MAIL_REPLY_TO_ADDRESS is used (falling back
                 to MAIL_FROM_ADDRESS if that is empty too) - so order
                 emails reply to support@ by default while the From
                 stays the no-reply sender.
    $failureReason - optional by-ref out-param that receives the failure
                  reason string when the send fails (empty on success).
    $extraHeaders - optional header name => value map (e.g. List-Unsubscribe
                  on newsletter campaigns). Names must be token-safe;
                  values must not contain CR/LF. Transactional callers
                  omit this and are unchanged.

    Returns true on success, false on ANY failure (never throws). On
    failure the exact PHPMailer error is written to error_log for ops
    to diagnose - the caller only sees "false".
========================================== */

function send_email(?string $toAddress, string $toName = '', string $subject = '', string $htmlBody = '', ?string $textBody = null, ?string $replyToAddress = null, ?string &$failureReason = null, array $extraHeaders = []): bool
{
    $failureReason = null;
    $toAddress = normalize_email((string) $toAddress);

    if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
        $reason = 'Invalid recipient email "' . $toAddress . '"';
        $failureReason = $reason;
        error_log('send_email: ' . $reason);
        return false;
    }

    if (!mail_is_configured()) {
        $reason = 'SMTP not configured (BREVO_SMTP_HOST/MAIL_FROM_ADDRESS)';
        $failureReason = $reason;
        error_log('send_email: ' . $reason . ', email not sent to "' . $toAddress . '"');
        return false;
    }

    $mail = new PHPMailer(true); // true = exceptions on failure

    try {

        $mail->isSMTP();

        // Brevo SMTP relay (STARTTLS on 587, implicit TLS on 465).
        $mail->Host       = BREVO_SMTP_HOST;
        $mail->Port       = (int) BREVO_SMTP_PORT;
        $mail->SMTPSecure = mailer_smtp_secure(); // '' means no explicit TLS
        $mail->SMTPAutoTLS = true;                // still upgrade if offered

        if (BREVO_SMTP_USERNAME !== '') {
            $mail->SMTPAuth   = true;
            $mail->Username   = BREVO_SMTP_USERNAME;
            $mail->Password   = BREVO_SMTP_PASSWORD;
        }

        // Behave well on shared hosts / gateways.
        $mail->Timeout       = 15;   // seconds per SMTP reply
        $mail->SMTPKeepAlive = false;

        // SMTP chatter must never leak into output or logs.
        $mail->SMTPDebug = 0;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        $replyTo = normalize_email((string) $replyToAddress);
        if ($replyTo === '') {
            $replyTo = normalize_email((string) (defined('MAIL_REPLY_TO_ADDRESS') ? MAIL_REPLY_TO_ADDRESS : ''));
        }
        if ($replyTo === '') {
            $replyTo = MAIL_FROM_ADDRESS;
        }
        $mail->addReplyTo($replyTo, MAIL_FROM_NAME);

        $mail->addAddress($toAddress, $toName);

        foreach ($extraHeaders as $headerName => $headerValue) {
            $headerName = trim((string) $headerName);
            $headerValue = trim((string) $headerValue);
            if ($headerName === '' || $headerValue === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9-]+$/', $headerName)) {
                continue;
            }
            if (preg_match('/[\r\n]/', $headerValue)) {
                continue;
            }
            $mail->addCustomHeader($headerName, $headerValue);
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = ($textBody !== null && $textBody !== '') ? $textBody : html_to_text($htmlBody);

        return $mail->send();

    } catch (PHPMailerException $e) {
        $failureReason = $e->getMessage();
        error_log('send_email: failed to "' . $toAddress . '" - ' . $failureReason);
        return false;
    } catch (Throwable $e) {
        $failureReason = $e->getMessage();
        error_log('send_email: unexpected error sending to "' . $toAddress . '" - ' . $failureReason);
        return false;
    }
}
