<?php
// scope-audit: skip — emails one sale's own receipt to a caller-supplied address
// under canView('pos') + the same warehouse-scope guard as print_receipt.php.
/**
 * API: Email a POS Receipt (Phase 10, pos_upgrade_plan.md §7)
 * POST: sale_id, email
 *
 * The receipt itself is delivered as a real PDF attachment — generated
 * server-side via the same generateLetterPdf() every other BMS document uses
 * (tender_print.php's BOQ/Materials/Checklist exports are the reference
 * pattern) — not as inline HTML in the email body. The email body is a short
 * covering note only. Full field parity with api/pos/print_receipt.php:
 * company letterhead (via use_letterhead), cashier, register, warehouse,
 * customer, discount, payment method/tendered/change — everything the
 * printed receipt shows, the emailed one now shows too.
 *
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
require_once __DIR__ . '/../../core/document_letter_pdf.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$sale_id = (int)($_POST['sale_id'] ?? 0);
$email   = trim($_POST['email'] ?? '');

if ($sale_id <= 0) { echo json_encode(['success' => false, 'message' => t('Invalid sale.')]); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => t('Please enter a valid email address.')]); exit; }

// Same field set as print_receipt.php: register_name/discount_amount/
// payment_method/amount_tendered/change_given all come free via s.*
// (denormalised on the sale row at checkout time).
$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.customer_name,
        u.username AS cashier_name,
        w.warehouse_name,
        r.receipt_footer AS reg_receipt_footer
      FROM pos_sales s
      LEFT JOIN customers c ON s.customer_id = c.customer_id
      LEFT JOIN users u ON s.user_id = u.user_id
      LEFT JOIN warehouses w ON s.warehouse_id = w.warehouse_id
      LEFT JOIN pos_registers r ON s.register_id = r.register_id
     WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) { echo json_encode(['success' => false, 'message' => t('Sale not found.')]); exit; }

// A walk-in sale has no customer_id (see process_sale.php), so the LEFT JOIN
// above leaves customer_name null — show "Walk-in Customer" explicitly,
// same as print_receipt.php, instead of silently omitting the row/recipient.
if (empty($sale['customer_name'])) {
    $sale['customer_name'] = t('Walk-in Customer');
}

$wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
if ($wid !== null && !userCan('warehouse', $wid)) {
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

$items = $pdo->prepare("SELECT product_name, quantity, unit_price, line_total, discount_amount, tax_rate FROM pos_sale_items WHERE sale_id = ? ORDER BY sale_item_id");
$items->execute([$sale_id]);
$lines = $items->fetchAll(PDO::FETCH_ASSOC);

$company_name = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$currency     = getSetting('currency', 'TZS');
$esc          = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// ── Build the PDF body content (TCPDF-safe HTML: real <table>s, no flexbox —
// the same style as core/tender_documents.php's PRINT_BOQ/Materials/
// Checklist builders). Company header/logo/address/TIN/VRN come from
// generateLetterPdf()'s own letterhead (use_letterhead => true below), so
// they are NOT repeated here.
$infoRows = [
    [t('Receipt #'), $sale['receipt_number']],
    [t('Date'), date('d/m/Y H:i', strtotime($sale['sale_date']))],
    [t('Cashier'), $sale['cashier_name'] ?? t('N/A')],
];
if (!empty($sale['register_name']))  $infoRows[] = [t('Register'), $sale['register_name']];
if (!empty($sale['warehouse_name'])) $infoRows[] = [t('Warehouse'), $sale['warehouse_name']];
if (!empty($sale['customer_name']))  $infoRows[] = [t('Customer'), $sale['customer_name']];

$infoHtml = '<table cellpadding="3" cellspacing="0" width="100%" style="font-size:11px;">';
foreach ($infoRows as [$k, $v]) {
    $infoHtml .= '<tr><td width="28%"><strong>' . $esc($k) . ':</strong></td><td>' . $esc($v) . '</td></tr>';
}
$infoHtml .= '</table>';

$itemsHtml = '<table border="1" cellpadding="4" cellspacing="0" width="100%" style="font-size:11px;margin-top:10px;">'
    . '<tr style="background-color:#f0f0f0;">'
    . '<th width="46%" align="left">' . $esc(t('Item')) . '</th>'
    . '<th width="12%">' . $esc(t('Qty')) . '</th>'
    . '<th width="21%" align="right">' . $esc(t('Price')) . '</th>'
    . '<th width="21%" align="right">' . $esc(t('Total')) . '</th>'
    . '</tr>';
foreach ($lines as $l) {
    $itemsHtml .= '<tr>'
        . '<td>' . $esc($l['product_name']) . '</td>'
        . '<td align="center">' . $esc($l['quantity']) . '</td>'
        . '<td align="right">' . number_format((float)$l['unit_price'], 0) . '</td>'
        . '<td align="right">' . number_format((float)$l['line_total'], 0) . '</td>'
        . '</tr>';
}
$itemsHtml .= '</table>';

$totalsRows = [[t('Subtotal'), number_format((float)$sale['subtotal'], 0)]];
if ((float)($sale['discount_amount'] ?? 0) > 0.009) {
    $totalsRows[] = [t('Discount'), '-' . number_format((float)$sale['discount_amount'], 0)];
}
$totalsRows[] = [t('Tax'), number_format((float)$sale['tax_amount'], 0)];
$totalsRows[] = [strtoupper(t('Total')), $currency . ' ' . number_format((float)$sale['grand_total'], 0)];
$totalsRows[] = [
    sprintf(t('Payment (%s)'), t(ucfirst(str_replace('_', ' ', (string)($sale['payment_method'] ?? ''))))),
    number_format((float)$sale['amount_tendered'], 0),
];
$totalsRows[] = [t('Change'), number_format((float)$sale['change_given'], 0)];

$totalsHtml = '<table cellpadding="3" cellspacing="0" width="100%" style="font-size:12px;margin-top:10px;">';
foreach ($totalsRows as [$k, $v]) {
    $totalsHtml .= '<tr><td width="70%" align="right"><strong>' . $esc($k) . ':</strong></td><td width="30%" align="right">' . $esc($v) . '</td></tr>';
}
$totalsHtml .= '</table>';

$footerExtra = trim((string)($sale['reg_receipt_footer'] ?? ''));
$footerHtml = '<p style="text-align:center;font-size:11px;margin-top:16px;">*** ' . $esc(t('THANK YOU')) . ' ***<br>'
    . $esc(t('Goods sold are not returnable'))
    . ($footerExtra !== '' ? '<br>' . nl2br($esc($footerExtra)) : '')
    . '</p>';

$receiptContent = $infoHtml . $itemsHtml . $totalsHtml . $footerHtml;

// ── Generate the PDF to a throwaway temp file, attach it, always clean up.
$tmpPath = sys_get_temp_dir() . '/pos_receipt_' . $sale_id . '_' . bin2hex(random_bytes(6)) . '.pdf';
$ok = false;
try {
    generateLetterPdf($pdo, [
        'document_code'          => (string)$sale['receipt_number'],
        'letter_date'            => (string)$sale['sale_date'],
        'use_letterhead'         => true,
        'recipient'              => $sale['customer_name'] ?? '',
        'subject'                => t('Sales Receipt'),
        'content'                => $receiptContent,
        'signature_align'        => 'left',
        'suppress_signature_box' => true,
    ], $tmpPath);

    $subject = sprintf(t('Receipt #%s — %s'), $sale['receipt_number'], $company_name);
    $shortBody = '<p>' . sprintf(t('Thank you for your purchase. Your receipt <strong>#%s</strong> for <strong>%s %s</strong> is attached as a PDF.'),
        $esc($sale['receipt_number']), $esc($currency), number_format((float)$sale['grand_total'], 0)) . '</p>'
        . '<p>' . $esc(t('Thank you for your business.')) . '</p>';

    $ok = sendEmail($email, $subject, $shortBody, ['attachments' => [$tmpPath]]);
} catch (\Throwable $e) {
    error_log('email_receipt: PDF generation failed for sale #' . $sale_id . ': ' . $e->getMessage());
    $GLOBALS['__bms_mailer_last_error'] = t('Could not generate the receipt PDF.');
} finally {
    if (is_file($tmpPath)) unlink($tmpPath);
}

if ($ok) {
    logActivity($pdo, $_SESSION['user_id'], 'Emailed POS Receipt', "Emailed receipt #{$sale['receipt_number']} to $email");
    echo json_encode(['success' => true, 'message' => sprintf(t('Receipt emailed to %s'), $email)]);
} else {
    echo json_encode(['success' => false, 'message' => sprintf(t('Could not send email: %s'), mailer_last_error() ?: t('unknown error'))]);
}
