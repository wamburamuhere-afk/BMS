<?php
// scope-audit: skip — emails one sale's own receipt to a caller-supplied address
// under canView('pos') + the same warehouse-scope guard as print_receipt.php.
/**
 * API: Email a POS Receipt (Phase 10, pos_upgrade_plan.md §7)
 * POST: sale_id, email
 * Uses the real SMTP-backed sendEmail() (core/mailer.php) — fails clearly with
 * mailer_last_error() if SMTP isn't configured, rather than pretending to send.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/mailer.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$sale_id = (int)($_POST['sale_id'] ?? 0);
$email   = trim($_POST['email'] ?? '');

if ($sale_id <= 0) { echo json_encode(['success' => false, 'message' => t('Invalid sale.')]); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => t('Please enter a valid email address.')]); exit; }

$stmt = $pdo->prepare("
    SELECT s.*, c.customer_name, u.username AS cashier_name
      FROM pos_sales s
      LEFT JOIN customers c ON s.customer_id = c.customer_id
      LEFT JOIN users u ON s.user_id = u.user_id
     WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) { echo json_encode(['success' => false, 'message' => t('Sale not found.')]); exit; }

$wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
if ($wid !== null && !userCan('warehouse', $wid)) {
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

$items = $pdo->prepare("SELECT product_name, quantity, unit_price, line_total FROM pos_sale_items WHERE sale_id = ? ORDER BY sale_item_id");
$items->execute([$sale_id]);
$lines = $items->fetchAll(PDO::FETCH_ASSOC);

$company_name = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$currency     = getSetting('currency', 'TZS');

$rows = '';
foreach ($lines as $l) {
    $rows .= '<tr>'
        . '<td style="padding:4px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($l['product_name']) . '</td>'
        . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:center;">' . htmlspecialchars((string)$l['quantity']) . '</td>'
        . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right;">' . number_format((float)$l['unit_price'], 2) . '</td>'
        . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right;">' . number_format((float)$l['line_total'], 2) . '</td>'
        . '</tr>';
}

$html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;color:#222;">'
    . '<h2 style="margin:0 0 4px;">' . htmlspecialchars($company_name) . '</h2>'
    . '<p style="color:#666;margin:0 0 16px;">Receipt #' . htmlspecialchars($sale['receipt_number']) . ' — ' . date('d/m/Y H:i', strtotime($sale['sale_date'])) . '</p>'
    . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
    . '<thead><tr style="background:#f6f6f6;"><th style="padding:4px 8px;text-align:left;">Item</th><th style="padding:4px 8px;">Qty</th><th style="padding:4px 8px;text-align:right;">Price</th><th style="padding:4px 8px;text-align:right;">Total</th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table>'
    . '<div style="margin-top:12px;text-align:right;">'
    . '<div>Subtotal: ' . $currency . ' ' . number_format((float)$sale['subtotal'], 2) . '</div>'
    . '<div>Tax: ' . $currency . ' ' . number_format((float)$sale['tax_amount'], 2) . '</div>'
    . '<div style="font-weight:bold;font-size:15px;">Total: ' . $currency . ' ' . number_format((float)$sale['grand_total'], 2) . '</div>'
    . '</div>'
    . '<p style="margin-top:20px;color:#888;font-size:12px;">Thank you for your business.</p>'
    . '</div>';

$ok = sendEmail($email, 'Receipt #' . $sale['receipt_number'] . ' — ' . $company_name, $html);

if ($ok) {
    logActivity($pdo, $_SESSION['user_id'], 'Emailed POS Receipt', "Emailed receipt #{$sale['receipt_number']} to $email");
    echo json_encode(['success' => true, 'message' => sprintf(t('Receipt emailed to %s'), $email)]);
} else {
    echo json_encode(['success' => false, 'message' => sprintf(t('Could not send email: %s'), mailer_last_error() ?: t('unknown error'))]);
}
