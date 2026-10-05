<?php
/**
 * core/account_recovery.php — getting a locked-out tenant admin back in.
 *
 * Runs inside ONE tenant's database (the subdomain decides which; $pdo from
 * includes/config.php is already pointed at it). The schema it relies on is
 * core/account_recovery_schema.php.
 *
 * ── The rules this file implements, and why ─────────────────────────────────
 *
 * 1. ONE ANSWER FOR EVERY CASE. recoveryRequestReset() returns the same thing
 *    whether the address exists, belongs to a non-admin, belongs to a disabled
 *    account, or matches nothing at all. Telling a visitor "no such account" or
 *    "that user is not an admin" lets anyone test addresses until they find the
 *    admin ones, then aim everything at those. The caller is given nothing to
 *    branch on, so a careless page cannot leak it either.
 *
 * 2. ADMINS ONLY. Ordinary staff already have a route back: their own admin can
 *    set a password for them in Settings → Users (app/constant/settings/edit_user.php).
 *    The admin is the one with nobody above them, so self-service recovery
 *    exists for exactly that gap and nothing wider.
 *
 * 3. THE TOKEN IS A BEARER CREDENTIAL. Stored as SHA-256, never raw: an attacker
 *    who reads the table still cannot sign in. Single-use, short-lived, and
 *    issuing a new one kills every earlier unused token for that account.
 *
 * 4. RESETTING ENDS EVERY SESSION. The reason someone resets a password is
 *    often that somebody else has it. Leaving the intruder's existing session
 *    signed in would make the reset theatre.
 *
 * 5. THE OLD ADDRESS IS ALWAYS TOLD. A silent password change is how an account
 *    takeover stays invisible. The notice is what makes it noticeable.
 *
 * Email is the only channel that can actually carry any of this today: there is
 * no SMS gateway in this codebase (api/test_sms_config.php makes no HTTP call).
 * password_resets.channel already allows 'sms' so adding one later is wiring,
 * not a redesign.
 */

require_once __DIR__ . '/account_recovery_schema.php';

if (!defined('RECOVERY_TOKEN_TTL_MIN'))      define('RECOVERY_TOKEN_TTL_MIN', 30);
if (!defined('RECOVERY_MAX_PER_IDENTIFIER')) define('RECOVERY_MAX_PER_IDENTIFIER', 3);
if (!defined('RECOVERY_MAX_PER_IP'))         define('RECOVERY_MAX_PER_IP', 10);
if (!defined('RECOVERY_WINDOW_MIN'))         define('RECOVERY_WINDOW_MIN', 60);

if (!function_exists('recoveryClientIp')) {
    function recoveryClientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip, 0, 45);
    }
}

if (!function_exists('recoveryNow')) {
    /**
     * "Now", decided in PHP and bound as a parameter — never MySQL's NOW().
     *
     * THIS IS NOT STYLE. A token's lifetime must not depend on which entry
     * point happened to open the connection. The app pins `SET time_zone =
     * '+03:00'` in roots.php, core/tenant_bootstrap.php, core/control_db.php
     * and core/tenant_migration_bootstrap.php — four places, each of which a
     * future entry point could forget. A connection that forgets reads a
     * different NOW() from the one that wrote the row: measured on this server,
     * ten hours apart. A token issued on one and checked on the other is either
     * dead on arrival or alive for hours after it should have died — and the
     * second of those is a security hole that would never show up in testing.
     *
     * PHP's clock is set once, for every entry point including CLI, by
     * includes/config.php (`date_default_timezone_set('Africa/Dar_es_Salaam')`),
     * so this is both consistent everywhere AND still local time — the stored
     * values read the same way as every other datetime in the schema, which
     * storing UTC here would have broken.
     */
    function recoveryNow(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('recoveryPasswordError')) {
    /**
     * The same rule actions/register_tenant.php and .claude/security.md §20
     * apply: 8+ characters with at least one letter and one digit. Stated once
     * here so the reset page and the OTP page cannot drift from signup.
     */
    function recoveryPasswordError(string $pw, string $confirm): ?string
    {
        if (strlen($pw) < 8)            return 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Za-z]/', $pw)) return 'Password must include at least one letter.';
        if (!preg_match('/\d/', $pw))   return 'Password must include at least one number.';
        if ($pw !== $confirm)           return 'The two passwords do not match.';
        return null;
    }
}

