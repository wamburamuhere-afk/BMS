<?php
// scope-audit: skip — registers are a small global lookup table (no project/warehouse scope), same as tax_rates/brands
/**
 * API: List POS Registers (Tills)
 * GET ?active_only=1 to restrict to registers a cashier may sign in at (used by
 * the Open Shift modal). Full list (any status) is used by the Registers
 * management screen in pos_config_settings.php.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
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
               r.printer_connection_type, r.printer_ip_address, r.printer_port,
               sh.shift_id AS active_shift_id,
               u.username AS active_cashier_name,
               DATE_FORMAT(sh.start_time, '%d %b, %H:%i') AS active_shift_started_label
          FROM pos_registers r
          LEFT JOIN cash_register_shifts sh ON sh.register_id = r.register_id AND sh.status = 'active'
          LEFT JOIN users u ON u.user_id = sh.user_id";
if ($active_only) $sql .= " WHERE r.status = 'active'";
$sql .= " ORDER BY r.register_name";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $rows]);
