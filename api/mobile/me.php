<?php
/**
 * api/mobile/me.php
 *
 * GET — returns the full context the Flutter app needs after login:
 * user, company, warehouses, active shift, POS settings, tax rates,
 * and the permission flags the app gates UI features on.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
require_once __DIR__ . '/../../core/pos_nav.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!mobileBearerAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

global $pdo;

$user_id = (int)$_SESSION['user_id'];
$role_id = (int)($_SESSION['role_id'] ?? 0);

try {
    // ── User row ─────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // The EFFECTIVE language: an admin can set one for the whole company
    // (Language Mode = organization, core/i18n.php orgLanguage()); else the user's own.
    $orgLang  = function_exists('orgLanguage') ? orgLanguage() : null;
    $language = $orgLang ?? get_setting('user_language_' . $user_id, 'en');
    $currency = bmsCompanyCurrency();   // same currency the web uses (core/currency.php)

    // ── Company ───────────────────────────────────────────────────────────────
    $company = [
        'name'     => get_setting('company_name',     ''),
        'logo_url' => get_setting('company_logo',     ''),
        'currency' => $currency,
        'address'  => get_setting('company_address',  '') ?: get_setting('company_physical_address', ''),
        'phone'    => get_setting('company_phone',    ''),
        'email'            => get_setting('company_email', ''),
        'website'          => get_setting('company_website', ''),
        'tin'              => get_setting('company_tin', ''),
        'vrn'              => get_setting('company_vrn', ''),
        'physical_address' => get_setting('company_physical_address', ''),
        'postal_address'   => get_setting('company_postal_address', ''),
        'code_prefix'      => get_setting('company_code_prefix', ''),
    ];

    // ── Warehouses the user's scope allows ────────────────────────────────────
    $warehouses = [];
    try {
        $wh = $pdo->query("
            SELECT warehouse_id, warehouse_name
              FROM warehouses
             WHERE status = 'active'
             ORDER BY warehouse_name
        ");
        $warehouses = array_values(array_filter($wh->fetchAll(PDO::FETCH_ASSOC) ?: [],
            fn($w) => userCan('warehouse', (int)$w['warehouse_id'])));
    } catch (Exception $e) { /* table may not exist yet */ }

    // ── Active shift ──────────────────────────────────────────────────────────
    $shift = null;
    try {
        $sh = $pdo->prepare("
            SELECT shift_id, shift_code, register_id, warehouse_id,
                   start_time, starting_cash, status
              FROM cash_register_shifts
             WHERE user_id = ? AND status = 'active'
             ORDER BY start_time DESC
             LIMIT 1
        ");
        $sh->execute([$user_id]);
        $row = $sh->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $shift = $row;
        }
    } catch (Exception $e) { /* non-fatal */ }

    // ── POS settings ──────────────────────────────────────────────────────────
    $simpleMode = posSimpleModeEnabled();
    $pos_settings = [
        // Display preferences
        'discount_type'      => get_setting('pos_discount_type',      'percentage'),
        'receipt_width'      => (int)get_setting('pos_receipt_width',  '80'),
        'auto_print_receipt' => get_setting('pos_auto_print_receipt', '0') === '1',

        // Simple POS mode flags — Flutter uses these to decide which form variant to render
        // simple_mode=true  → simplified forms (see below), credit sales enabled, simple dashboard
        // simple_mode=false → full ERP forms with all fields
        'simple_mode'          => $simpleMode,

        // Per-entity form overrides (only relevant when simple_mode=true):
        // true = show full form for that entity even while simple_mode is on
        'advanced_product'     => advancedProductEnabled(),
        'advanced_customer'    => advancedCustomerEnabled(),
        'advanced_supplier'    => advancedSupplierEnabled(),

        // Whether the Suppliers module is reachable in Simple POS mode
        // (separate from advanced_supplier — this controls visibility, not form depth)
        'supplier_access'      => supplierAccessEnabled(),

        // How many products to render in the grid on open (before any search/filter)
        'products_display_limit' => (int)get_setting('pos_products_display_limit', '20'),
        'products_display_limit_mobile' => (int)get_setting('pos_products_display_limit_mobile', '10'),

        // VAT selector is shown in all POS modes (v3 change — Simple POS included)
        'vat_enabled'          => true,

        // Shop mode: true = "Duka/Shop" wording; false = "Ghala/Warehouse" wording
        'shop_mode'            => get_setting('shop_mode', '0') === '1',

        // Record Old Sale: how many days back an old sale may be dated
        // (core/pos_old_sales.php; POS Settings, default 30).
        'old_sale_max_days'    => (int)get_setting('pos_old_sale_max_days', '30') ?: 30,
    ];
    // Loyalty (POS Advanced plans only — same entitlement gate as the web settings page).
    $loyaltyEntitled = function_exists('canView') && canView('pos_advanced');
    $pos_settings['loyalty'] = [
        'available'       => $loyaltyEntitled,
        'enabled'         => $loyaltyEntitled && get_setting('pos_loyalty_enabled', '0') === '1',
        'spend_per_point' => (float)get_setting('pos_loyalty_spend_per_point', '1000'),
        'redeem_value'    => (float)get_setting('pos_loyalty_redeem_value', '50'),
    ];

    // ── Tax rates ─────────────────────────────────────────────────────────────
    $tax_rates = [];
    try {
        $tr = $pdo->query("
            SELECT rate_id, rate_name, rate_percentage
              FROM tax_rates
             ORDER BY rate_name
        ");
        $tax_rates = $tr->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* table may not exist in all tenants */ }

    // ── Permissions (gates used by the Flutter app) ───────────────────────────
    $permissions = [
        'pos_create'       => function_exists('canCreate') ? canCreate('pos')       : false,
        'pos_view'         => function_exists('canView')   ? canView('pos')         : false,
        'inventory_create' => function_exists('canCreate') ? canCreate('inventory') : false,
        'inventory_view'   => function_exists('canView')   ? canView('inventory')   : false,
        'reports_view'     => function_exists('canView')   ? canView('reports')     : false,
        'customers_view'   => function_exists('canView')   ? canView('customers')   : false,
        'customers_create' => function_exists('canCreate') ? canCreate('customers') : false,
        // Admin-only settings screens (same gates as the web pages).
        'users_manage'      => isAdmin(),
        'company_edit'      => isAdmin(),
        'pos_settings_edit' => function_exists('canEdit') ? canEdit('pos_config_settings') : false,
        // Record Old Sale (process_sale old_sale + old_sale_context) — same gate as the web button.
        'pos_past_sales'    => function_exists('canView') ? canView('pos_past_sales') : false,
    ];

    // ── Plan limits (core/tenant_quotas.php): null max = unlimited ────────────
    $limits = [
        'shops' => ['used' => function_exists('tenantActiveShopCount') ? tenantActiveShopCount($pdo) : null,
                    'max'  => function_exists('tenantShopLimit') ? tenantShopLimit() : null],
        'users' => ['used' => function_exists('tenantActiveUserCount') ? tenantActiveUserCount($pdo) : null,
                    'max'  => function_exists('tenantUserLimit') ? tenantUserLimit() : null],
    ];

    // ── Subscription summary for every user (the web's header banner) ─────────
    // Admins open the full page via api/mobile/subscription.php (can_manage).
    $subscription = null;
    if (function_exists('bmsCurrentTenant') && ($tRow = bmsCurrentTenant())) {
        require_once __DIR__ . '/../../core/tenant_lifecycle_policy.php';
        $endsAt   = tenantAccessEndsAt($tRow);
        $daysLeft = tenantDaysRemaining($tRow);
        $graceUntil = !empty($tRow['grace_until']) ? substr((string)$tRow['grace_until'], 0, 10) : null;
        $state = $endsAt === null ? 'no_end'
               : ($daysLeft !== null && $daysLeft < 0 ? 'grace' : ((string)$tRow['status'] === 'trial' ? 'trial' : 'active'));
        $planName = null;
        if (!empty($tRow['plan'])) {
            try {
                $ps = getControlPdo()->prepare("SELECT name FROM plans WHERE plan_key = ?");
                $ps->execute([(string)$tRow['plan']]);
                $planName = $ps->fetchColumn() ?: null;
            } catch (Throwable $e) { /* plan catalogue not set up */ }
        }
        $subscription = [
            'plan_key'   => $tRow['plan'] ?? null,
            'plan_name'  => $planName,
            'status'     => $state,                 // trial | active | grace | no_end
            'ends_at'    => $endsAt !== null ? substr($endsAt, 0, 10) : null,
            'days_left'  => $daysLeft,              // negative while in grace
            'grace_until'     => $state === 'grace' ? $graceUntil : null,
            'grace_days_left' => $state === 'grace' && $graceUntil ? max(0, (int)floor((strtotime($graceUntil) - strtotime('today')) / 86400)) : null,
            // Same rule as the web banner: last 7 days before the end, or in grace.
            'warning'    => $state === 'grace' || ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7),
            'can_manage' => isAdmin(),
        ];
    }

    // ── "Ask Smart POS / Ask BMS" (api/mobile/ai_ask.php) — same gates as the web chat ──
    require_once __DIR__ . '/../../core/ai_service.php';
    $aiAllowed = (!function_exists('tenantFeatureEnabled') || tenantFeatureEnabled('ai_assistant')) && canView('ai_assistant');
    $aiReady   = $aiAllowed && aiConfigured();
    $ai = [
        'allowed'       => $aiAllowed,                       // module on + this user may use it
        'ready'         => $aiReady,                         // … and a key is set: show the chat
        'limit_reached' => $aiReady && !empty(aiCapInfo()['exceeded']),
        'name'          => function_exists('aiProductName') ? aiProductName() : 'Smart POS',
    ];

    echo json_encode([
        'success' => true,
        'limits'  => $limits,
        'subscription' => $subscription,
        'ai'      => $ai,
        'user'    => [
            'id'         => (int)$user['user_id'],
            'name'       => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name' => $user['first_name'] ?? '',
            'last_name'  => $user['last_name']  ?? '',
            'role'       => $user['user_role']  ?? $user['role'] ?? 'user',
            'role_id'    => $role_id,
            'phone'      => $user['username']   ?? '',
            'email'      => $user['email']      ?? '',
            'language'   => $language,
            'language_mode'   => $orgLang !== null ? 'organization' : 'user',
            'language_locked' => $orgLang !== null,   // true → hide the app's language switch
            'currency'   => $currency,
            'is_admin'   => isAdmin(),
            'avatar_url' => !empty($user['avatar']) ? 'uploads/avatars/' . basename($user['avatar']) : '',
        ],
        'company'      => $company,
        'warehouses'   => $warehouses,
        'shift'        => $shift,
        'pos_settings' => $pos_settings,
        'tax_rates'    => $tax_rates,
        'permissions'  => $permissions,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
