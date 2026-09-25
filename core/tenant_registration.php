<?php
/**
 * core/tenant_registration.php
 * ----------------------------
 * The policy layer around self-registration: is signup open, is this request
 * allowed to try, and what gets recorded about it.
 *
 * Kept separate from core/tenant_provisioner.php on purpose. The provisioner
 * answers "build a tenant correctly"; this file answers "should we build one for
 * THIS caller, right now". Mixing them would mean the internal, trusted callers
 * of provisionTenant() (Phase 7's migration, an operator CLI) had to reason
 * about rate limits meant for anonymous traffic.
 *
 * WHY THE THROTTLE MATTERS. registerTenant() is reachable by anyone on the
 * internet with no credentials, and every success creates a MySQL database and a
 * MySQL user. Unthrottled, a short loop fills the server's disk and its user
 * table. The limits below are the difference between a signup form and a
 * denial-of-service amplifier.
 *
 * Public API:
 *   selfRegistrationOpen(): bool
 *   selfRegistrationClosedReason(): ?string
 *   registrationThrottleCheck(string $ip): ?string   → error message, or null
 *   logRegistrationAttempt(...): void
 *   registerTenant(array $input, string $ip): array
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/tenant_provisioner.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/plans.php';

/**
 * Throttle policy.
 *
 * Per-IP counts exclude trivial validation rejections (outcome='rejected') —
 * a mistyped email or short password should not burn a registration slot.
 * Only real provisioning attempts (success / failed / throttled) count toward
 * the per-IP cap so a team signing up from the same office NAT is not blocked
 * after three typos between them.
 *
 * The global hourly cap already counts only successes (a distributed flood of
 * failures would slip past it, but would hit the per-IP limit instead).
 */
const REGISTRATION_MAX_PER_IP_HOUR  = 10;   // was 3 — raised for shared-NAT offices
const REGISTRATION_MAX_PER_IP_DAY   = 20;   // was 6
const REGISTRATION_MAX_GLOBAL_HOUR  = 40;

if (!function_exists('selfRegistrationOpen')) {
    /** True if the public signup flow should accept registrations right now. */
    function selfRegistrationOpen(): bool
    {
        return selfRegistrationClosedReason() === null;
    }
}

if (!function_exists('selfRegistrationClosedReason')) {
    /**
     * Why signup is unavailable, or null if it is open.
     *
     * Fails CLOSED. Provisioning a tenant that nobody can then reach — because
     * multi-tenancy is off, or the encryption key is missing so its credentials
     * could not be stored safely — would create real databases that are pure
     * liability. Better to refuse than to half-succeed.
     */
    function selfRegistrationClosedReason(): ?string
    {
        if (strtolower(trim((string)getenv('TENANT_SELF_REGISTRATION'))) === 'off') {
            return 'Registration is currently closed.';
        }
        if (!tenantModeEnabled()) {
            // Without routing, a new tenant's subdomain would resolve nowhere.
            return 'Registration is not available on this installation.';
        }
        if (tenantBaseDomain() === null) {
            return 'Registration is not available on this installation.';
        }
        if (!tenantCredKeyAvailable()) {
            // Refuse rather than store a live database password unencrypted.
            error_log('Self-registration blocked: TENANT_CRED_KEY is not configured.');
            return 'Registration is temporarily unavailable. Please try again later.';
        }
        try {
            if (!controlDbReady()) return 'Registration is temporarily unavailable. Please try again later.';
        } catch (Throwable $e) {
            return 'Registration is temporarily unavailable. Please try again later.';
        }
        return null;
    }
}

