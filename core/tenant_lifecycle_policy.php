<?php
/**
 * core/tenant_lifecycle_policy.php — how long a tenant gets, and who decides.
 *
 * ── What this replaces ──────────────────────────────────────────────────────
 *
 * The same two numbers were written into the code in three places, with no way
 * to change any of them without a deploy:
 *
 *   core/tenant_provisioner.php   trial = NOW() + 14 days   (inline)
 *   core/tenant_bootstrap.php     define('BMS_GRACE_PERIOD_DAYS', 7)
 *   api/cron/trial_enforcement.php define('GRACE_PERIOD_DAYS', 7)
 *
 * Two independent copies of the grace period, consulted by the two code paths
 * that enforce it — the live request and the nightly sweep. They agreed only
 * because nobody had edited one of them yet.
 *
 * Both numbers are now platform settings an operator can change from the panel,
 * and the grace period can additionally be set per tenant: "they have asked for
 * more time" is an ordinary thing to need, and it should not mean giving every
 * other customer more time too.
 *
 * ── Trial vs subscription ───────────────────────────────────────────────────
 *
 * A tenant's access ends on ONE date, but which column holds it depends on
 * status: `trial_ends_at` while they are on trial, `subscription_ends_at` once
 * they are paying. Everything that asked "when does this tenant run out?" had
 * to know that rule and get it right. tenantAccessEndsAt() answers it once.
 *
 * That split is also why extending access used to half-work: the only action
 * available, superadmin_extend_trial.php, moved `trial_ends_at` and restored a
 * suspended tenant to 'trial'. For an ACTIVE paying customer who asked for a
 * few more days, it did nothing useful at all. extendTenantAccess() moves
 * whichever date actually governs.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/platform_settings.php';

if (!function_exists('tenantDefaultTrialDays')) {
    /** How long a brand-new tenant's trial runs. Operator-configurable. */
    function tenantDefaultTrialDays(): int
    {
        $v = (int)getPlatformSetting('default_trial_days', '14');
        return ($v >= 1 && $v <= 365) ? $v : 14;
    }
}

if (!function_exists('tenantDefaultGraceDays')) {
    /**
     * How long a tenant keeps working after their date passes.
     *
     * Zero is legitimate and means "cut them off the moment it expires", so the
     * range starts at 0 rather than 1.
     */
    function tenantDefaultGraceDays(): int
    {
        $raw = getPlatformSetting('default_grace_days', '7');
        $v   = (int)$raw;
        return ($raw !== '' && $v >= 0 && $v <= 90) ? $v : 7;
    }
}

if (!function_exists('tenantGraceDaysFor')) {
    /**
     * This tenant's grace period: their own override, else the platform default.
     *
     * @param array|null $tenant A tenants row (needs `grace_days`), or null.
     */
    function tenantGraceDaysFor(?array $tenant): int
    {
        if ($tenant !== null && isset($tenant['grace_days']) && $tenant['grace_days'] !== null
            && $tenant['grace_days'] !== '') {
            $v = (int)$tenant['grace_days'];
            if ($v >= 0 && $v <= 90) return $v;
        }
        return tenantDefaultGraceDays();
    }
}

if (!function_exists('tenantAccessEndsAt')) {
    /**
     * The date this tenant's access actually runs out, or null if it does not.
     *
     * NULL is a real answer, not a missing one: an operator-created tenant goes
     * in as 'active' with no subscription_ends_at and never expires. On the
     * live platform every active tenant was in exactly that state — the list
     * showed "Expires —" for all of them. They are flagged in the panel rather
     * than silently treated as paid up forever.
     *
     * @return string|null Y-m-d H:i:s (trial) or Y-m-d (subscription).
     */
    function tenantAccessEndsAt(?array $tenant): ?string
    {
        if (!$tenant) return null;
        $status = (string)($tenant['status'] ?? '');

        if ($status === 'trial') {
            return !empty($tenant['trial_ends_at']) ? (string)$tenant['trial_ends_at'] : null;
        }
        if ($status === 'active') {
            return !empty($tenant['subscription_ends_at']) ? (string)$tenant['subscription_ends_at'] : null;
        }
        return null;   // suspended / archived / deleted have no running clock
    }
}

