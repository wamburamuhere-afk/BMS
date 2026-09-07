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

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;

$active_only = !empty($_GET['active_only']);
$sql = "SELECT register_id, register_name, register_code, location, opening_cash, status,
               receipt_printer, barcode_scanner, cash_drawer, card_reader,
               receipt_header, receipt_footer, receipt_logo, default_cashier
          FROM pos_registers";
if ($active_only) $sql .= " WHERE status = 'active'";
$sql .= " ORDER BY register_name";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $rows]);
