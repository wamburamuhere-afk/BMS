<?php
/**
 * public_sitemap.php — /sitemap.xml for the public website (2026-11-11).
 *
 * Only on the platform's own address when it is the main site (core/public_site.php); 404 elsewhere.
 * Every page in English and Kiswahili, each listing its other-language version.
 */
require_once __DIR__ . '/core/public_site.php';

$site = publicSiteInfo();
if (!publicSiteIsPlatformRoot() || !$site['indexable']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$lastmod = date('Y-m-d', (int)@filemtime(__DIR__ . '/landing.php') ?: time());
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$abs = fn(string $p, string $l) => $site['base'] . '/' . $p . ($l === 'sw' ? '?lang=sw' : '');
$pages = array_keys(publicSitePages());
$pages[] = 'register';   // the public sign-up page
$pages[] = 'privacy';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($pages as $p) {
    $langs = in_array($p, ['privacy'], true) ? ['en'] : ['en', 'sw'];
    foreach ($langs as $l) {
        echo "  <url>\n    <loc>" . $x($abs($p, $l)) . "</loc>\n    <lastmod>$lastmod</lastmod>\n";
        if (count($langs) > 1) {
            foreach ($langs as $alt) echo '    <xhtml:link rel="alternate" hreflang="' . $alt . '" href="' . $x($abs($p, $alt)) . "\"/>\n";
        }
        echo "    <priority>" . ($p === '' ? '1.0' : (in_array($p, ['smart-pos', 'smart-bms', 'pricing'], true) ? '0.8' : '0.5')) . "</priority>\n  </url>\n";
    }
}
echo "</urlset>\n";
