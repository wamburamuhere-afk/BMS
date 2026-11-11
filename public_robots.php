<?php
/**
 * public_robots.php — /robots.txt, per address (2026-11-11).
 *
 * Platform's own address, set as the main site: the public pages may be indexed; app areas may not.
 * Everything else — a company's own address, the Superadmin address, a second copy whose main site
 * is elsewhere (Superadmin › Platform settings › Branding › Main public website) — asks search
 * engines to stay out entirely.
 */
require_once __DIR__ . '/core/public_site.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$site = publicSiteInfo();
if (publicSiteIsPlatformRoot() && $site['indexable']) {
    echo "User-agent: *\n";
    echo "Allow: /\n";
    foreach (['/api/', '/ajax/', '/actions/', '/app/', '/uploads/', '/login', '/forgot-password', '/change-password'] as $p) {
        echo "Disallow: $p\n";
    }
    echo "\nSitemap: " . $site['base'] . "/sitemap.xml\n";
} else {
    echo "User-agent: *\nDisallow: /\n";
}
