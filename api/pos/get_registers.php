<?php
/**
 * API: List POS Registers (Tills)
 * GET ?active_only=1 to restrict to registers a cashier may sign in at (used by
 * the Open Shift modal). Full list (any status) is used by the Registers
 * management screen in pos_config_settings.php.
 *
 * Warehouse scope (2026-09-16): a register optionally belongs to one shop
 * (pos_registers.warehouse_id). A non-admin only sees registers whose shop
 * they were actually granted via Settings > Admin > Project & Warehouse
 * Access (userCan('warehouse', ...)) — plus every still-unassigned ("legacy")
 * register, which stays visible to everyone exactly as before this column
 * existed. Admins and the full-management list (no active_only) always see
 * every register regardless of status, matching the pre-existing "management
 * screen sees everything" behaviour.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}


if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

global $pdo;

$active_only = !empty($_GET['active_only']);
// LEFT JOINs surface whether a register is currently staffed (Phase 8's
// "one active shift per register" rule in open_shift.php) so the Start Shift
// modal can disable a busy register up front instead of the cashier only
// finding out after submitting. canEdit('pos') users can also use this to
// know who to ask before force-closing a stuck shift from Shift History.
$sql = "SELECT r.register_id, r.register_name, r.register_code, r.location, r.opening_cash, r.status,
               r.receipt_printer, r.barcode_scanner, r.cash_drawer, r.card_reader,
               r.receipt_header, r.receipt_footer, r.receipt_logo, r.default_cashier,
               r.printer_connection_type, r.printer_ip_address, r.printer_port, r.receipt_template,
               r.warehouse_id, w.warehouse_name,
               sh.shift_id AS active_shift_id,
               u.username AS active_cashier_name,
               DATE_FORMAT(sh.start_time, '%d %b, %H:%i') AS active_shift_started_label
          FROM pos_registers r
          LEFT JOIN warehouses w ON w.warehouse_id = r.warehouse_id
          LEFT JOIN cash_register_shifts sh ON sh.register_id = r.register_id AND sh.status = 'active'
          LEFT JOIN users u ON u.user_id = sh.user_id";
$where = [];
$params = [];
if ($active_only) $where[] = "r.status = 'active'";

// Shop scope (2026-09-16): the Open Shift modal (active_only=1) only offers
// registers this user may actually staff — their granted shop(s), plus any
// still-unassigned ("legacy") register. isAdmin() / hasAllWarehouseAccess()
// bypass this, same as every other warehouse-scoped list in the app. The
// full management list (pos_config_settings.php, no active_only) stays
// unfiltered so an admin can see and assign every register regardless of
// their own warehouse grant.
if ($active_only && !isAdmin() && !hasAllWarehouseAccess()) {
    $allowedWarehouses = warehouseIdsForUser($pdo, (int)$_SESSION['user_id'], false);
    if (empty($allowedWarehouses)) {
        $where[] = "r.warehouse_id IS NULL";
    } else {
        $ph = implode(',', array_fill(0, count($allowedWarehouses), '?'));
        $where[] = "(r.warehouse_id IS NULL OR r.warehouse_id IN ($ph))";
        $params = $allowedWarehouses;
    }
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY r.register_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $rows]);