if (!function_exists('tenantDaysRemaining')) {
    /**
     * Whole days until access ends. Negative once it has passed, null when
     * there is no expiry at all.
     *
     * Counted in whole days from today, not in 24-hour blocks from now, so the
     * number matches what a person reading a date would say.
     */
    function tenantDaysRemaining(?array $tenant): ?int
    {
        $ends = tenantAccessEndsAt($tenant);
        if ($ends === null) return null;

        $endTs = strtotime(date('Y-m-d', strtotime($ends)));
        $today = strtotime(date('Y-m-d'));
        if ($endTs === false) return null;

        return (int)round(($endTs - $today) / 86400);
    }
}

if (!function_exists('tenantExpiryLabel')) {
    /**
     * A short human phrase for the panel's list: "12 days", "today",
     * "4 days ago", or "No expiry".
     *
     * @return array{text:string, tone:string} tone: ok | warn | danger | none
     */
    function tenantExpiryLabel(?array $tenant): array
    {
        $d = tenantDaysRemaining($tenant);
        if ($d === null) {
            $status = (string)($tenant['status'] ?? '');
            return in_array($status, ['trial', 'active'], true)
                ? ['text' => 'No expiry', 'tone' => 'none']
                : ['text' => '—',         'tone' => 'none'];
        }
        if ($d < 0)   return ['text' => abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago', 'tone' => 'danger'];
        if ($d === 0) return ['text' => 'today', 'tone' => 'danger'];
        if ($d <= 7)  return ['text' => $d . ' day' . ($d === 1 ? '' : 's'), 'tone' => 'warn'];
        return ['text' => $d . ' days', 'tone' => 'ok'];
    }
}

if (!function_exists('extendTenantAccess')) {
    /**
     * Give a tenant more time — whichever clock is actually running.
     *
     * Replaces the half-feature this had before: the old action moved
     * `trial_ends_at` only, so for an active paying customer who asked for a
     * few more days it changed nothing they would ever notice.
     *
     * Rules:
     *   - trial (or a tenant suspended from an expired trial) → trial_ends_at
     *   - active (or suspended from an expired subscription)  → subscription_ends_at
     *   - extends from the LATER of today and the existing date, so a lapsed
     *     tenant genuinely gets the days asked for rather than losing some of
     *     them to the past
     *   - a tenant suspended BY the expiry is restored to service; one an
     *     operator suspended by hand is left suspended, because more time was
     *     not the reason they were cut off
     *   - grace_until is cleared: the clock has moved, so the old grace window
     *     is stale
     *
     * @return array{ok:bool, error:?string, ends_at:?string, field:?string, resumed:bool}
     */
    function extendTenantAccess(int $tenantId, int $days): array
    {
        $fail = fn(string $m): array =>
            ['ok' => false, 'error' => $m, 'ends_at' => null, 'field' => null, 'resumed' => false];

        if ($days < 1 || $days > 365) return $fail('Days must be between 1 and 365.');

        try {
            $ctrl = getControlPdo();
            $st = $ctrl->prepare("SELECT id, subdomain, status, suspension_reason,
                                         trial_ends_at, subscription_ends_at
                                    FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenantId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) return $fail('Tenant not found.');

            $status = (string)$t['status'];
            if (in_array($status, ['deleted', 'archived'], true)) {
                return $fail('This tenant is closed. Reactivate it before extending access.');
            }

            // Which clock governs? For a suspended tenant, the reason it was
            // suspended says which one ran out.
            if ($status === 'trial') {
                $field = 'trial_ends_at';
            } elseif ($status === 'active') {
                $field = 'subscription_ends_at';
            } elseif ($status === 'suspended') {
                $field = ((string)$t['suspension_reason'] === 'trial_expired')
                    ? 'trial_ends_at' : 'subscription_ends_at';
            } else {
                return $fail('This tenant has no access period to extend.');
            }

            $current = $t[$field] ?? null;
            $base    = (!empty($current) && strtotime((string)$current) > time())
                ? strtotime((string)$current)
                : time();
            $newTs   = strtotime('+' . $days . ' days', $base);
            $newVal  = $field === 'trial_ends_at'
                ? date('Y-m-d 23:59:59', $newTs)
                : date('Y-m-d', $newTs);

            // Who granted it. The column name says "extended BY", and the only
            // writer before this put the operator's id in it — so that is what
            // it means. The number of days lives in the audit log, which is
            // where a history belongs.
            $operatorId = function_exists('currentSuperadmin')
                ? (int)(currentSuperadmin()['id'] ?? 0) : 0;
            $operatorId = $operatorId > 0 ? $operatorId : null;

            // Resume service only when the expiry is what stopped it. A manual
            // suspension is a decision, not a clock, and more days do not undo it.
            $autoSuspended = $status === 'suspended'
                && in_array((string)$t['suspension_reason'], ['trial_expired', 'subscription_expired'], true);
            $resumed = false;

            if ($autoSuspended) {
                $newStatus = $field === 'trial_ends_at' ? 'trial' : 'active';
                $ctrl->prepare("
                    UPDATE tenants
                       SET `$field` = ?, status = ?, suspended_at = NULL,
                           suspension_reason = NULL, grace_until = NULL,
                           trial_extended_by = ?
                     WHERE id = ?
                ")->execute([$newVal, $newStatus, $operatorId, $tenantId]);
                $resumed = true;
            } else {
                $ctrl->prepare("
                    UPDATE tenants
                       SET `$field` = ?, grace_until = NULL,
                           trial_extended_by = ?
                     WHERE id = ?
                ")->execute([$newVal, $operatorId, $tenantId]);
            }

            if (function_exists('logTenantAdminAction')) {
                logTenantAdminAction($tenantId, (string)$t['subdomain'], 'extend_access',
                    "+{$days} day(s) on {$field} → {$newVal}" . ($resumed ? ' (service resumed)' : ''));
            }

            return ['ok' => true, 'error' => null, 'ends_at' => $newVal,
                    'field' => $field, 'resumed' => $resumed];
        } catch (Throwable $e) {
            error_log('extendTenantAccess(' . $tenantId . '): ' . $e->getMessage());
            return $fail('Could not extend access. Please try again.');
        }
    }
}

if (!function_exists('setTenantGraceDays')) {
    /**
     * Give one tenant their own grace period, or clear it back to the platform
     * default. NULL means "follow the default", which is not the same as 0.
     *
     * @return array{ok:bool, error:?string}
     */
    function setTenantGraceDays(int $tenantId, ?int $days): array
    {
        if ($days !== null && ($days < 0 || $days > 90)) {
            return ['ok' => false, 'error' => 'Grace days must be between 0 and 90, or left blank to use the default.'];
        }
        try {
            $ctrl = getControlPdo();
            $st = $ctrl->prepare("SELECT subdomain FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenantId]);
            $sub = $st->fetchColumn();
            if ($sub === false) return ['ok' => false, 'error' => 'Tenant not found.'];

            $ctrl->prepare("UPDATE tenants SET grace_days = ? WHERE id = ?")->execute([$days, $tenantId]);

            if (function_exists('logTenantAdminAction')) {
                logTenantAdminAction($tenantId, (string)$sub, 'set_grace_days',
                    $days === null ? 'cleared — follows the platform default' : $days . ' day(s)');
            }
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('setTenantGraceDays(' . $tenantId . '): ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the grace period.'];
        }
    }
}
