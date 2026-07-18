<?php
// File: app/bms/sales/quotations/print_quotation_noir.php
// "Noir" template — solid black minimalist header, inspired by a black
// minimal business quotation design. Same data source and fields as
// print_quotation.php; presentation only differs.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/workflow.php';
require_once __DIR__ . '/../../../../core/permissions.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('sales_orders')) die("Access Denied");

$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($quotation_id <= 0) die("Invalid Quotation ID");

assertScopeForRecordHtml('quotations', 'sales_order_id', $quotation_id);

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT q.*,
            c.customer_name,
            c.company_name,
            COALESCE(NULLIF(TRIM(c.company_email), ''), c.email) as c_email,
            c.phone          as c_phone,
            c.address        as c_address,
            c.postal_address as c_postal_address,
            c.tax_id         as c_tin,
            c.vat_number     as c_vrn,
            u.username       as salesperson_name,
            pr.project_name,
            pr.contract_number as project_contract_no,
            w.warehouse_name,
            TRIM(CONCAT(COALESCE(uc.first_name,''),' ',COALESCE(uc.last_name,''))) AS creator_name,
            uc.username AS creator_username,
            COALESCE(uc.user_role, uc.role) AS creator_role,
            TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
            COALESCE(ur.user_role, ur.role) AS reviewer_role,
            TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
            COALESCE(ua.user_role, ua.role) AS approver_role
        FROM quotations q
        LEFT JOIN customers c          ON q.customer_id    = c.customer_id
        LEFT JOIN users u              ON q.salesperson_id = u.user_id
        LEFT JOIN projects pr          ON q.project_id     = pr.project_id
        LEFT JOIN warehouses w         ON q.warehouse_id   = w.warehouse_id
        LEFT JOIN users uc             ON q.created_by     = uc.user_id
        LEFT JOIN users ur             ON q.reviewed_by    = ur.user_id
        LEFT JOIN users ua             ON q.approved_by    = ua.user_id
        WHERE q.sales_order_id = ?
    ");
    $stmt->execute([$quotation_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) die("Quotation not found");

    logActivity($pdo, $_SESSION['user_id'], 'Print Quotation',
        ($_SESSION['username'] ?? 'User') . " printed Quotation #{$order['order_number']} (Noir template)");

    $stmtItems = $pdo->prepare("
        SELECT qi.*, p.product_name, p.sku, p.unit
        FROM quotation_items qi
        LEFT JOIN products p ON qi.product_id = p.product_id
        WHERE qi.order_id = ?
    ");
    $stmtItems->execute([$quotation_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $order['currency'] ?? 'TZS';

$order['subtotal']        = $order['subtotal']        ?? $order['total_amount'] ?? 0;
$order['tax_amount']      = $order['tax_amount']      ?? 0;
$order['discount_amount'] = $order['discount_amount'] ?? 0;
$order['shipping_cost']   = $order['shipping_cost']   ?? 0;
$order['grand_total']     = $order['grand_total']     ?? 0;

$comp = ['name'=>'Business Management System','email'=>'','phone'=>'','address'=>'','postal_address'=>'','website'=>'','tin'=>'','vrn'=>'','logo'=>''];
try {
    $stmtC = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'company_%'");
    $stmtC->execute();
    while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $k = str_replace('company_', '', $r['setting_key']);
        if ($k === 'physical_address') $comp['address'] = $r['setting_value'];
        elseif ($k === 'logo')         $comp['logo']    = $r['setting_value'];
        else                           $comp[$k]        = $r['setting_value'];
    }
} catch (Exception $e) {}

$bank = ['bank_name'=>'','account_name'=>'','account_number'=>'','swift_code'=>'','check_payable_to'=>'','mpesa_paybill'=>'','mpesa_account_no'=>''];
try {
    $stmtB = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bank_name','account_name','account_number','swift_code','check_payable_to','mpesa_paybill','mpesa_account_no')");
    $stmtB->execute();
    while ($r = $stmtB->fetch(PDO::FETCH_ASSOC)) {
        $bank[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$has_bank = !empty($bank['bank_name']) || !empty($bank['mpesa_paybill']) || !empty($bank['check_payable_to']);

$creator_name  = trim($order['creator_name']  ?? '');
if ($creator_name === '') $creator_name = trim($order['creator_username'] ?? '');
$creator_role  = trim($order['creator_role']  ?? '');
$reviewer_name = trim($order['reviewer_name'] ?? '');
$reviewer_role = trim($order['reviewer_role'] ?? '');
$approver_name = trim($order['approver_name'] ?? '');
$approver_role = trim($order['approver_role'] ?? '');
$creator_label = $creator_name ? $creator_name . ($creator_role ? ' (' . ucfirst($creator_role) . ')' : '') : 'Unknown';

$quote_id_for_sig = $order['sales_order_id'] ?? 0;
$wf_sigs = $quote_id_for_sig ? getWorkflowSignatures($pdo, 'quotation', $quote_id_for_sig) : [];
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $creator_role,
    'reviewed_by_name'   => $reviewer_name,
    'reviewed_by_role'   => $reviewer_role,
    'approved_by_name'   => $approver_name,
    'approved_by_role'   => $approver_role,
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => true,
];

$cust_postal  = trim($order['c_postal_address'] ?? '');
$cust_address = trim($order['c_address'] ?? '');
if ($cust_postal !== '' && $cust_address !== '' && stripos($cust_address, $cust_postal) !== false) {
    $cust_postal = '';
}
$addr_lines = [];
if ($cust_postal !== '') {
    $addr_lines[] = preg_match('/^\s*p\.?\s*o\.?\s*box/i', $cust_postal) ? $cust_postal : 'P.O. Box ' . $cust_postal;
}
if ($cust_address !== '') {
    $addr_lines[] = $cust_address;
}

$accent = getSetting('print_template_color_qt_noir', '#111111');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QUOTATION #<?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #171717;
            line-height: 1.5;
            padding: 0 22px 0 22px;
            background: #fff;
        }

        .header-band {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            margin: 0 -22px 20px -22px;
            padding: 26px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-band .company-block { display: flex; align-items: center; gap: 12px; }
        .header-band img { max-height: 44px; width: auto; background: #fff; padding: 3px; border-radius: 3px; }
        .header-band h1 { font-size: 16px; font-weight: 700; letter-spacing: 0.5px; }
        .header-band .sub { font-size: 9.5px; opacity: 0.75; margin-top: 3px; }
        .header-band .title-block { text-align: right; }
        .header-band .title-block h2 { font-size: 24px; font-weight: 800; letter-spacing: 3px; }
        .header-band .title-block p { font-size: 10.5px; opacity: 0.85; margin-top: 3px; }

        .panel-row { display: flex; gap: 14px; margin-bottom: 18px; }
        .panel { flex: 1; border: 1px solid #171717; padding: 12px 14px; }
        .panel h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #171717; }
        .panel p { font-size: 11px; margin: 3px 0; }
        .panel strong { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th {
            background: var(--accent);
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
            color: #fff;
            font-weight: 600;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 8px 10px;
            text-align: left;
        }
        tbody tr td { padding: 6px 10px; font-size: 11.5px; border-bottom: 1px solid #e2e2e2; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .totals-section { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .bank-details { flex: 1; border: 1px solid #171717; padding: 12px 14px; }
        .bank-details h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #171717; }
        .bank-details p { font-size: 10.5px; margin: 2px 0; }
        .bank-details strong { display: block; font-size: 10.5px; margin-bottom: 2px; }
        .bank-section { margin-bottom: 8px; }
        .bank-section:last-child { margin-bottom: 0; }

        .totals { width: 300px; background: var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; color: #fff; padding: 14px 18px; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11.5px; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .totals-row.grand-total { border-top: 2px solid #fff; border-bottom: none; margin-top: 6px; padding-top: 8px; font-size: 14px; font-weight: 700; }

        .notes-section > div { border-left: 3px solid var(--accent); padding: 10px 14px; margin-bottom: 10px; background: #f7f7f7; }
        .notes-section strong { display: block; margin-bottom: 4px; font-size: 11px; }
        .notes-section p { font-size: 11px; }

        @page { margin: 0mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
    <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
</head>
<body onload="bmsAutoFitPrint()">

    <div class="no-print" style="margin:16px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>


    <div class="print-scale-wrapper">
    <div class="header-band">
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars(getUrl($comp['logo'])) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <div class="sub">
                    <?php
                    $parts = [];
                    if (!empty($comp['address'])) $parts[] = htmlspecialchars($comp['address']);
                    if (!empty($comp['phone'])) $parts[] = 'Tel: ' . htmlspecialchars($comp['phone']);
                    if (!empty($comp['email'])) $parts[] = htmlspecialchars($comp['email']);
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv) $parts[] = implode(' | ', $tv);
                    echo implode(' &bull; ', $parts);
                    ?>
                </div>
            </div>
        </div>
        <div class="title-block">
            <h2>QUOTATION</h2>
            <p>Quote #: <?= htmlspecialchars($order['order_number']) ?></p>
            <p>Quote Date: <?= date('d M Y', strtotime($order['order_date'])) ?></p>
            <?php if (!empty($order['quote_valid_until'])): ?>
            <p>Valid Until: <?= date('d M Y', strtotime($order['quote_valid_until'])) ?></p>
            <?php endif; ?>
            <p>Status: <?= strtoupper($order['status']) ?></p>
        </div>
    </div>

    <div class="panel-row">
        <div class="panel">
            <h3>Customer</h3>
            <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
            <?php foreach ($addr_lines as $addr_line): ?>
            <p><?= htmlspecialchars($addr_line) ?></p>
            <?php endforeach; ?>
            <?php if (!empty($order['c_phone'])): ?><p><?= htmlspecialchars($order['c_phone']) ?></p><?php endif; ?>
            <?php if (!empty($order['c_email'])): ?><p><?= htmlspecialchars($order['c_email']) ?></p><?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($order['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($order['c_tin']);
            if (!empty($order['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($order['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="panel">
            <h3>Quotation Information</h3>
            <?php if (!empty($order['project_contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($order['project_contract_no']) ?></p><?php endif; ?>
            <?php if (!empty($order['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($order['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($order['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($order['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Salesperson:</strong> <?= htmlspecialchars($order['salesperson_name'] ?? 'N/A') ?></p>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_label) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:34px;">S/NO</th>
                <th class="text-center" style="width:90px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:70px;">Qty</th>
                <th class="text-right" style="width:95px;">Unit Price</th>
                <th class="text-right" style="width:105px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item):
                $lineTotal = floatval($item['quantity']) * floatval($item['unit_price']);
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? $item['item_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-section">
        <?php if ($has_bank): ?>
        <div class="bank-details">
            <h3>Account Details</h3>
            <?php if (!empty($bank['bank_name'])): ?>
            <div class="bank-section">
                <strong>Bank Transfer</strong>
                <p>Bank: <?= htmlspecialchars($bank['bank_name']) ?></p>
                <?php if (!empty($bank['account_name'])): ?><p>Account Name: <?= htmlspecialchars($bank['account_name']) ?></p><?php endif; ?>
                <?php if (!empty($bank['account_number'])): ?><p>Account No: <?= htmlspecialchars($bank['account_number']) ?></p><?php endif; ?>
                <?php if (!empty($bank['swift_code'])): ?><p>SWIFT / BIC: <?= htmlspecialchars($bank['swift_code']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['mpesa_paybill'])): ?>
            <div class="bank-section">
                <strong>Mobile Money</strong>
                <p>Paybill: <?= htmlspecialchars($bank['mpesa_paybill']) ?></p>
                <?php if (!empty($bank['mpesa_account_no'])): ?><p>Account: <?= htmlspecialchars($bank['mpesa_account_no']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['check_payable_to'])): ?>
            <div class="bank-section"><strong>Cheque</strong><p>Payable to: <?= htmlspecialchars($bank['check_payable_to']) ?></p></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <div class="totals">
            <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($order['subtotal'], 2) ?></span></div>
            <?php if (floatval($order['discount_amount']) > 0): ?>
            <div class="totals-row"><span>Discount:</span><span>-<?= $currency ?> <?= number_format($order['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($order['tax_amount'], 2) ?></span></div>
            <?php if (floatval($order['shipping_cost']) > 0): ?>
            <div class="totals-row"><span>Shipping:</span><span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row grand-total"><span>GRAND TOTAL:</span><span><?= $currency ?> <?= number_format($order['grand_total'], 2) ?></span></div>
        </div>
    </div>

    <div class="notes-section">
        <?php if (!empty($order['notes'])): ?>
        <div><strong>Notes:</strong><p><?= nl2br(htmlspecialchars($order['notes'])) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($order['terms_conditions'])): ?>
        <div><strong>Terms &amp; Conditions:</strong><p><?= nl2br(htmlspecialchars($order['terms_conditions'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
