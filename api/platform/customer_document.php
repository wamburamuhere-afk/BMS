<?php
// A customer's copy of one quotation or invoice, opened from "My Subscription" in the
// customer's own system through a short-lived signed link (customer_subscription_plan.md
// Phase 5). Platform root only; read-only; no BJP login involved — the token names the
// document AND the customer, and both must match.
// scope-audit: skip — access is the signed token (document + its customer), not a session
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/platform_link.php';
require_once __DIR__ . '/../../core/vendor_context.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$deny = function (string $msg, int $code) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; };

if (!isPlatformVendorContext()) $deny('Not found', 404);
$tok = plDocTokenRead((string)($_GET['t'] ?? ''));
if (!$tok) $deny('This link has expired. Open the document again from My Subscription.', 403);

global $pdo;
if ($tok['type'] === 'quotation') {
    $h = $pdo->prepare("SELECT order_number AS number, order_date AS date, quote_valid_until AS second_date, subtotal, tax_amount,
                               discount_amount, grand_total, currency, status, notes, customer_id, NULL AS paid_amount, NULL AS balance_due
                          FROM quotations WHERE sales_order_id = ?");
    $l = $pdo->prepare("SELECT product_name, description, quantity, unit, unit_price, tax_rate, line_total FROM quotation_items WHERE order_id = ? ORDER BY order_item_id");
} else {
    $h = $pdo->prepare("SELECT invoice_number AS number, invoice_date AS date, due_date AS second_date, subtotal, tax_amount,
                               discount_amount, grand_total, currency, status, notes, customer_id, paid_amount, balance_due
                          FROM invoices WHERE invoice_id = ?");
    $l = $pdo->prepare("SELECT product_name, description, quantity, unit, unit_price, tax_rate, line_total FROM invoice_items WHERE invoice_id = ? ORDER BY invoice_item_id");
}
$h->execute([$tok['id']]);
$doc = $h->fetch(PDO::FETCH_ASSOC);
if (!$doc || (int)$doc['customer_id'] !== $tok['customer_id']) $deny('Not found', 404);
$l->execute([$tok['id']]);
$lines = $l->fetchAll(PDO::FETCH_ASSOC);

$cs = $pdo->prepare("SELECT customer_code, customer_name, company_name, phone, email, address FROM customers WHERE customer_id = ?");
$cs->execute([$tok['customer_id']]);
$cust = $cs->fetch(PDO::FETCH_ASSOC) ?: [];

$set = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings
                       WHERE setting_key LIKE 'company_%' OR setting_key IN ('bank_name','account_name','account_number','swift_code','mpesa_paybill','mpesa_account_no')") as $r) {
    $set[$r['setting_key']] = $r['setting_value'];
}
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = fn($v) => number_format((float)$v, 2);
$isInv = $tok['type'] === 'invoice';
$title = $isInv ? 'INVOICE' : 'QUOTATION';
$cur = $doc['currency'] ?: 'TZS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?> <?= $e($doc['number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body { background:#fff; font-size:.9rem; }
    .doc { max-width: 860px; margin: 24px auto; padding: 0 16px; }
    .doc-title { color:#0d6efd; letter-spacing:2px; font-weight:700; }
    .tbl thead th { background:#0d6efd; color:#fff; font-weight:600; }
    .badge-st { background:#e7f0ff; color:#0d6efd; border:1px solid #b6ccfe; }
    @media print { .no-print { display:none !important; } .doc { margin:0; } }
</style>
</head>
<body>
<div class="doc">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 border-bottom border-primary border-3 pb-3 mb-3">
        <div>
            <div class="fs-5 fw-bold"><?= $e($set['company_name'] ?? 'BJP Technologies') ?></div>
            <div class="text-muted small">
                <?= $e($set['company_physical_address'] ?? '') ?><?= !empty($set['company_postal_address']) ? ' · ' . $e($set['company_postal_address']) : '' ?><br>
                <?= $e($set['company_phone'] ?? '') ?><?= !empty($set['company_email']) ? ' · ' . $e($set['company_email']) : '' ?>
                <?= !empty($set['company_tin']) ? '<br>TIN: ' . $e($set['company_tin']) : '' ?>
            </div>
        </div>
        <div class="text-end">
            <div class="fs-3 doc-title"><?= $title ?></div>
            <div class="fw-bold"><?= $e($doc['number']) ?></div>
            <div class="small"><?= $isInv ? 'Date' : 'Quote date' ?>: <?= $e($doc['date']) ?></div>
            <?php if ($doc['second_date']): ?><div class="small"><?= $isInv ? 'Due' : 'Valid until' ?>: <?= $e($doc['second_date']) ?></div><?php endif; ?>
            <span class="badge badge-st mt-1"><?= $e(ucfirst((string)$doc['status'])) ?></span>
        </div>
    </div>

    <div class="mb-3">
        <div class="text-muted small text-uppercase fw-bold">Bill to</div>
        <div class="fw-bold"><?= $e($cust['company_name'] ?: ($cust['customer_name'] ?? '')) ?></div>
        <div class="small text-muted"><?= $e($cust['customer_code'] ?? '') ?><?= !empty($cust['phone']) ? ' · ' . $e($cust['phone']) : '' ?><?= !empty($cust['email']) ? ' · ' . $e($cust['email']) : '' ?></div>
    </div>

    <div class="table-responsive">
    <table class="table table-bordered tbl align-middle">
        <thead><tr><th style="width:50px">S/NO</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Total (<?= $e($cur) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $ln): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><div class="fw-semibold"><?= $e($ln['product_name']) ?></div>
                    <?php if (trim((string)$ln['description']) !== ''): ?><div class="small text-muted"><?= nl2br($e($ln['description'])) ?></div><?php endif; ?></td>
                <td class="text-end"><?= rtrim(rtrim(number_format((float)$ln['quantity'], 3), '0'), '.') ?></td>
                <td class="text-end"><?= $money($ln['unit_price']) ?></td>
                <td class="text-end"><?= $money($ln['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="row justify-content-end">
        <div class="col-sm-6">
            <table class="table table-sm">
                <tr><td>Subtotal</td><td class="text-end"><?= $money($doc['subtotal']) ?></td></tr>
                <?php if ((float)$doc['discount_amount'] > 0): ?><tr><td>Discount</td><td class="text-end">-<?= $money($doc['discount_amount']) ?></td></tr><?php endif; ?>
                <tr><td>VAT</td><td class="text-end"><?= $money($doc['tax_amount']) ?></td></tr>
                <tr class="fw-bold"><td>Total (<?= $e($cur) ?>)</td><td class="text-end"><?= $money($doc['grand_total']) ?></td></tr>
                <?php if ($isInv): ?>
                <tr><td>Paid</td><td class="text-end"><?= $money($doc['paid_amount']) ?></td></tr>
                <tr class="fw-bold text-primary"><td>Balance</td><td class="text-end"><?= $money($doc['balance_due']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php if (!empty($set['bank_name']) || !empty($set['mpesa_paybill'])): ?>
    <div class="p-2 rounded mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
        <div class="fw-bold small mb-1">How to pay</div>
        <div class="small">
            <?php if (!empty($set['bank_name'])): ?>Bank: <?= $e($set['bank_name']) ?> · Account name: <?= $e($set['account_name'] ?? '') ?> · Account no: <?= $e($set['account_number'] ?? '') ?><br><?php endif; ?>
            <?php if (!empty($set['mpesa_paybill'])): ?>Mobile money paybill: <?= $e($set['mpesa_paybill']) ?><?= !empty($set['mpesa_account_no']) ? ' · Account: ' . $e($set['mpesa_account_no']) : '' ?><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (trim((string)$doc['notes']) !== ''): ?><div class="small text-muted mb-3"><?= nl2br($e($doc['notes'])) ?></div><?php endif; ?>

    <div class="no-print text-center mb-4">
        <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    </div>
</div>
</body>
</html>
