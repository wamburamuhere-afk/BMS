<?php
/**
 * core/vendor_context.php — is this request BJP selling the system itself?
 *
 * The platform's root domain (TENANT_BASE_DOMAIN, e.g. bms.bjptechnologies.co.tz)
 * resolves to no tenant and runs on BJP's own database. Only there do the
 * "platform vendor" features exist: subscription services, linking a visited
 * client to their tenant, and the paid-invoice → subscription bridge. Every
 * tenant subdomain, and every install without multi-tenancy, gets none of it —
 * the same rule register.php follows.
 */
require_once __DIR__ . '/tenant_resolver.php';

if (!function_exists('vendorHostMatchesBase')) {
    /** PURE: does $host name the platform root itself (optionally www.)? */
    function vendorHostMatchesBase(?string $host, ?string $base): bool
    {
        if ($host === null || $base === null || $base === '') return false;
        $h = strtolower(trim(explode(':', trim($host))[0], '.'));
        $b = strtolower(trim($base, '.'));
        return $h !== '' && ($h === $b || $h === 'www.' . $b);
    }
}

if (!function_exists('isPlatformVendorContext')) {
    function isPlatformVendorContext(): bool
    {
        if (!function_exists('tenantModeEnabled') || !tenantModeEnabled()) return false;
        if (function_exists('bmsCurrentTenant') && bmsCurrentTenant() !== null) return false;

        if (PHP_SAPI === 'cli') {
            // Cron/workers on the root install opt in explicitly; tests set it too.
            return getenv('BMS_VENDOR_CONTEXT') === '1';
        }
        return vendorHostMatchesBase($_SERVER['HTTP_HOST'] ?? null, tenantBaseDomain());
    }
}