if (!function_exists('logRegistrationAttempt')) {
    /**
     * Record an attempt. Best-effort: a logging failure must not break signup —
     * but note the throttle reads this table, so a persistent write failure
     * degrades the limiter to "allow", which is why the table is created by a
     * migration rather than lazily.
     */
    function logRegistrationAttempt(
        string $ip, ?string $email, ?string $subdomain,
        string $outcome, ?string $reason = null, ?int $tenantId = null
    ): void {
        try {
            getControlPdo()->prepare("
                INSERT INTO registration_attempts (ip_address, email, subdomain, outcome, reason, tenant_id)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                substr($ip, 0, 45), $email, $subdomain, $outcome,
                $reason === null ? null : substr($reason, 0, 255), $tenantId,
            ]);
        } catch (Throwable $e) {
            error_log('registration_attempts write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('registrationThrottleCheck')) {
    /**
     * @return ?string  a message to show the caller, or null if within limits.
     *
     * Counts every attempt, not just successes: a script probing subdomains is
     * exactly what needs stopping, and it never reaches a success.
     */
    function registrationThrottleCheck(string $ip): ?string
    {
        try {
            $pdo = getControlPdo();

            // Count only real provisioning attempts (not trivial form-validation
            // rejections) so a team on the same office NAT is not blocked because
            // one member mistyped their password three times.
            $perIpHour = $pdo->prepare("
                SELECT COUNT(*) FROM registration_attempts
                WHERE ip_address = ? AND outcome <> 'rejected'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $perIpHour->execute([$ip]);
            if ((int)$perIpHour->fetchColumn() >= REGISTRATION_MAX_PER_IP_HOUR) {
                return 'Too many registration attempts from your network. Please try again in an hour.';
            }

            $perIpDay = $pdo->prepare("
                SELECT COUNT(*) FROM registration_attempts
                WHERE ip_address = ? AND outcome <> 'rejected'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $perIpDay->execute([$ip]);
            if ((int)$perIpDay->fetchColumn() >= REGISTRATION_MAX_PER_IP_DAY) {
                return 'Too many registrations from your network today. Please try again tomorrow.';
            }

            // A distributed flood would slip past the per-IP limits; this caps the
            // damage to the server regardless of how many addresses are used.
            $global = (int)$pdo->query("
                SELECT COUNT(*) FROM registration_attempts
                WHERE outcome = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ")->fetchColumn();
            if ($global >= REGISTRATION_MAX_GLOBAL_HOUR) {
                error_log('Self-registration global hourly cap reached (' . $global . ').');
                return 'We are receiving an unusual number of signups. Please try again shortly.';
            }
        } catch (Throwable $e) {
            error_log('registrationThrottleCheck: ' . $e->getMessage());
            return null;   // never block a genuine signup because the counter broke
        }

        return null;
    }
}

if (!function_exists('registerTenant')) {
    /**
     * The public signup entry point: gate, throttle, validate, provision, record.
     *
     * @param array  $in  company_name, subdomain, owner_email, owner_password,
     *                    owner_first_name, owner_last_name, company_physical_address,
     *                    company_postal_address, logo_tmp_path, logo_extension,
     *                    website (honeypot)
     * @return array{ok:bool, error:?string, tenant_id:?int, subdomain:?string, login_url:?string}
     */
    function registerTenant(array $in, string $ip): array
    {
        $fail = function (string $msg, string $outcome, ?string $email, ?string $sub) use ($ip) {
            logRegistrationAttempt($ip, $email, $sub, $outcome, $msg);
            return ['ok' => false, 'error' => $msg, 'tenant_id' => null, 'subdomain' => null, 'login_url' => null];
        };

        $company = trim((string)($in['company_name'] ?? ''));
        $sub     = strtolower(trim((string)($in['subdomain'] ?? '')));
        $phone   = preg_replace('/\s+/', '', (string)($in['owner_phone'] ?? ''));
        $pw      = (string)($in['owner_password'] ?? '');

        // ── Is signup open at all? ───────────────────────────────────────────
        if ($closed = selfRegistrationClosedReason()) {
            return $fail($closed, 'rejected', $phone ?: null, $sub ?: null);
        }

        // ── Honeypot ─────────────────────────────────────────────────────────
        // A field hidden from humans by CSS. Anything that fills it is a bot, so
        // it is refused with the same wording a person would see — never a hint
        // that the trap exists.
        if (trim((string)($in['website'] ?? '')) !== '') {
            return $fail('Registration could not be completed.', 'rejected', $phone ?: null, $sub ?: null);
        }

        // ── Throttle ─────────────────────────────────────────────────────────
        if ($msg = registrationThrottleCheck($ip)) {
            logRegistrationAttempt($ip, $phone ?: null, $sub ?: null, 'throttled', $msg);
            return ['ok' => false, 'error' => $msg, 'tenant_id' => null, 'subdomain' => null, 'login_url' => null];
        }

        // ── Validate before doing any work ───────────────────────────────────
        if ($company === '' || mb_strlen($company) < 2) {
            return $fail('Please enter your company name.', 'rejected', $phone ?: null, $sub ?: null);
        }
        if ($err = tenantSubdomainError($sub)) {
            return $fail($err, 'rejected', $phone ?: null, $sub ?: null);
        }
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            return $fail('Please enter a valid phone number (digits only, 7–15 characters).', 'rejected', $phone ?: null, $sub);
        }
        // .claude/security.md §20 — 8+ chars, at least one letter and one digit.
        if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return $fail('Password must be at least 8 characters and include a letter and a number.',
                'rejected', $phone, $sub);
        }
        if (($in['owner_password_confirm'] ?? $pw) !== $pw) {
            return $fail('The two passwords do not match.', 'rejected', $phone, $sub);
        }
        if (!tenantSubdomainAvailable($sub)) {
            return $fail('That subdomain is already taken. Please choose another.', 'rejected', $phone, $sub);
        }

        // ── Provision ────────────────────────────────────────────────────────
        // provisionTenant() guarantees all-or-nothing: on failure there is no
        // orphaned database, MySQL user or registry row to clean up here.
        $ownerEmail = trim((string)($in['owner_email'] ?? ''));
        if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return $fail('Please enter a valid email address.', 'rejected', $phone, $sub);
        }

        $r = provisionTenant($company, $sub, $phone, $pw, [
            'status'            => 'active',      // so the owner can sign in immediately
            'owner_first_name'  => trim((string)($in['owner_first_name'] ?? '')),
            'owner_last_name'   => trim((string)($in['owner_last_name'] ?? '')),
            'owner_phone'       => $phone,
            'country'           => trim((string)($in['country']       ?? '')),
            'industry'          => trim((string)($in['industry']      ?? '')),
            'company_size'      => trim((string)($in['company_size']  ?? '')),
            'physical_address'  => trim((string)($in['company_physical_address'] ?? '')),
            'postal_address'    => trim((string)($in['company_postal_address'] ?? '')),
            'phone'             => $phone,
            'email'             => $ownerEmail,
            'logo_tmp_path'     => $in['logo_tmp_path'] ?? null,
            'logo_extension'    => $in['logo_extension'] ?? null,
        ]);

        if (!$r['ok']) {
            // The provisioner's message can name internal objects; log the detail
            // and show the visitor something plain.
            error_log('Self-registration failed for ' . $sub . ': ' . $r['error']);
            logRegistrationAttempt($ip, $phone, $sub, 'failed', $r['error']);
            $public = (stripos((string)$r['error'], 'already taken') !== false
                    || stripos((string)$r['error'], 'just taken') !== false)
                ? 'That subdomain is already taken. Please choose another.'
                : 'We could not finish setting up your account. Please try again shortly.';
            return ['ok' => false, 'error' => $public, 'tenant_id' => null, 'subdomain' => null, 'login_url' => null];
        }

        // tenant_module_control_plan.md §5.1 — the platform-wide switch for
        // the UNATTENDED path only (nobody at the platform in the loop yet).
        // Default 'all' is today's exact behaviour: everything on, byte-
        // identical, nothing further happens here. A failure to apply the
        // 'blank' plan is never fatal to registration itself — the owner can
        // already sign in — it just means "everything on" stands, same as if
        // the switch were 'all'; logged so it can be finished manually.
        if (getPlatformSetting('tenant_default_provisioning', 'all') === 'none') {
            $blank = getPlanByKey('blank');
            if ($blank) {
                $ar = applyPlanToTenant((int)$r['tenant_id'], (int)$blank['id']);
                if (!$ar['ok']) {
                    error_log('registerTenant: blank-plan apply failed for tenant ' . $r['tenant_id'] . ': ' . $ar['error']);
                }
            } else {
                error_log('registerTenant: provisioning switch is "none" but the reserved "blank" plan is missing — run scripts/setup_control_db.php');
            }
        }

        // Apply self-registration defaults: Simple Mode ON + only POS/Warehouses.
        // Best-effort — logged but never fatal to registration itself.
        try {
            applySelfRegistrationDefaults((int)$r['tenant_id']);
        } catch (Throwable $_defaultsE) {
            error_log('applySelfRegistrationDefaults threw: ' . $_defaultsE->getMessage());
        }

        logRegistrationAttempt($ip, $phone, $sub, 'success', null, $r['tenant_id']);

        // Notify all superadmins by email — best-effort, never fatal.
        try {
            notifySuperadminsOfNewRegistration((int)$r['tenant_id'], $sub, $phone, $company);
        } catch (Throwable $_notifyE) {
            error_log('notifySuperadminsOfNewRegistration threw: ' . $_notifyE->getMessage());
        }

        return [
            'ok' => true, 'error' => null,
            'tenant_id' => $r['tenant_id'],
            'subdomain' => $sub,
            'login_url' => tenantLoginUrl($sub),
        ];
    }
}

if (!function_exists('applySelfRegistrationDefaults')) {
    /**
     * Applied once, right after self-registration, to set the new tenant's
     * default experience: Simple Mode ON (locked so the tenant cannot toggle
     * it themselves), with only POS and Warehouses enabled. All other modules
     * start denied; a superadmin must explicitly grant them from the tenant panel.
     *
     * Best-effort — a failure here is logged but never fails the registration
     * itself. The owner can already sign in, and a superadmin can fix the
     * module/mode state manually.
     */
    function applySelfRegistrationDefaults(int $tenantId): void
    {
        // Turn off every module except 'pos' and 'warehouses'.
        // 'warehouses' must stay on because 'pos' depends on it.
        $desired = [];
        foreach ([
            'sales', 'procurement', 'tenders', 'hr', 'assets', 'projects',
            'ai_assistant', 'esignature', 'crm', 'communication', 'documents',
            'compliance', 'finance',
        ] as $key) {
            $desired[$key] = false;
        }
        $fr = setTenantFeatures($tenantId, $desired);
        if (!$fr['ok']) {
            error_log('applySelfRegistrationDefaults: setTenantFeatures failed for tenant '
                . $tenantId . ': ' . $fr['error']);
        }

        // Simple Mode ON and locked — only a superadmin can change it from
        // the tenant detail panel; the tenant's own admin cannot toggle it.
        $sr = setTenantPosSimpleMode($tenantId, true, true);
        if (!$sr['ok']) {
            error_log('applySelfRegistrationDefaults: setTenantPosSimpleMode failed for tenant '
                . $tenantId . ': ' . $sr['error']);
        }

        // Shop Mode ON — self-registered tenants are small retail shops; "Shop/Duka"
        // wording fits better than "Warehouse/Ghala" from day one.
        $sm = setTenantShopMode($tenantId, true);
        if (!$sm['ok']) {
            error_log('applySelfRegistrationDefaults: setTenantShopMode failed for tenant '
                . $tenantId . ': ' . $sm['error']);
        }

        // Supplier Access ON — a shop buys stock from suppliers and needs to record
        // payments to them without the full Procurement module being enabled.
        $sa = setTenantSupplierAccess($tenantId, true, false);
        if (!$sa['ok']) {
            error_log('applySelfRegistrationDefaults: setTenantSupplierAccess failed for tenant '
                . $tenantId . ': ' . $sa['error']);
        }
    }
}

if (!function_exists('notifySuperadminsOfNewRegistration')) {
    /**
     * Email every superadmin when a new company self-registers.
     * Best-effort — never fatal to the registration itself.
     * Same pattern as notifySuperadminsOfModuleRequest() in core/module_requests.php.
     */
    function notifySuperadminsOfNewRegistration(
        int $tenantId, string $subdomain, string $ownerEmail, string $companyName
    ): void {
        require_once __DIR__ . '/platform_settings.php';
        require_once __DIR__ . '/mailer.php';

        $mailer = platformMailerOpts();
        if (!$mailer['configured']) return;   // SMTP not set up yet — silently skip

        $safeCompany = htmlspecialchars($companyName,  ENT_QUOTES, 'UTF-8');
        $safeEmail   = htmlspecialchars($ownerEmail,   ENT_QUOTES, 'UTF-8');
        $safeSub     = htmlspecialchars($subdomain,    ENT_QUOTES, 'UTF-8');
        $ts          = date('Y-m-d H:i:s T');

        $subject = "New company registered: {$companyName}";
        $body    = "<p>A new company has registered on the platform.</p>"
                 . "<ul>"
                 . "<li><strong>Company:</strong> {$safeCompany}</li>"
                 . "<li><strong>Subdomain:</strong> {$safeSub}</li>"
                 . "<li><strong>Owner email:</strong> {$safeEmail}</li>"
                 . "<li><strong>Tenant ID:</strong> {$tenantId}</li>"
                 . "<li><strong>Registered at:</strong> {$ts}</li>"
                 . "</ul>"
                 . "<p>View details in the platform admin panel under <strong>Companies</strong>.</p>";

        try {
            $emails = getControlPdo()->query("SELECT email FROM superadmins")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($emails as $to) {
                sendEmail((string)$to, $subject, $body, $mailer['opts']);
            }
        } catch (Throwable $e) {
            error_log('notifySuperadminsOfNewRegistration: ' . $e->getMessage());
        }
    }
}

if (!function_exists('tenantLoginUrl')) {
    /** Absolute sign-in URL for a tenant's own subdomain. */
    function tenantLoginUrl(string $subdomain): string
    {
        $base   = tenantBaseDomain() ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $subdomain . ($base !== '' ? '.' . $base : '') . '/login';
    }
}
