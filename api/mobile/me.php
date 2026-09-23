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

    $language = get_setting('user_language_' . $user_id, 'en');
    $currency = get_setting('company_currency', 'TZS');

    // ── Company ───────────────────────────────────────────────────────────────
    $company = [
        'name'     => get_setting('company_name',     ''),
        'logo_url' => get_setting('company_logo',     ''),
        'currency' => $currency,
        'address'  => get_setting('company_address',  ''),
        'phone'    => get_setting('company_phone',    ''),
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
        $warehouses = $wh->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* table may not exist yet */ }

    // ── Active shift ──────────────────────────────────────────────────────────
    $shift = null;
    try {
        $sh = $pdo->prepare("
            SELECT shift_id, shift_code, register_id, warehouse_id,
                   start_time, starting_cash, status
              FROM cash_register_shifts
             WHERE user_id = ? AND status = 'active'
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

        // VAT is hidden and always 0 when simple_mode=true
        'vat_enabled'          => !$simpleMode,
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
    ];

    echo json_encode([
        'success' => true,
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
            'currency'   => $currency,
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
