<?php
/**
 * api/mobile/platform_info.php
 *
 * GET — no auth required.
 *
 * Returns platform-level branding for the mobile app's Welcome / splash screen:
 * platform name, logo, tagline, registration availability, support contact, and
 * the canonical platform URL so the app never hard-codes anything beyond the
 * one initial discovery endpoint.
 *
 * Cache-Control: 1 hour — platform settings change rarely.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/../../core/platform_settings.php'; // getPlatformSetting()
require_once __DIR__ . '/../../core/tenant_resolver.php';   // tenantBaseDomain()

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$base   = tenantBaseDomain() ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$platformUrl = $base !== '' ? $scheme . '://' . $base : '';

// Build an absolute logo URL.  The stored value may be relative (uploads/logo.png)
// or already absolute — normalise both to a full URL the app can display.
$rawLogo = getPlatformSetting('platform_logo_url', '');
$logoUrl = '';
if ($rawLogo !== '') {
    if (str_starts_with($rawLogo, 'http://') || str_starts_with($rawLogo, 'https://')) {
        $logoUrl = $rawLogo;
    } elseif ($platformUrl !== '') {
        $logoUrl = $platformUrl . '/' . ltrim($rawLogo, '/');
    }
}

echo json_encode([
    'success'           => true,
    'platform_name'     => getPlatformSetting('platform_name',    'BMS — Business Management System'),
    'platform_logo_url' => $logoUrl,
    'tagline'           => getPlatformSetting('platform_tagline', 'Run your business, anywhere.'),
    // false → hide the Register button in the mobile app (superadmin can disable)
    'registration_open' => getPlatformSetting('self_registration_enabled', '1') === '1',
    'support_email'     => getPlatformSetting('support_email',
                               getPlatformSetting('smtp_from_email', '')),
    // The app uses this to construct all other API calls — no hard-coded base URL in the binary
    'platform_url'      => $platformUrl,
]);
