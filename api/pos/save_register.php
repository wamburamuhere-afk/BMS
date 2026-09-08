<?php
// scope-audit: skip — registers are a small global lookup table (no project/warehouse scope), same as tax_rates/brands
/**
 * API: Create/Update a POS Register (Till)
 * POST: register_id (blank = create), register_name, register_code, location,
 *       opening_cash, barcode_scanner, cash_drawer, card_reader,
 *       receipt_header, receipt_footer, receipt_logo
 * Permission: canEdit('pos_config_settings') — register setup is an admin/settings action.
 * Entitlement: Phase 13 (pos_upgrade_plan.md §7) — multi-register management
 * is gated behind the 'pos_advanced' tenant feature.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}


if (!isAuthenticated())            { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))      { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Multi-register management is not included in your plan.')]); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$register_id    = (int)($_POST['register_id'] ?? 0);
$register_name  = trim($_POST['register_name'] ?? '');
$register_code  = trim($_POST['register_code'] ?? '');
$location       = trim($_POST['location'] ?? '');
$opening_cash   = (float)($_POST['opening_cash'] ?? 0);
$barcode_scanner = !empty($_POST['barcode_scanner']) ? 1 : 0;
$cash_drawer     = !empty($_POST['cash_drawer']) ? 1 : 0;
$card_reader     = !empty($_POST['card_reader']) ? 1 : 0;
$receipt_header = trim($_POST['receipt_header'] ?? '') ?: null;
$receipt_footer = trim($_POST['receipt_footer'] ?? '') ?: null;

// Phase 21 (pos_upgrade_plan.md §8) — real network (IP) thermal-printer
// support. 'browser' (the pre-existing behaviour) stays the default.
$printer_connection_type = ($_POST['printer_connection_type'] ?? 'browser') === 'network' ? 'network' : 'browser';
$printer_ip_address = trim($_POST['printer_ip_address'] ?? '') ?: null;
$printer_port = (int)($_POST['printer_port'] ?? 9100) ?: 9100;

// Phase 22 (pos_upgrade_plan.md §8) — receipt layout variety.
$receipt_template = in_array($_POST['receipt_template'] ?? 'classic', ['classic', 'detailed', 'slim'], true)
    ? $_POST['receipt_template'] : 'classic';

if ($register_name === '' || $register_code === '') {
    echo json_encode(['success' => false, 'message' => t('Register name and code are required.')]);
    exit;
}
if ($printer_connection_type === 'network' && $printer_ip_address === null) {
    echo json_encode(['success' => false, 'message' => t('A printer IP address is required for a network-connected printer.')]);
    exit;
}

try {
    // Duplicate code check (UNIQUE KEY register_code already enforces this at the
    // DB level, but this gives a clear message instead of an opaque DB error).
    $dupSql = "SELECT COUNT(*) FROM pos_registers WHERE register_code = ?" . ($register_id > 0 ? " AND register_id != ?" : "");
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute($register_id > 0 ? [$register_code, $register_id] : [$register_code]);
    if ($dupStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => t('Register code already exists. Please use a different code.')]);
        exit;
    }

    if ($register_id > 0) {
        $pdo->prepare("UPDATE pos_registers
                          SET register_name = ?, register_code = ?, location = ?, opening_cash = ?,
                              barcode_scanner = ?, cash_drawer = ?, card_reader = ?,
                              receipt_header = ?, receipt_footer = ?,
                              printer_connection_type = ?, printer_ip_address = ?, printer_port = ?,
                              receipt_template = ?,
                              updated_at = NOW()
                        WHERE register_id = ?")
            ->execute([$register_name, $register_code, $location, $opening_cash,
                       $barcode_scanner, $cash_drawer, $card_reader,
                       $receipt_header, $receipt_footer,
                       $printer_connection_type, $printer_ip_address, $printer_port,
                       $receipt_template, $register_id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated POS register: $register_name ($register_code)");
        $message = t('Register updated successfully.');
    } else {
        $pdo->prepare("INSERT INTO pos_registers
                          (register_name, register_code, location, opening_cash, status,
                           barcode_scanner, cash_drawer, card_reader, receipt_header, receipt_footer,
                           printer_connection_type, printer_ip_address, printer_port, receipt_template, created_at, updated_at)
                       VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$register_name, $register_code, $location, $opening_cash,
                       $barcode_scanner, $cash_drawer, $card_reader, $receipt_header, $receipt_footer,
                       $printer_connection_type, $printer_ip_address, $printer_port, $receipt_template]);
        $register_id = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created POS register: $register_name ($register_code)");
        $message = t('Register created successfully.');
    }

    echo json_encode(['success' => true, 'message' => $message, 'register_id' => $register_id]);

} catch (PDOException $e) {
    error_log('save_register: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