if (!function_exists('recoveryLogAttempt')) {
    function recoveryLogAttempt(PDO $pdo, string $identifier, string $ip): void
    {
        try {
            $pdo->prepare("INSERT INTO password_reset_attempts (identifier, request_ip, created_at) VALUES (?,?,?)")
                ->execute([mb_substr($identifier, 0, 191), $ip, recoveryNow()]);
        } catch (Throwable $e) {
            error_log('recoveryLogAttempt: ' . $e->getMessage());
        }
    }
}

if (!function_exists('recoveryThrottled')) {
    /**
     * Rate limit by account AND by source address.
     *
     * Per-identifier stops one account being mail-bombed into uselessness by
     * someone who knows the address. Per-IP stops a sweep across many addresses
     * from one place. Both are needed: either alone leaves the other open.
     */
    function recoveryThrottled(PDO $pdo, string $identifier, string $ip): bool
    {
        try {
            $since = date('Y-m-d H:i:s', time() - ((int)RECOVERY_WINDOW_MIN * 60));

            $st = $pdo->prepare("
                SELECT COUNT(*) FROM password_reset_attempts
                 WHERE identifier = ? AND created_at > ?
            ");
            $st->execute([mb_substr($identifier, 0, 191), $since]);
            if ((int)$st->fetchColumn() >= RECOVERY_MAX_PER_IDENTIFIER) return true;

            $st = $pdo->prepare("
                SELECT COUNT(*) FROM password_reset_attempts
                 WHERE request_ip = ? AND created_at > ?
            ");
            $st->execute([$ip, $since]);
            if ((int)$st->fetchColumn() >= RECOVERY_MAX_PER_IP) return true;

            return false;
        } catch (Throwable $e) {
            // A broken throttle must not become an open door.
            error_log('recoveryThrottled: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('recoveryFindAdminByEmail')) {
    /**
     * The one active admin that address belongs to, or null.
     *
     * LIMIT 1 with an explicit "exactly one" check rather than "take the first":
     * users.email has no UNIQUE constraint (and cannot get one — staff may share
     * a company address), so if two accounts answer to it there is no way to
     * know which was meant. Sending a reset for a guess is worse than sending
     * nothing, and the caller cannot tell the difference anyway.
     */
    function recoveryFindAdminByEmail(PDO $pdo, string $email): ?array
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        try {
            $st = $pdo->prepare("
                SELECT user_id, username, email, first_name, last_name, is_admin, is_active
                  FROM users
                 WHERE email = ? AND is_admin = 1 AND is_active = 1
                 LIMIT 2
            ");
            $st->execute([$email]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                if (count($rows) > 1) {
                    error_log('recoveryFindAdminByEmail: ' . count($rows) . ' admin accounts share ' . $email . ' — refusing to guess');
                }
                return null;
            }
            return $rows[0];
        } catch (Throwable $e) {
            error_log('recoveryFindAdminByEmail: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('recoveryIssueToken')) {
    /**
     * Mint a single-use token for one account and return the RAW value.
     *
     * The raw token is returned to the caller to put in an email and is then
     * dropped; only its SHA-256 is persisted. Any earlier unused token for the
     * same account is expired first, so a forwarded or intercepted older link
     * stops working the moment a newer one is requested.
     *
     * @param string $kind 'self_service' | 'operator_otp'
     */
    function recoveryIssueToken(
        PDO $pdo,
        int $userId,
        string $destination,
        string $kind = 'self_service',
        ?int $issuedBy = null,
        string $channel = 'email',
        ?int $ttlMinutes = null
    ): string {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $ttl  = max(5, (int)($ttlMinutes ?? RECOVERY_TOKEN_TTL_MIN));
        $now  = recoveryNow();
        $exp  = date('Y-m-d H:i:s', time() + ($ttl * 60));

        $pdo->prepare("
            UPDATE password_resets SET used_at = ?
             WHERE user_id = ? AND used_at IS NULL AND expires_at > ?
        ")->execute([$now, $userId, $now]);

        $pdo->prepare("
            INSERT INTO password_resets
                   (user_id, token_hash, channel, destination, kind, issued_by,
                    expires_at, request_ip, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $userId, $hash, $channel, mb_substr($destination, 0, 191),
            $kind, $issuedBy, $exp, recoveryClientIp(), $now,
        ]);

        return $raw;
    }
}

if (!function_exists('recoveryVerifyToken')) {
    /**
     * The account a raw token belongs to, or null if it is unknown, already
     * used, or expired. Looks the row up BY HASH, so a stolen database dump
     * cannot be replayed and no comparison runs over attacker-supplied text.
     *
     * @return array{reset_id:int,user_id:int,kind:string,username:string,email:string}|null
     */
    function recoveryVerifyToken(PDO $pdo, string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) return null;

        try {
            $st = $pdo->prepare("
                SELECT pr.reset_id, pr.user_id, pr.kind,
                       COALESCE(u.username,'') AS username, COALESCE(u.email,'') AS email
                  FROM password_resets pr
                  JOIN users u ON u.user_id = pr.user_id
                 WHERE pr.token_hash = ?
                   AND pr.used_at IS NULL
                   AND pr.expires_at > ?
                   AND u.is_active = 1
                 LIMIT 1
            ");
            $st->execute([hash('sha256', $rawToken), recoveryNow()]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            return [
                'reset_id' => (int)$row['reset_id'],
                'user_id'  => (int)$row['user_id'],
                'kind'     => (string)$row['kind'],
                'username' => (string)$row['username'],
                'email'    => (string)$row['email'],
            ];
        } catch (Throwable $e) {
            error_log('recoveryVerifyToken: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('recoveryRevokeAllSessions')) {
    /**
     * Close every open session for this account.
     *
     * bmsEnforceSessionLifecycle() (core/session_tracker.php) signs a browser
     * out on its next request once its user_sessions row is closed, so this is
     * what actually turns an intruder out rather than merely changing the
     * password they already used.
     *
     * logout_type is VARCHAR(20), so 'password_reset' needs no schema change;
     * Login History renders it with its own badge.
     */
    function recoveryRevokeAllSessions(PDO $pdo, int $userId): int
    {
        try {
            $now = recoveryNow();
            $st  = $pdo->prepare("
                UPDATE user_sessions
                   SET logout_at = ?,
                       duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, login_at, ?)),
                       logout_type = 'password_reset',
                       revoked_at = ?
                 WHERE user_id = ? AND logout_at IS NULL
            ");
            $st->execute([$now, $now, $now, $userId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            // The table may not exist on an old tenant. Never let this stop a
            // reset the user is entitled to.
            error_log('recoveryRevokeAllSessions: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('recoveryCompleteReset')) {
    /**
     * Spend a token and set the new password.
     *
     * Order matters: the token is marked used in the SAME statement that checks
     * it is still unused, so two simultaneous submissions of one link cannot
     * both succeed. Only then is the password written.
     *
     * @return array{ok:bool, error:?string, user_id:?int}
     */
    function recoveryCompleteReset(PDO $pdo, string $rawToken, string $password, string $confirm): array
    {
        if ($err = recoveryPasswordError($password, $confirm)) {
            return ['ok' => false, 'error' => $err, 'user_id' => null];
        }

        $row = recoveryVerifyToken($pdo, $rawToken);
        if (!$row) {
            return ['ok' => false, 'error' =>
                'This link is no longer valid. It may have expired, already been used, '
              . 'or been replaced by a newer one. Please request a new link.', 'user_id' => null];
        }

        // Claim-then-act. rowCount() === 0 means somebody else spent it between
        // the check above and this update.
        $claim = $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE reset_id = ? AND used_at IS NULL");
        $claim->execute([recoveryNow(), $row['reset_id']]);
        if ($claim->rowCount() === 0) {
            return ['ok' => false, 'error' => 'This link has already been used. Please request a new one.', 'user_id' => null];
        }

        $pdo->prepare("
            UPDATE users
               SET password = ?, password_changed_at = ?, must_change_password = 0
             WHERE user_id = ?
        ")->execute([password_hash($password, PASSWORD_DEFAULT), recoveryNow(), $row['user_id']]);

        recoveryRevokeAllSessions($pdo, (int)$row['user_id']);
        recoveryNotifyPasswordChanged($pdo, (int)$row['user_id']);

        return ['ok' => true, 'error' => null, 'user_id' => (int)$row['user_id']];
    }
}

if (!function_exists('recoveryBaseUrl')) {
    /** Absolute origin of the tenant site handling this request. */
    function recoveryBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }
}

if (!function_exists('recoveryRequestReset')) {
    /**
     * Handle "I forgot my password".
     *
     * Returns NOTHING the caller can branch on — no boolean for "found", no
     * distinct error. The page prints one fixed sentence either way. The only
     * signal that ever differs is the throttle, and that is keyed on behaviour
     * (how many attempts), never on whether an account exists.
     *
     * @return array{throttled:bool}
     */
    function recoveryRequestReset(PDO $pdo, string $email, ?string $companyName = null): array
    {
        $email = trim($email);
        $ip    = recoveryClientIp();

        if (recoveryThrottled($pdo, $email, $ip)) {
            return ['throttled' => true];
        }
        recoveryLogAttempt($pdo, $email, $ip);

        $user = recoveryFindAdminByEmail($pdo, $email);
        if ($user) {
            try {
                $raw  = recoveryIssueToken($pdo, (int)$user['user_id'], $email, 'self_service');
                $link = recoveryBaseUrl() . '/forgot-password?token=' . $raw;
                recoverySendResetEmail($email, $user, $link, $companyName);
            } catch (Throwable $e) {
                // Logged, never surfaced: an error here would tell the visitor
                // the address matched something.
                error_log('recoveryRequestReset: ' . $e->getMessage());
            }
        }

        return ['throttled' => false];
    }
}

if (!function_exists('recoveryRequestUsername')) {
    /**
     * Handle "I forgot my username".
     *
     * Same silence as a password request. The username is sent TO the address
     * on file — never shown on screen, which would turn the form into a lookup
     * tool for guessing which addresses are admins.
     *
     * @return array{throttled:bool}
     */
    function recoveryRequestUsername(PDO $pdo, string $email, ?string $companyName = null): array
    {
        $email = trim($email);
        $ip    = recoveryClientIp();

        if (recoveryThrottled($pdo, $email, $ip)) {
            return ['throttled' => true];
        }
        recoveryLogAttempt($pdo, $email, $ip);

        $user = recoveryFindAdminByEmail($pdo, $email);
        if ($user) {
            try {
                recoverySendUsernameEmail($email, $user, $companyName);
            } catch (Throwable $e) {
                error_log('recoveryRequestUsername: ' . $e->getMessage());
            }
        }

        return ['throttled' => false];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Email bodies. Sent through core/mailer.php, which already routes via the
// platform relay for tenants that use it, so recovery works for a company that
// has never configured SMTP of its own.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('recoveryMailer')) {
    function recoveryMailer(): bool
    {
        if (!function_exists('sendEmail')) {
            @require_once __DIR__ . '/mailer.php';
        }
        return function_exists('sendEmail');
    }
}

if (!function_exists('recoveryDisplayName')) {
    function recoveryDisplayName(array $user): string
    {
        $n = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return $n !== '' ? $n : (string)($user['username'] ?? 'there');
    }
}

if (!function_exists('recoverySendResetEmail')) {
    function recoverySendResetEmail(string $to, array $user, string $link, ?string $companyName): bool
    {
        if (!recoveryMailer()) return false;

        $name = htmlspecialchars(recoveryDisplayName($user), ENT_QUOTES, 'UTF-8');
        $co   = htmlspecialchars((string)($companyName ?: 'your company'), ENT_QUOTES, 'UTF-8');
        $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $mins = (int)RECOVERY_TOKEN_TTL_MIN;

        $body = "<p>Hello {$name},</p>"
              . "<p>We received a request to reset the password for your administrator account at <strong>{$co}</strong>.</p>"
              . '<p style="margin:24px 0;"><a href="' . $safe . '" '
              . 'style="background:#0d6efd;color:#fff;padding:12px 22px;border-radius:6px;'
              . 'text-decoration:none;display:inline-block;font-weight:600;">Choose a new password</a></p>'
              . "<p>This link can be used <strong>once</strong> and expires in <strong>{$mins} minutes</strong>.</p>"
              . '<p>If the button does not work, copy this address into your browser:<br>'
              . '<span style="font-size:12px;color:#555;word-break:break-all;">' . $safe . '</span></p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="color:#666;font-size:13px;"><strong>Did not ask for this?</strong> '
              . 'You can ignore this email — your password has not changed. '
              . 'If you keep receiving these, somebody may know your email address; tell your system provider.</p>';

        return sendEmail($to, 'Reset your password — ' . ($companyName ?: 'BMS'), $body);
    }
}

if (!function_exists('recoverySendUsernameEmail')) {
    function recoverySendUsernameEmail(string $to, array $user, ?string $companyName): bool
    {
        if (!recoveryMailer()) return false;

        $name = htmlspecialchars(recoveryDisplayName($user), ENT_QUOTES, 'UTF-8');
        $co   = htmlspecialchars((string)($companyName ?: 'your company'), ENT_QUOTES, 'UTF-8');
        $un   = htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8');

        $body = "<p>Hello {$name},</p>"
              . "<p>You asked us to remind you of the username for your administrator account at <strong>{$co}</strong>.</p>"
              . '<p style="margin:20px 0;">Your username is: '
              . '<strong style="font-size:17px;background:#f1f3f5;padding:6px 12px;border-radius:5px;'
              . 'display:inline-block;">' . $un . '</strong></p>'
              . '<p>Sign in with it and your usual password.</p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="color:#666;font-size:13px;">If you did not ask for this, you can ignore this email. '
              . 'Nothing about your account has changed.</p>';

        return sendEmail($to, 'Your username — ' . ($companyName ?: 'BMS'), $body);
    }
}

if (!function_exists('recoveryNotifyPasswordChanged')) {
    /**
     * Tell the account holder their password just changed.
     *
     * Sent AFTER the change, to the address on file, and never suppressed: this
     * is the single notice that makes an account takeover visible to the person
     * it is happening to. Failing to send must not fail the reset itself —
     * the user is already locked out and the new password already works.
     */
    function recoveryNotifyPasswordChanged(PDO $pdo, int $userId, ?string $byWhom = null): bool
    {
        try {
            $st = $pdo->prepare("SELECT username, email, first_name, last_name FROM users WHERE user_id = ?");
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || trim((string)$u['email']) === '' || !recoveryMailer()) return false;

            $name = htmlspecialchars(recoveryDisplayName($u), ENT_QUOTES, 'UTF-8');
            $when = date('d M Y H:i');
            $who  = $byWhom !== null
                ? ' by ' . htmlspecialchars($byWhom, ENT_QUOTES, 'UTF-8')
                : '';

            $body = "<p>Hello {$name},</p>"
                  . "<p>The password for your account was changed{$who} on <strong>{$when}</strong>. "
                  . 'You have been signed out everywhere and will need to sign in again.</p>'
                  . '<p style="color:#b02a37;"><strong>If this was not you, act now:</strong> '
                  . 'whoever changed it can sign in as you. Contact your system provider immediately.</p>';

            return sendEmail((string)$u['email'], 'Your password was changed', $body);
        } catch (Throwable $e) {
            error_log('recoveryNotifyPasswordChanged: ' . $e->getMessage());
            return false;
        }
    }
}
