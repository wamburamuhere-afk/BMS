<?php
/**
 * core/tenant_account_recovery.php — the ONE place the superadmin panel is
 * allowed to write to a tenant's own `users` table.
 *
 * ── Why this is a separate file, and why it is the only one ─────────────────
 *
 * core/tenant_admin.php holds the rule that the panel never opens a tenant's
 * database, and two narrow, deliberately un-shared exceptions to it
 * (tenantUserDirectory, tenantUsageSnapshotFor), each with its own connection
 * code so neither becomes a general-purpose "open any tenant's database"
 * utility. Those are READS. This file adds WRITES, which is a bigger thing, so
 * it gets the same treatment one level up: everything the panel may write to a
 * tenant account lives here, behind one private connection helper that nothing
 * outside this file can reach. One file to audit, one file to delete.
 *
 * ── What an operator may and may not do ─────────────────────────────────────
 *
 * May: send the admin a one-time code, correct the address that code goes to,
 * correct a username, and re-enable an account that was switched off.
 *
 * May NOT: set, see, or choose a tenant's password, and may not sign in as
 * them. There is no function here that writes `users.password`. The operator
 * never learns the code either — issueAdminOtp() returns only a MASKED
 * destination, because a code the operator can read is a code the operator can
 * use, which is the whole thing this design exists to avoid.
 *
 * The owner ends up choosing their own password, through the same
 * forgot-password.php screen a self-service reset uses, so there is exactly one
 * code path that ever sets a tenant password and it is driven by the tenant.
 */

require_once __DIR__ . '/tenant_admin.php';
require_once __DIR__ . '/account_recovery.php';
require_once __DIR__ . '/account_recovery_schema.php';

if (!function_exists('tarOpenTenant')) {
    /**
     * Open one tenant's database, by its OWN stored credentials.
     *
     * Private to this file by convention and by name: `tar` = tenant account
     * recovery. Never call it from anywhere else — add the operation here
     * instead, so the list of writes the panel can perform stays readable in
     * one place.
     *
     * @return array{0:?PDO,1:?array} [connection, tenant row] — both null on failure.
     */
    function tarOpenTenant(int $tenantId): array
    {
        try {
            $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenantId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['status'] === 'deleted') return [null, null];

            $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($pw === null) return [null, null];

            $pdo = new PDO(
                'mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
                $t['db_username'], $pw,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            // Same pinning every other bootstrap applies, so timestamps written
            // here read the same way as the tenant's own.
            $pdo->exec("SET time_zone = '+03:00'");
            return [$pdo, $t];
        } catch (Throwable $e) {
            error_log('tarOpenTenant(' . $tenantId . '): ' . $e->getMessage());
            return [null, null];
        }
    }
}

if (!function_exists('tarMaskEmail')) {
    /**
     * j***@gmail.com — enough for the operator to confirm with the caller that
     * it is the right inbox, not enough to learn an address they did not have.
     */
    function tarMaskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) return '***';
        $user = substr($email, 0, $at);
        $rest = substr($email, $at);
        return substr($user, 0, 1) . str_repeat('*', max(2, min(6, strlen($user) - 1))) . $rest;
    }
}

