<?php
/**
 * core/superadmin_recovery.php — the platform operator's own way back in.
 *
 * Before this, an operator who forgot their password, or who tripped their own
 * five-attempt lockout in core/superadmin_auth.php, had one route back:
 * scripts/create_superadmin.php or raw SQL, over SSH. That is not a recovery
 * path; it is an outage that happens to have a shell prompt. It is also the
 * exact thing this whole piece of work exists to remove.
 *
 * Deliberately a near-copy of the tenant-side flow in core/account_recovery.php
 * rather than a shared abstraction over both. They look alike today, but they
 * are different trust domains against different databases — tenant users in a
 * tenant schema, operators in the control database — and the one thing that
 * must never happen is a change made for one quietly altering the other. Two
 * short files that can be read end to end beat one clever one.
 *
 * The rules are the same, and for the same reasons:
 *   - one identical answer whatever the address, so the form cannot be used to
 *     enumerate operator accounts
 *   - SHA-256 of the token only; single-use; short-lived; a newer link kills
 *     the older one
 *   - throttled per account and per IP, failing closed
 *   - time computed in PHP, never MySQL NOW() (see recoveryNow()'s note)
 *
 * One difference, and it is the point: completing a reset also clears
 * `failed_attempts` and `locked_until`. Someone locked out by the attempt
 * counter is, more often than not, the rightful owner typing a half-remembered
 * password — and proving control of the account's mailbox is a stronger claim
 * than the counter they tripped doing it.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/superadmin_auth.php';
require_once __DIR__ . '/account_recovery.php';   // recoveryNow(), recoveryPasswordError(), mailer helpers

if (!defined('SA_RECOVERY_TTL_MIN'))       define('SA_RECOVERY_TTL_MIN', 30);
if (!defined('SA_RECOVERY_MAX_PER_EMAIL')) define('SA_RECOVERY_MAX_PER_EMAIL', 3);
if (!defined('SA_RECOVERY_MAX_PER_IP'))    define('SA_RECOVERY_MAX_PER_IP', 10);
if (!defined('SA_RECOVERY_WINDOW_MIN'))    define('SA_RECOVERY_WINDOW_MIN', 60);

if (!function_exists('saRecoveryThrottled')) {
    function saRecoveryThrottled(PDO $ctrl, string $email, string $ip): bool
    {
        try {
            $since = date('Y-m-d H:i:s', time() - (SA_RECOVERY_WINDOW_MIN * 60));

            $st = $ctrl->prepare("SELECT COUNT(*) FROM superadmin_reset_attempts
                                   WHERE identifier = ? AND created_at > ?");
            $st->execute([mb_substr($email, 0, 191), $since]);
            if ((int)$st->fetchColumn() >= SA_RECOVERY_MAX_PER_EMAIL) return true;

            $st = $ctrl->prepare("SELECT COUNT(*) FROM superadmin_reset_attempts
                                   WHERE request_ip = ? AND created_at > ?");
            $st->execute([$ip, $since]);
            return (int)$st->fetchColumn() >= SA_RECOVERY_MAX_PER_IP;
        } catch (Throwable $e) {
            error_log('saRecoveryThrottled: ' . $e->getMessage());
            return true;   // fail closed
        }
    }
}

if (!function_exists('saRecoveryRequest')) {
    /**
     * Handle "I forgot my operator password".
     *
     * Returns only whether the throttle fired — never whether the address
     * matched an operator. The panel's login page already refuses to say
     * whether an account exists (attemptSuperadminLogin's generic failure);
     * this form must not undo that.
     *
     * @return array{throttled:bool}
     */
    function saRecoveryRequest(string $email, string $baseUrl): array
    {
        $email = trim($email);
        $ip    = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        try {
            $ctrl = getControlPdo();
        } catch (Throwable $e) {
            error_log('saRecoveryRequest: control DB unreachable: ' . $e->getMessage());
            return ['throttled' => false];   // say nothing different to the visitor
        }

        if (saRecoveryThrottled($ctrl, $email, $ip)) return ['throttled' => true];

        try {
            $ctrl->prepare("INSERT INTO superadmin_reset_attempts (identifier, request_ip, created_at) VALUES (?,?,?)")
                 ->execute([mb_substr($email, 0, 191), $ip, recoveryNow()]);
        } catch (Throwable $e) {
            error_log('saRecoveryRequest log: ' . $e->getMessage());
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['throttled' => false];

        try {
            $st = $ctrl->prepare("SELECT id, name, email FROM superadmins WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $sa = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sa) return ['throttled' => false];

            $raw  = bin2hex(random_bytes(32));
            $now  = recoveryNow();
            $exp  = date('Y-m-d H:i:s', time() + (SA_RECOVERY_TTL_MIN * 60));

            // Any link already in flight stops working the moment a newer one
            // is asked for.
            $ctrl->prepare("UPDATE superadmin_password_resets SET used_at = ?
                             WHERE superadmin_id = ? AND used_at IS NULL AND expires_at > ?")
                 ->execute([$now, (int)$sa['id'], $now]);

            $ctrl->prepare("INSERT INTO superadmin_password_resets
                            (superadmin_id, token_hash, destination, expires_at, request_ip, created_at)
                            VALUES (?,?,?,?,?,?)")
                 ->execute([(int)$sa['id'], hash('sha256', $raw), mb_substr($email, 0, 191), $exp, $ip, $now]);

            saRecoverySendEmail($sa, rtrim($baseUrl, '/') . '/forgot?token=' . $raw);
        } catch (Throwable $e) {
            error_log('saRecoveryRequest: ' . $e->getMessage());
        }

        return ['throttled' => false];
    }
}

if (!function_exists('saRecoveryVerify')) {
    /**
     * @return array{reset_id:int, superadmin_id:int, name:string, email:string}|null
     */
    function saRecoveryVerify(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) return null;

        try {
            $st = getControlPdo()->prepare("
                SELECT r.reset_id, r.superadmin_id, s.name, s.email
                  FROM superadmin_password_resets r
                  JOIN superadmins s ON s.id = r.superadmin_id
                 WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?
                 LIMIT 1
            ");
            $st->execute([hash('sha256', $rawToken), recoveryNow()]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;

            return [
                'reset_id'      => (int)$r['reset_id'],
                'superadmin_id' => (int)$r['superadmin_id'],
                'name'          => (string)$r['name'],
                'email'         => (string)$r['email'],
            ];
        } catch (Throwable $e) {
            error_log('saRecoveryVerify: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('saRecoveryComplete')) {
    /**
     * Spend the token, set the password, and lift any lockout.
     *
     * @return array{ok:bool, error:?string}
     */
    function saRecoveryComplete(string $rawToken, string $password, string $confirm): array
    {
        // The panel's own rule — strength AND confirmation, both already
        // expressed in superadminPasswordError() — so a reset cannot set a
        // password the profile page would have refused.
        if ($err = superadminPasswordError($password, $confirm)) {
            return ['ok' => false, 'error' => $err];
        }

        $row = saRecoveryVerify($rawToken);
        if (!$row) {
            return ['ok' => false, 'error' =>
                'This link is no longer valid. It may have expired, already been used, or been '
              . 'replaced by a newer one. Please request a new one.'];
        }

        try {
            $ctrl = getControlPdo();

            // Claim-then-act: marking it used is the same statement that
            // checks it is unused, so two clicks cannot both win.
            $claim = $ctrl->prepare("UPDATE superadmin_password_resets SET used_at = ?
                                      WHERE reset_id = ? AND used_at IS NULL");
            $claim->execute([recoveryNow(), $row['reset_id']]);
            if ($claim->rowCount() === 0) {
                return ['ok' => false, 'error' => 'This link has already been used. Please request a new one.'];
            }

            // locked_until / failed_attempts cleared deliberately — see the
            // file header. Proving control of the mailbox outranks a counter
            // the rightful owner most likely tripped themselves.
            $ctrl->prepare("UPDATE superadmins
                               SET password_hash = ?, failed_attempts = 0, locked_until = NULL
                             WHERE id = ?")
                 ->execute([password_hash($password, PASSWORD_DEFAULT), $row['superadmin_id']]);

            saRecoveryNotifyChanged($row);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('saRecoveryComplete: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The change could not be saved. Please try again.'];
        }
    }
}

if (!function_exists('saRecoverySendEmail')) {
    function saRecoverySendEmail(array $sa, string $link): bool
    {
        if (!recoveryMailer()) return false;

        $name = htmlspecialchars((string)($sa['name'] ?: 'there'), ENT_QUOTES, 'UTF-8');
        $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $mins = (int)SA_RECOVERY_TTL_MIN;

        $body = "<p>Hello {$name},</p>"
              . '<p>We received a request to reset the password for your <strong>platform operator</strong> '
              . 'account.</p>'
              . '<p style="margin:24px 0;"><a href="' . $safe . '" '
              . 'style="background:#0d6efd;color:#fff;padding:12px 22px;border-radius:6px;'
              . 'text-decoration:none;display:inline-block;font-weight:600;">Choose a new password</a></p>'
              . "<p>This link works <strong>once</strong> and expires in <strong>{$mins} minutes</strong>. "
              . 'Using it also lifts any sign-in lockout on the account.</p>'
              . '<p>If the button does not work, copy this address into your browser:<br>'
              . '<span style="font-size:12px;color:#555;word-break:break-all;">' . $safe . '</span></p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="color:#b02a37;font-size:13px;"><strong>Did not ask for this?</strong> '
              . 'Your password has not changed. This account administers every company on the platform — '
              . 'if you are not expecting this email, treat it as an attempt to take it over.</p>';

        return sendEmail((string)$sa['email'], 'Reset your platform operator password',
            $body, ['wrap_brand' => 'BJP Technologies / BMS']);
    }
}

if (!function_exists('saRecoveryNotifyChanged')) {
    function saRecoveryNotifyChanged(array $row): bool
    {
        if (!recoveryMailer()) return false;

        $name = htmlspecialchars((string)($row['name'] ?: 'there'), ENT_QUOTES, 'UTF-8');
        $when = date('d M Y H:i');

        $body = "<p>Hello {$name},</p>"
              . "<p>The password for your platform operator account was reset on <strong>{$when}</strong>, "
              . 'and any sign-in lockout on it has been lifted.</p>'
              . '<p style="color:#b02a37;"><strong>If this was not you, act immediately.</strong> '
              . 'This account can reach every company on the platform.</p>';

        return sendEmail((string)$row['email'], 'Your platform operator password was reset',
            $body, ['wrap_brand' => 'BJP Technologies / BMS']);
    }
}
