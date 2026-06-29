<?php
/**
 * core/mailer.php
 * ---------------------------------------------------------------------------
 * Central email sender for BMS. Single entry point: sendEmail().
 *
 * Backed by PHPMailer (vendored in includes/PHPMailer/, no composer needed).
 * SMTP credentials are read from system_settings (the Settings > Email tab),
 * with an optional per-call override (used by the "Test Email" button so an
 * admin can test values before saving them).
 *
 * Design rules (consistent with the rest of BMS):
 *   - FAIL-SILENT: if SMTP is not configured it returns false and logs — it
 *     never throws, so it can never break the page/flow that called it.
 *   - TLS verification uses the bundled CA bundle (includes/cacert.pem) so it
 *     works on Windows/WAMP where the system CA store is often missing.
 *   - The last error is retrievable via mailer_last_error() for UIs that need
 *     to show why a send failed (e.g. the Test Email button).
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   sendEmail('user@x.com', 'Subject', '<p>Hello</p>');
 *   sendEmail(['a@x.com','b@x.com'], 'Subject', $html, ['cc'=>'c@x.com']);
 */

require_once __DIR__ . '/../includes/PHPMailer/Exception.php';
require_once __DIR__ . '/../includes/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/SMTP.php';

if (!function_exists('mailer_last_error')) {
    /** Returns the human-readable error from the most recent sendEmail() call. */
    function mailer_last_error(): string
    {
        return $GLOBALS['__bms_mailer_last_error'] ?? '';
    }
}

if (!function_exists('bms_email_wrap')) {
    /**
     * Wrap body HTML in a simple, email-client-safe branded template
     * (inline CSS only — no external assets, so it renders everywhere).
     */
    function bms_email_wrap(string $title, string $bodyHtml): string
    {
        $company = function_exists('get_setting') ? (string) get_setting('company_name', 'BMS') : 'BMS';
        $company = htmlspecialchars($company !== '' ? $company : 'BMS', ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#212529;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">'
            . '<tr><td style="background:#0d6efd;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:bold;">' . $company . '</td></tr>'
            . '<tr><td style="padding:24px;">'
            . '<h2 style="margin:0 0 14px;font-size:18px;color:#212529;">' . $safeTitle . '</h2>'
            . '<div style="font-size:14px;line-height:1.6;color:#343a40;">' . $bodyHtml . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px;background:#f8f9fa;border-top:1px solid #e9ecef;font-size:12px;color:#6c757d;">'
            . 'This is an automated message from ' . $company . '. Please do not reply to this email.<br>&copy; ' . $year . ' ' . $company . '.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

if (!function_exists('sendEmail')) {
    /**
     * Send an email via the configured SMTP server.
     *
     * @param string|array $to       One address or a list of addresses.
     * @param string       $subject  Subject line.
     * @param string       $htmlBody Body HTML (wrapped in the branded template unless opts['wrap']=false).
     * @param array        $opts     Optional:
     *      'cc'          => string|array
     *      'bcc'         => string|array
     *      'reply_to'    => string
     *      'from_email'  => string   (override From address)
     *      'from_name'   => string   (override From name)
     *      'alt_body'    => string   (plain-text alternative; auto-derived if omitted)
     *      'attachments' => array    (list of absolute file paths)
     *      'wrap'        => bool     (default true — apply bms_email_wrap)
     *      'smtp'        => array    (override saved SMTP config: host,port,username,password,encryption,from_email,from_name)
     *
     * @return bool True on success. On failure returns false (see mailer_last_error()).
     */
    function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool
    {
        $GLOBALS['__bms_mailer_last_error'] = '';

        $get = function (string $key, $default = '') {
            return function_exists('get_setting') ? get_setting($key, $default) : $default;
        };

        // Resolve SMTP config: per-call override wins, else saved settings.
        $smtp = $opts['smtp'] ?? [];
        $host = trim((string)($smtp['host']        ?? $get('smtp_host')));
        $port = (int)            ($smtp['port']        ?? $get('smtp_port', 587));
        $user = trim((string)($smtp['username']    ?? $get('smtp_username')));
        $pass =        (string)($smtp['password']    ?? $get('smtp_password'));
        $enc  = strtolower(trim((string)($smtp['encryption'] ?? $get('smtp_encryption', 'tls'))));
        $fromEmail = trim((string)($opts['from_email'] ?? $smtp['from_email'] ?? $get('from_email', $get('company_email'))));
        $fromName  = trim((string)($opts['from_name']  ?? $smtp['from_name']  ?? $get('from_name',  $get('company_name', 'BMS'))));

        if ($host === '' || $user === '') {
            $GLOBALS['__bms_mailer_last_error'] = 'SMTP is not configured (set Host & Username in Settings > Email).';
            error_log('sendEmail: ' . $GLOBALS['__bms_mailer_last_error']);
            return false;
        }
        if ($fromEmail === '') {
            $fromEmail = $user; // sensible fallback
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port > 0 ? $port : 587;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 20;

            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            }

            // Use the bundled CA bundle for TLS verification (robust on WAMP/Windows).
            $caFile = __DIR__ . '/../includes/cacert.pem';
            if (is_file($caFile)) {
                $mail->SMTPOptions = ['ssl' => [
                    'cafile'            => $caFile,
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ]];
            }

            $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'BMS');

            foreach ((array)$to as $addr) {
                $addr = trim((string)$addr);
                if ($addr !== '') $mail->addAddress($addr);
            }
            if (!empty($opts['cc']))  foreach ((array)$opts['cc']  as $a) { if (trim((string)$a) !== '') $mail->addCC(trim((string)$a)); }
            if (!empty($opts['bcc'])) foreach ((array)$opts['bcc'] as $a) { if (trim((string)$a) !== '') $mail->addBCC(trim((string)$a)); }
            if (!empty($opts['reply_to'])) $mail->addReplyTo(trim((string)$opts['reply_to']));

            if (!empty($opts['attachments'])) {
                foreach ((array)$opts['attachments'] as $path) {
                    if (is_string($path) && is_file($path)) $mail->addAttachment($path);
                }
            }

            $wrap = $opts['wrap'] ?? true;
            $body = $wrap ? bms_email_wrap($subject, $htmlBody) : $htmlBody;

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = $opts['alt_body'] ?? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // PHPMailer's ErrorInfo is the most descriptive; fall back to the exception.
            $GLOBALS['__bms_mailer_last_error'] = $mail->ErrorInfo ?: $e->getMessage();
            error_log('sendEmail failed: ' . $GLOBALS['__bms_mailer_last_error']);
            return false;
        }
    }
}
