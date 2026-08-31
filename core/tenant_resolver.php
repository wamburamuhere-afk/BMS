<?php
/**
 * core/tenant_resolver.php
 * ------------------------
 * Works out WHICH TENANT a request belongs to. Nothing more.
 *
 * This file deliberately does not open a tenant connection, touch sessions, or
 * render anything — core/tenant_bootstrap.php does all of that. Keeping the
 * contract this narrow is what makes the documented v2 fallback cheap: if
 * wildcard DNS turns out to be unavailable on production hosting, only
 * extractTenantSubdomain() changes (to read a company code posted at login),
 * and every caller of resolveTenantFromRequest() carries on unchanged.
 * See docs/MULTI_TENANCY_CONVENTIONS.md §3.
 *
 * MULTI-TENANCY IS OFF UNTIL EXPLICITLY SWITCHED ON. tenantModeEnabled() reads
 * TENANT_MODE and returns false unless it is exactly 'on'. That is what makes
 * this phase safe to deploy ahead of Phase 7: with the variable unset, the
 * application connects exactly as it always has.
 *
 * Public API:
 *   tenantModeEnabled(): bool
 *   tenantBaseDomain(): ?string
 *   extractTenantSubdomain(?string $host, ?string $base = null): ?string   [pure]
 *   resolveTenantFromRequest(?string $host = null): array
 */

require_once __DIR__ . '/control_db.php';

if (!function_exists('tenantModeEnabled')) {
    /**
     * True only when TENANT_MODE is exactly 'on'.
     *
     * Fail-closed on purpose: a typo, an empty value, or an environment that
     * never heard of the variable all mean "behave exactly as the single-tenant
     * application always has", never "guess".
     */
    function tenantModeEnabled(): bool
    {
        return strtolower(trim((string)getenv('TENANT_MODE'))) === 'on';
    }
}

if (!function_exists('tenantBaseDomain')) {
    /**
     * The domain tenant subdomains hang off, e.g. 'bms.co.tz' — so that
     * kampunia.bms.co.tz resolves to the tenant 'kampunia'.
     *
     * Returns null when unset. Without it a host cannot be split reliably: the
     * first label of 'erp.example.co.uk' is not a tenant, and guessing by
     * counting dots gets it wrong for every multi-part TLD.
     */
    function tenantBaseDomain(): ?string
    {
        $d = strtolower(trim((string)getenv('TENANT_BASE_DOMAIN')));
        $d = trim($d, '.');
        return $d === '' ? null : $d;
    }
}

if (!function_exists('extractTenantSubdomain')) {
    /**
     * Pull the tenant label out of an HTTP host. PURE — no I/O, no globals — so
     * the whole matrix of hostnames can be tested without a web server.
     *
     * Returns null when the host is not a tenant address at all: the root
     * domain, a reserved label such as www/admin/superadmin, a bare hostname,
     * an IP address, or anything not under the base domain.
     *
     * Returning null is NOT the same as "tenant not found" — see
     * resolveTenantFromRequest(), which distinguishes them. Conflating the two
     * is how an unknown subdomain would end up quietly served the main
     * database's data.
     */
    function extractTenantSubdomain(?string $host, ?string $base = null): ?string
    {
        if ($host === null) return null;

        // Strip any port, and an IPv6 literal's brackets, then normalise.
        $h = strtolower(trim($host));
        if ($h === '') return null;
        if ($h[0] === '[') {                       // [::1]:8080
            return null;                           // an IP literal is never a tenant
        }
        $h = explode(':', $h)[0];
        $h = trim($h, '.');
        if ($h === '') return null;

        // A bare IPv4 address carries no subdomain.
        if (filter_var($h, FILTER_VALIDATE_IP) !== false) return null;

        $base = $base ?? tenantBaseDomain();
        if ($base === null || $base === '') return null;
        $base = strtolower(trim($base, '.'));

        // The root domain itself is not a tenant.
        if ($h === $base) return null;

        $suffix = '.' . $base;
        if (substr($h, -strlen($suffix)) !== $suffix) return null;   // different domain entirely

        $label = substr($h, 0, strlen($h) - strlen($suffix));
        if ($label === '' || strpos($label, '.') !== false) {
            // Multi-level (a.b.bms.co.tz) is not a tenant address. Accepting it
            // would let 'evil.kampunia' masquerade as a neighbour of 'kampunia'.
            return null;
        }

        // Reserved labels belong to the platform, never to a tenant. Provisioning
        // already refuses to hand these out, so this is a second line of defence
        // against a row that predates the rule or was inserted by hand.
        //
        // The full list lives in core/tenant_provisioner.php, which is far too
        // heavy to load on every request just for a constant — so it is used when
        // already loaded, and a platform-critical subset is applied otherwise.
        $reserved = defined('TENANT_RESERVED_SUBDOMAINS')
            ? TENANT_RESERVED_SUBDOMAINS
            : ['www', 'admin', 'superadmin', 'api', 'app', 'mail', 'static', 'cdn', 'status'];

        if (in_array($label, $reserved, true)) return null;

        return $label;
    }
}

if (!function_exists('resolveTenantFromRequest')) {
    /**
     * Decide which tenant (if any) this request is for.
     *
     * @param ?string $host  defaults to $_SERVER['HTTP_HOST']
     * @return array{status:string, subdomain:?string, tenant:?array}
     *
     * status is one of:
     *   'disabled' — multi-tenancy is switched off; behave as single-tenant.
     *   'none'     — not a tenant address (root domain, reserved label, CLI,
     *                an IP, or an unrelated domain). The platform's own pages.
     *   'unknown'  — it LOOKED like a tenant address but no such tenant exists.
     *                Distinct from 'none' on purpose: serving the main database
     *                for a made-up subdomain would hand one company's data to
     *                anyone who guessed a hostname.
     *   'found'    — 'tenant' holds the registry row.
     */
    function resolveTenantFromRequest(?string $host = null): array
    {
        $none = ['status' => 'none', 'subdomain' => null, 'tenant' => null];

        if (!tenantModeEnabled()) {
            return ['status' => 'disabled', 'subdomain' => null, 'tenant' => null];
        }

        // Fall back to the request's host. The ABSENCE of a host — not merely an
        // absent argument — is what marks something as not a tenant request, so
        // migrations, cron and tests run against the main database while a caller
        // that deliberately supplies a host (or sets HTTP_HOST) still resolves.
        $host = $host ?? ($_SERVER['HTTP_HOST'] ?? null);
        if ($host === null || trim($host) === '') return $none;

        $sub = extractTenantSubdomain($host);
        if ($sub === null) return $none;

        try {
            $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE subdomain = ? LIMIT 1");
            $st->execute([$sub]);
            $row = $st->fetch();
        } catch (Throwable $e) {
            // The control database being unreachable must not be reported as
            // "no such tenant" — that would silently fall through to the main
            // database. Surface it as a distinct, loud failure instead.
            error_log('tenant resolution failed for "' . $sub . '": ' . $e->getMessage());
            return ['status' => 'error', 'subdomain' => $sub, 'tenant' => null,
                    'error' => $e->getMessage()];
        }

        if (!$row) return ['status' => 'unknown', 'subdomain' => $sub, 'tenant' => null];

        return ['status' => 'found', 'subdomain' => $sub, 'tenant' => $row];
    }
}
