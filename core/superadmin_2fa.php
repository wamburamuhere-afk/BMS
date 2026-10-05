<?php
/**
 * core/superadmin_2fa.php — two-step sign-in for the platform operator.
 *
 * This account administers every company on the platform. Until now it was
 * protected by one password, with no second factor anywhere in the codebase —
 * so a single reused or phished credential was the whole of the defence.
 *
 * ── Design notes ────────────────────────────────────────────────────────────
 *
 * OPT-IN, NOT FORCED. Enrolment is a deliberate act in the operator's own
 * profile, and it is confirmed by typing a live code before it takes effect.
 * Switching it on for an account that turns out to have a mis-scanned secret
 * would lock the platform's own administrator out of the platform — the exact
 * failure this work exists to eliminate.
 *
 * REPLAY IS BLOCKED. A TOTP code is valid for up to 90 seconds across the
 * accepted window, so plain verification lets the same six digits be used
 * twice — by whoever watched them being typed. The counter that matched is
 * stored, and anything at or below it is refused afterwards.
 *
 * RECOVERY CODES ARE MANDATORY. A phone gets lost, wiped or replaced. Without
 * a second route the operator is back to SSH, which is what Phase 2 removed.
 * Ten single-use codes are issued at enrolment, stored only as SHA-256, and
 * shown exactly once.
 *
 * THE EMAIL RESET STILL WORKS, AND STILL ASKS FOR THE CODE. Password recovery
 * (core/superadmin_recovery.php) proves control of the mailbox; it does not
 * prove possession of the second factor, so it does not bypass it. Losing both
 * the password and the phone is what the recovery codes are for.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';

if (!defined('SA_2FA_ISSUER'))        define('SA_2FA_ISSUER', 'BMS Platform');
if (!defined('SA_2FA_RECOVERY_CODES')) define('SA_2FA_RECOVERY_CODES', 10);

if (!function_exists('saTotpRow')) {
    /** @return array|null The operator row, or null. */
    function saTotpRow(int $id): ?array
    {
        try {
            $st = getControlPdo()->prepare("SELECT * FROM superadmins WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            error_log('saTotpRow: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('saTotpEnabled')) {
    /**
     * Is two-step sign-in actually ON for this operator?
     *
     * Confirmed, not merely enrolled: a half-finished enrolment leaves a secret
     * in the row, and treating that as enabled would demand a code the operator
     * has no way to produce.
     */
    function saTotpEnabled(?array $sa): bool
    {
        return $sa !== null
            && !empty($sa['totp_secret_enc'])
            && !empty($sa['totp_confirmed_at']);
    }
}

if (!function_exists('saTotpBeginEnrollment')) {
    /**
     * Mint a secret and hand back what the operator needs to scan it.
     *
     * Stored immediately but UNCONFIRMED, so the code they type next can be
     * checked against the same secret the app holds. Calling it again before
     * confirming replaces the pending secret — which is what someone who
     * closed the page half way through will do.
     *
     * @return array{ok:bool, error:?string, secret:?string, uri:?string, formatted:?string}
     */
    function saTotpBeginEnrollment(int $id): array
    {
        $fail = fn(string $m): array =>
            ['ok' => false, 'error' => $m, 'secret' => null, 'uri' => null, 'formatted' => null];

        $sa = saTotpRow($id);
        if (!$sa) return $fail('Your account no longer exists.');
        if (saTotpEnabled($sa)) {
            return $fail('Two-step sign-in is already on. Turn it off first if you want to set up a new device.');
        }

        $secret = totpGenerateSecret();
        $enc    = encryptSecret($secret);
        if ($enc === '') return $fail('Could not secure the new secret. Please try again.');

        try {
            getControlPdo()->prepare("
                UPDATE superadmins
                   SET totp_secret_enc = ?, totp_confirmed_at = NULL, totp_last_counter = NULL
                 WHERE id = ?
            ")->execute([$enc, $id]);
        } catch (Throwable $e) {
            error_log('saTotpBeginEnrollment: ' . $e->getMessage());
            return $fail('Could not start setup. Please try again.');
        }

        return [
            'ok' => true, 'error' => null,
            'secret'    => $secret,
            'uri'       => totpUri($secret, (string)$sa['email'], SA_2FA_ISSUER),
            'formatted' => totpFormatSecret($secret),
        ];
    }
}

if (!function_exists('saTotpConfirm')) {
    /**
     * Turn it on, once a live code proves the app really holds the secret.
     *
     * Returns the recovery codes in plain text — the ONLY time they exist
     * outside a hash. The caller must show them and must not store them.
     *
     * @return array{ok:bool, error:?string, recovery_codes:?array<int,string>}
     */
    function saTotpConfirm(int $id, string $code): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'recovery_codes' => null];

        $sa = saTotpRow($id);
        if (!$sa) return $fail('Your account no longer exists.');
        if (saTotpEnabled($sa)) return $fail('Two-step sign-in is already on.');
        if (empty($sa['totp_secret_enc'])) return $fail('Start the setup again — there is no pending secret.');

        $secret = decryptSecret((string)$sa['totp_secret_enc']);
        if ($secret === null) return $fail('The pending secret could not be read. Please start setup again.');

        $counter = totpVerify($secret, $code);
        if ($counter === null) {
            return $fail('That code is not right. Check your phone\'s time is set automatically, and try the current code.');
        }

        $plain  = [];
        $hashes = [];
        for ($i = 0; $i < SA_2FA_RECOVERY_CODES; $i++) {
            // Human-typeable, grouped, from an alphabet with no look-alikes.
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[]  = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $hashes[] = hash('sha256', $raw);
        }

        try {
            getControlPdo()->prepare("
                UPDATE superadmins
                   SET totp_confirmed_at = ?, totp_last_counter = ?, totp_recovery_hashes = ?
                 WHERE id = ?
            ")->execute([date('Y-m-d H:i:s'), $counter, json_encode($hashes), $id]);
        } catch (Throwable $e) {
            error_log('saTotpConfirm: ' . $e->getMessage());
            return $fail('Could not switch it on. Please try again.');
        }

        if (function_exists('logTenantAdminAction')) {
            logTenantAdminAction(null, null, 'superadmin_2fa_enabled', 'operator #' . $id);
        }
        saTotpNotify($sa, 'Two-step sign-in is now ON for your platform operator account.',
            'From now on you will be asked for a code from your authenticator app after your password.');

        return ['ok' => true, 'error' => null, 'recovery_codes' => $plain];
    }
}

if (!function_exists('saTotpDisable')) {
    /**
     * Switch it off. Requires the CURRENT PASSWORD, never just a session:
     * an unattended logged-in browser must not be enough to strip the second
     * factor off the account that administers the whole platform.
     *
     * @return array{ok:bool, error:?string}
     */
    function saTotpDisable(int $id, string $currentPassword): array
    {
        $sa = saTotpRow($id);
        if (!$sa) return ['ok' => false, 'error' => 'Your account no longer exists.'];
        if (!password_verify($currentPassword, (string)$sa['password_hash'])) {
            return ['ok' => false, 'error' => 'Your current password is incorrect.'];
        }

        try {
            getControlPdo()->prepare("
                UPDATE superadmins
                   SET totp_secret_enc = NULL, totp_confirmed_at = NULL,
                       totp_last_counter = NULL, totp_recovery_hashes = NULL
                 WHERE id = ?
            ")->execute([$id]);
        } catch (Throwable $e) {
            error_log('saTotpDisable: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not turn it off. Please try again.'];
        }

        if (function_exists('logTenantAdminAction')) {
            logTenantAdminAction(null, null, 'superadmin_2fa_disabled', 'operator #' . $id);
        }
        saTotpNotify($sa, 'Two-step sign-in was turned OFF for your platform operator account.',
            'If this was not you, someone knows your password — change it immediately.');

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('saTotpCheck')) {
    /**
     * Verify a code at sign-in. Accepts an authenticator code OR an unused
     * recovery code, and spends whichever it was.
     *
     * @return array{ok:bool, error:?string, used_recovery:bool, remaining:?int}
     */
    function saTotpCheck(int $id, string $input): array
    {
        $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'used_recovery' => false, 'remaining' => null];

        $sa = saTotpRow($id);
        if (!$sa) return $fail('Your account no longer exists.');
        if (!saTotpEnabled($sa)) return ['ok' => true, 'error' => null, 'used_recovery' => false, 'remaining' => null];

        $input  = trim($input);
        $secret = decryptSecret((string)$sa['totp_secret_enc']);

        if ($secret !== null) {
            $counter = totpVerify($secret, $input);
            if ($counter !== null) {
                // Replay guard: a code stays valid for up to 90 seconds, so
                // without this the same six digits work twice for anyone who
                // saw them typed.
                $last = $sa['totp_last_counter'] !== null ? (int)$sa['totp_last_counter'] : null;
                if ($last !== null && $counter <= $last) {
                    return $fail('That code has already been used. Wait for your app to show the next one.');
                }
                try {
                    getControlPdo()->prepare("UPDATE superadmins SET totp_last_counter = ? WHERE id = ?")
                        ->execute([$counter, $id]);
                } catch (Throwable $e) {
                    error_log('saTotpCheck counter: ' . $e->getMessage());
                }
                return ['ok' => true, 'error' => null, 'used_recovery' => false, 'remaining' => null];
            }
        }

        // Recovery code: single use, removed from the list the moment it works.
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        if (strlen($normalised) === 10) {
            $hashes = json_decode((string)($sa['totp_recovery_hashes'] ?? '[]'), true);
            if (is_array($hashes)) {
                $want = hash('sha256', $normalised);
                foreach ($hashes as $i => $h) {
                    if (is_string($h) && hash_equals($h, $want)) {
                        unset($hashes[$i]);
                        $left = array_values($hashes);
                        try {
                            getControlPdo()->prepare("UPDATE superadmins SET totp_recovery_hashes = ? WHERE id = ?")
                                ->execute([json_encode($left), $id]);
                        } catch (Throwable $e) {
                            error_log('saTotpCheck recovery: ' . $e->getMessage());
                        }
                        saTotpNotify($sa, 'A recovery code was used to sign in to your operator account.',
                            'You have ' . count($left) . ' left. If this was not you, change your password now.');
                        return ['ok' => true, 'error' => null, 'used_recovery' => true, 'remaining' => count($left)];
                    }
                }
            }
        }

        return $fail('That code is not right.');
    }
}

if (!function_exists('saTotpRecoveryRemaining')) {
    function saTotpRecoveryRemaining(?array $sa): int
    {
        $h = json_decode((string)($sa['totp_recovery_hashes'] ?? '[]'), true);
        return is_array($h) ? count($h) : 0;
    }
}

if (!function_exists('saTotpNotify')) {
    /**
     * Tell the operator their second factor changed. Best-effort: never let a
     * mail failure block the change itself, which has already happened.
     */
    function saTotpNotify(array $sa, string $headline, string $detail): bool
    {
        if (!function_exists('sendEmail')) {
            @require_once __DIR__ . '/mailer.php';
        }
        if (!function_exists('sendEmail')) return false;

        $name = htmlspecialchars((string)($sa['name'] ?: 'there'), ENT_QUOTES, 'UTF-8');
        $body = "<p>Hello {$name},</p>"
              . '<p><strong>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</strong></p>'
              . '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p style="color:#666;font-size:13px;">Sent at ' . date('d M Y H:i') . '.</p>';

        try {
            return sendEmail((string)$sa['email'], 'Your platform operator security settings changed',
                $body, ['wrap_brand' => 'BJP Technologies / BMS']);
        } catch (Throwable $e) {
            error_log('saTotpNotify: ' . $e->getMessage());
            return false;
        }
    }
}
