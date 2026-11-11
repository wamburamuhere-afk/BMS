<?php
/**
 * core/public_site.php — the public website on the platform's own address (2026-11-11).
 *
 * The landing page, robots.txt and sitemap.xml exist ONLY on the platform root
 * (e.g. bms.bjptechnologies.co.tz, demo.bjptechnologies.co.tz) — never on a company's own
 * address (name.bms.bjptechnologies.co.tz) and never on the Superadmin address.
 *
 * Everything shown comes from the system: legal name, address and the main public website
 * from Superadmin › Platform settings › Branding; phone / WhatsApp / email / hours from
 * Superadmin › Support settings; products, plans and prices from listPublicOffers().
 */

require_once __DIR__ . '/platform_settings.php';

if (!function_exists('publicSiteDefaultLegalName')) {
    /** Used only until Superadmin saves a legal name (Platform settings › Branding). */
    function publicSiteDefaultLegalName(): string
    {
        return 'BJP TECHNOLOGIES (L) LTD';
    }
}

if (!function_exists('publicSiteRequestBase')) {
    /** This request's own scheme://host (no path). */
    function publicSiteRequestBase(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = strtolower(preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
        return ($https ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
    }
}

if (!function_exists('publicSiteInfo')) {
    /**
     * @return array{legal_name:string, address:string, public_url_setting:string, base:string,
     *               indexable:bool, email:string, phone:string, whatsapp:string, hours:string}
     *   base       — the address pages are canonical on (the main site when one is set, else this one)
     *   indexable  — false on a second copy (main site set and different from this host): hidden from search
     */
    function publicSiteInfo(): array
    {
        $get = function (string $k, string $d = ''): string {
            try { return trim((string)getPlatformSetting($k, $d)); } catch (Throwable $e) { return $d; }
        };
        $legal = $get('platform_legal_name');
        $url = rtrim($get('platform_public_url'), '/');
        $self = publicSiteRequestBase();
        $sameHost = $url === '' || strtolower((string)parse_url($url, PHP_URL_HOST)) === strtolower((string)parse_url($self, PHP_URL_HOST));

        $sup = [];
        try { require_once __DIR__ . '/support.php'; $sup = supportSettings(); } catch (Throwable $e) { $sup = []; }
        return [
            'legal_name'         => $legal !== '' ? $legal : publicSiteDefaultLegalName(),
            'address'            => $get('platform_address'),
            'public_url_setting' => $url,
            'base'               => $url !== '' ? $url : $self,
            'indexable'          => $sameHost,
            'email'              => trim((string)($sup['support_email'] ?? '')),
            'phone'              => trim((string)($sup['support_phone'] ?? '')),
            'whatsapp'           => trim((string)($sup['support_whatsapp'] ?? '')),
            'hours'              => trim((string)($sup['support_hours'] ?? '')),
        ];
    }
}

if (!function_exists('publicSiteValidate')) {
    /** Branding form → values to save. @return array{ok:bool, error:?string, values:array<string,string>} */
    function publicSiteValidate(array $in): array
    {
        $legal = trim((string)($in['platform_legal_name'] ?? ''));
        $addr  = trim((string)($in['platform_address'] ?? ''));
        $url   = rtrim(trim((string)($in['platform_public_url'] ?? '')), '/');
        if (mb_strlen($legal) > 191) return ['ok' => false, 'error' => 'Company legal name: up to 191 characters.', 'values' => []];
        if (mb_strlen($addr) > 255)  return ['ok' => false, 'error' => 'Company address: up to 255 characters.', 'values' => []];
        if ($url !== '') {
            $p = parse_url($url);
            if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
                || isset($p['query']) || isset($p['fragment']) || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')) {
                return ['ok' => false, 'error' => 'Main public website: enter just the address, e.g. https://bms.example.co.tz', 'values' => []];
            }
            $url = strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
        }
        return ['ok' => true, 'error' => null,
                'values' => ['platform_legal_name' => $legal, 'platform_address' => $addr, 'platform_public_url' => $url]];
    }
}

if (!function_exists('publicSiteIsPlatformRoot')) {
    /**
     * Is this request on the platform's own address — not a company's, not Superadmin's?
     * Same rule register.php uses: a company address resolves to a tenant ('found'), an unknown
     * company address is 'unknown'; the root (or a single-company install) is 'none'/'disabled'.
     */
    function publicSiteIsPlatformRoot(): bool
    {
        try {
            require_once __DIR__ . '/tenant_resolver.php';
            $r = resolveTenantFromRequest();
            if (in_array($r['status'] ?? '', ['found', 'unknown', 'error'], true)) return false;
            require_once __DIR__ . '/superadmin_auth.php';
            if (function_exists('isSuperadminHost') && isSuperadminHost()) return false;
            return true;
        } catch (Throwable $e) {
            error_log('publicSiteIsPlatformRoot: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('publicSitePages')) {
    /** The public pages: path => [en title, sw title]. Order = the menu and the sitemap. */
    function publicSitePages(): array
    {
        return [
            ''                     => ['Home', 'Mwanzo'],
            'smart-pos'            => ['Smart POS', 'Smart POS'],
            'smart-pos/retail'     => ['Smart POS · Retail shop', 'Smart POS · Duka la rejareja'],
            'smart-pos/restaurant' => ['Smart POS · Restaurant', 'Smart POS · Mgahawa'],
            'smart-bms'            => ['Smart BMS', 'Smart BMS'],
            'pricing'              => ['Pricing', 'Bei'],
            'faq'                  => ['FAQ', 'Maswali'],
            'contact'              => ['Contact', 'Wasiliana'],
        ];
    }
}