if (!function_exists('tarFetchUser')) {
    /** One account from the tenant, or null. */
    function tarFetchUser(PDO $pdo, int $userId): ?array
    {
        $st = $pdo->prepare("
            SELECT user_id, username, email, phone, first_name, last_name, is_admin, is_active
              FROM users WHERE user_id = ? LIMIT 1
        ");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('tenantIssueAdminOtp')) {
    /**
     * Email the tenant's administrator a one-time code that lets them set a new
     * password themselves.
     *
     * This replaces the thing an operator would otherwise do — sign in as the
     * customer, or type a password and read it down the phone. Neither is
     * needed: the owner regains control without anybody outside the company
     * ever holding their password.
     *
     * Admins only, and active accounts only. An account with no address on file
     * is refused with a message that says what to fix, rather than silently
     * doing nothing — unlike the public form, the operator here is trusted and
     * an honest error is what lets them help.
     *
     * @return array{ok:bool, error:?string, sent_to:?string, expires_minutes:?int}
     */
    function tenantIssueAdminOtp(int $tenantId, int $userId): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'sent_to' => null, 'expires_minutes' => null];

        [$pdo, $t] = tarOpenTenant($tenantId);
        if (!$pdo) return $fail('Could not reach this tenant right now.');

        try {
            accountRecoveryEnsureSchema($pdo);
            $u = tarFetchUser($pdo, $userId);
            if (!$u)                        return $fail('That account no longer exists.');
            if ((int)$u['is_admin'] !== 1)  return $fail('Only an administrator account can be sent a recovery code.');
            if ((int)$u['is_active'] !== 1) return $fail('That account is deactivated. Re-enable it first, then send a code.');

            $email = trim((string)$u['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $fail('This account has no email address on file, so there is nowhere to send a code. '
                           . 'Set a recovery email for it first — verify who you are speaking to before you do.');
            }

            // Typeable, because an operator on the phone may have to read it
            // out. One hour rather than the self-service 30 minutes: this is a
            // supported call, and the owner may need to go and find the email.
            $code = recoveryIssueToken(
                $pdo, $userId, $email, 'operator_otp',
                (int)(currentSuperadmin()['id'] ?? 0), 'email', RECOVERY_OTP_TTL_MIN, 'code'
            );

            tarSendOtpEmail($email, $u, $code, (string)$t['company_name'], (string)$t['subdomain']);

            // The code itself is NEVER logged and never returned — see the
            // file header. Only that one was issued, and to whom.
            logTenantAdminAction($tenantId, $t['subdomain'], 'admin_otp_issued',
                'user #' . $userId . ' (' . $u['username'] . ') → ' . tarMaskEmail($email));

            return ['ok' => true, 'error' => null,
                    'sent_to' => tarMaskEmail($email),
                    'expires_minutes' => (int)RECOVERY_OTP_TTL_MIN];
        } catch (Throwable $e) {
            error_log('tenantIssueAdminOtp(' . $tenantId . ',' . $userId . '): ' . $e->getMessage());
            return $fail('Could not issue a recovery code. Please try again.');
        }
    }
}

if (!function_exists('tenantSetUserEmail')) {
    /**
     * Correct the address an account's recovery mail goes to.
     *
     * The escape hatch for the one case self-service cannot solve: the admin
     * has no address on file, or has lost access to the one that is. Because
     * changing this address decides who can take over the account, it is the
     * most dangerous thing in this file, so:
     *
     *   - the OLD address, if there was one, is told that it changed. An
     *     attacker who talks an operator into redirecting recovery cannot do it
     *     silently.
     *   - email_verified_at is cleared: an address an operator typed has not
     *     been proven to belong to anyone.
     *   - every unused reset token is burned, so a code already in flight to
     *     the old address stops working.
     *
     * @return array{ok:bool, error:?string}
     */
    function tenantSetUserEmail(int $tenantId, int $userId, string $newEmail): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m];

        $newEmail = trim($newEmail);
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $fail('Please enter a valid email address.');
        }
        if (mb_strlen($newEmail) > 100) return $fail('That email address is too long.');

        [$pdo, $t] = tarOpenTenant($tenantId);
        if (!$pdo) return $fail('Could not reach this tenant right now.');

        try {
            accountRecoveryEnsureSchema($pdo);
            $u = tarFetchUser($pdo, $userId);
            if (!$u) return $fail('That account no longer exists.');

            $old = trim((string)$u['email']);
            if (strcasecmp($old, $newEmail) === 0) {
                return $fail('That is already the address on this account.');
            }

            // Another ACTIVE ADMIN on the same address would make
            // recoveryFindAdminByEmail() refuse to act for either of them.
            $clash = $pdo->prepare("SELECT COUNT(*) FROM users
                                     WHERE email = ? AND user_id <> ? AND is_admin = 1 AND is_active = 1");
            $clash->execute([$newEmail, $userId]);
            if ((int)$clash->fetchColumn() > 0) {
                return $fail('Another active administrator already uses that address. '
                           . 'Password recovery cannot tell two admins apart, so each needs their own.');
            }

            $pdo->prepare("UPDATE users SET email = ?, email_verified_at = NULL WHERE user_id = ?")
                ->execute([$newEmail, $userId]);

            $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL")
                ->execute([recoveryNow(), $userId]);

            if ($old !== '' && filter_var($old, FILTER_VALIDATE_EMAIL)) {
                tarNotifyEmailChanged($old, $newEmail, $u, (string)$t['company_name']);
            }

            logTenantAdminAction($tenantId, $t['subdomain'], 'admin_email_changed',
                'user #' . $userId . ' (' . $u['username'] . '): '
                . ($old !== '' ? tarMaskEmail($old) : '(none)') . ' → ' . tarMaskEmail($newEmail));

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('tenantSetUserEmail(' . $tenantId . ',' . $userId . '): ' . $e->getMessage());
            return $fail('Could not update the email address. Please try again.');
        }
    }
}

