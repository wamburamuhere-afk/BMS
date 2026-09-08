<?php
/**
 * api/pos/test_network_printer.php
 *
 * Phase 21 (pos_upgrade_plan.md §8) — send a short test print to a network
 * (IP) thermal printer, from the register settings modal, before saving.
 * POST: printer_ip_address, printer_port
 * Permission: canEdit('pos_config_settings'), gated behind 'pos_advanced'
 * (same dual-gate the register CRUD endpoints already use).
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/escpos_printer.php';

header('Content-Type: application/json');

if (!isAuthenticated())              { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))        { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Multi-register management is not included in your plan.')]); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$ip = trim($_POST['printer_ip_address'] ?? '');
$port = (int)($_POST['printer_port'] ?? 9100) ?: 9100;

if ($ip === '') {
    echo json_encode(['success' => false, 'message' => t('Enter a printer IP address first.')]);
    exit;
}

$bytes = ESCPOS_INIT . ESCPOS_ALIGN_CENTER . ESCPOS_BOLD_ON
    . "BMS POS\n" . ESCPOS_BOLD_OFF
    . "*** TEST PRINT OK ***\n"
    . date('Y-m-d H:i:s') . "\n\n\n"
    . ESCPOS_CUT;

$result = sendToNetworkPrinter($ip, $port, $bytes);

echo json_encode([
    'success' => $result['success'],
    'message' => $result['success'] ? t('Test print sent — check the printer.') : $result['error'],
]);