if (!function_exists('tenantSetUserUsername')) {
    /**
     * Change an account's sign-in name.
     *
     * Needed because `username` was never editable anywhere: not in the user's
     * own profile, not in the panel. A self-registered owner is stuck signing
     * in with the phone number provisioning used as their username, and a typo
     * made at onboarding was permanent.
     *
     * @return array{ok:bool, error:?string}
     */
    function tenantSetUserUsername(int $tenantId, int $userId, string $newUsername): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m];

        $newUsername = trim($newUsername);
        if ($newUsername === '')                           return $fail('Please enter a username.');
        if (mb_strlen($newUsername) < 3)                   return $fail('A username must be at least 3 characters.');
        if (mb_strlen($newUsername) > 50)                  return $fail('A username must be 50 characters or fewer.');
        if (!preg_match('/^[A-Za-z0-9._@+\-]+$/', $newUsername)) {
            return $fail('A username may contain letters, numbers and . _ @ + - only.');
        }

        [$pdo, $t] = tarOpenTenant($tenantId);
        if (!$pdo) return $fail('Could not reach this tenant right now.');

        try {
            accountRecoveryEnsureSchema($pdo);
            $u = tarFetchUser($pdo, $userId);
            if (!$u) return $fail('That account no longer exists.');
            if ((string)$u['username'] === $newUsername) {
                return $fail('That is already this account\'s username.');
            }

            // Checked here for a readable message; users.username is also
            // UNIQUE at the database (account recovery Phase 0) so a race
            // cannot slip a duplicate past this.
            $clash = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id <> ?");
            $clash->execute([$newUsername, $userId]);
            if ((int)$clash->fetchColumn() > 0) {
                return $fail('Another account in this company already signs in with that username.');
            }

            $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?")
                ->execute([$newUsername, $userId]);

            tarNotifyUsernameChanged($u, $newUsername, (string)$t['company_name']);

            logTenantAdminAction($tenantId, $t['subdomain'], 'admin_username_changed',
                'user #' . $userId . ': ' . $u['username'] . ' → ' . $newUsername);

            return ['ok' => true, 'error' => null];
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                return $fail('Another account in this company already signs in with that username.');
            }
            error_log('tenantSetUserUsername(' . $tenantId . ',' . $userId . '): ' . $e->getMessage());
            return $fail('Could not update the username. Please try again.');
        } catch (Throwable $e) {
            error_log('tenantSetUserUsername(' . $tenantId . ',' . $userId . '): ' . $e->getMessage());
            return $fail('Could not update the username. Please try again.');
        }
    }
}

if (!function_exists('tenantReactivateUser')) {
    /**
     * Switch an account back on.
     *
     * There is no per-user lockout on the tenant side — actions/login.php
     * refuses an account only when `is_active` is 0 — so "locked out" here
     * means somebody deactivated it. Normally the company's own admin undoes
     * that in Settings › Users. The case this exists for is the one where the
     * account that was switched off is the LAST active administrator: then
     * nobody inside the company can reach the page that would switch it back
     * on, and before this the only way out was SQL over SSH.
     *
     * @return array{ok:bool, error:?string}
     */
    function tenantReactivateUser(int $tenantId, int $userId): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m];

        [$pdo, $t] = tarOpenTenant($tenantId);
        if (!$pdo) return $fail('Could not reach this tenant right now.');

        try {
            $u = tarFetchUser($pdo, $userId);
            if (!$u) return $fail('That account no longer exists.');
            if ((int)$u['is_active'] === 1) return $fail('That account is already active.');

            $pdo->prepare("UPDATE users SET is_active = 1 WHERE user_id = ?")->execute([$userId]);

            logTenantAdminAction($tenantId, $t['subdomain'], 'admin_account_reactivated',
                'user #' . $userId . ' (' . $u['username'] . ')');

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('tenantReactivateUser(' . $tenantId . ',' . $userId . '): ' . $e->getMessage());
            return $fail('Could not re-enable that account. Please try again.');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Emails. All go to the TENANT's people, never to the operator.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('tarSendOtpEmail')) {
    function tarSendOtpEmail(string $to, array $u, string $code, string $company, string $subdomain): bool
    {
        if (!recoveryMailer()) return false;

        $name    = htmlspecialchars(recoveryDisplayName($u), ENT_QUOTES, 'UTF-8');
        $co      = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
        $pretty  = htmlspecialchars(recoveryFormatCode($code), ENT_QUOTES, 'UTF-8');
        $mins    = (int)RECOVERY_OTP_TTL_MIN;
        $base    = function_exists('operatorTenantLoginUrl')
            ? preg_replace('~/login$~', '', operatorTenantLoginUrl($subdomain))
            : '';
        $link    = htmlspecialchars($base . '/forgot-password?token=' . $code, ENT_QUOTES, 'UTF-8');

        $body = "<p>Hello {$name},</p>"
              . "<p>Your provider has issued a one-time code so you can set a new password for your "
              . "administrator account at <strong>{$co}</strong>.</p>"
              . '<p style="margin:22px 0;text-align:center;">'
              . '<span style="font-family:monospace;font-size:26px;letter-spacing:3px;font-weight:700;'
              . 'background:#f1f3f5;border:1px solid #dee2e6;border-radius:8px;padding:14px 22px;'
              . 'display:inline-block;">' . $pretty . '</span></p>'
              . '<p style="text-align:center;margin:18px 0;"><a href="' . $link . '" '
              . 'style="background:#0d6efd;color:#fff;padding:12px 22px;border-radius:6px;'
              . 'text-decoration:none;display:inline-block;font-weight:600;">Use it now</a></p>'
              . "<p>The code works <strong>once</strong> and expires in <strong>{$mins} minutes</strong>. "
              . 'You will be asked to choose a new password straight away — '
              . '<strong>nobody at your provider can see what you choose.</strong></p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="color:#666;font-size:13px;">Did not ask for this? Your password has not changed '
              . 'and this code can be ignored. If you did not contact your provider about your account, '
              . 'tell them now.</p>';

        return sendEmail($to, 'Your one-time recovery code — ' . $company,
            $body, ['wrap_brand' => 'BJP Technologies / BMS']);
    }
}

if (!function_exists('tarNotifyEmailChanged')) {
    /** Sent to the OLD address. The whole point is that it is not silent. */
    function tarNotifyEmailChanged(string $oldEmail, string $newEmail, array $u, string $company): bool
    {
        if (!recoveryMailer()) return false;

        $name = htmlspecialchars(recoveryDisplayName($u), ENT_QUOTES, 'UTF-8');
        $co   = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
        $to   = htmlspecialchars(tarMaskEmail($newEmail), ENT_QUOTES, 'UTF-8');

        $body = "<p>Hello {$name},</p>"
              . "<p>The recovery email address on your administrator account at <strong>{$co}</strong> "
              . "was changed to <strong>{$to}</strong> by your provider.</p>"
              . '<p>From now on, password reset emails for this account go to that address instead '
              . 'of this one.</p>'
              . '<p style="color:#b02a37;"><strong>If you did not ask for this, act now.</strong> '
              . 'Whoever controls that address can take over your account. Contact your provider '
              . 'immediately and ask them to change it back.</p>';

        return sendEmail($oldEmail, 'Your recovery email address was changed — ' . $company,
            $body, ['wrap_brand' => 'BJP Technologies / BMS']);
    }
}

if (!function_exists('tarNotifyUsernameChanged')) {
    function tarNotifyUsernameChanged(array $u, string $newUsername, string $company): bool
    {
        $to = trim((string)($u['email'] ?? ''));
        if ($to === '' || !recoveryMailer()) return false;

        $name = htmlspecialchars(recoveryDisplayName($u), ENT_QUOTES, 'UTF-8');
        $co   = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
        $un   = htmlspecialchars($newUsername, ENT_QUOTES, 'UTF-8');

        $body = "<p>Hello {$name},</p>"
              . "<p>The username on your account at <strong>{$co}</strong> has been changed. "
              . 'From your next sign-in, use:</p>'
              . '<p><strong style="font-size:17px;background:#f1f3f5;padding:6px 12px;'
              . 'border-radius:5px;display:inline-block;">' . $un . '</strong></p>'
              . '<p>Your password has not changed.</p>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
              . '<p style="color:#666;font-size:13px;">If you did not ask for this, contact your provider.</p>';

        return sendEmail($to, 'Your username has changed — ' . $company,
            $body, ['wrap_brand' => 'BJP Technologies / BMS']);
    }
}
