<?php
// File: app/bms/sales/quotations/print_quotation.php
// Dedicated quotation print-out — reads the `quotations` table.
// Includes the company Bank / Account details (configured in company_profile.php)
// and a Created / Reviewed / Approved-By footer driven by the approval workflow.
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';

if (!isAuthenticated()) die("Unauthorized");

$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($quotation_id <= 0) die("Invalid Quotation ID");

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
        ($_SESSION['username'] ?? 'User') . " printed Quotation #{$order['order_number']}");

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

$doc_title  = 'QUOTATION';
$doc_label  = 'Quote #:';
$date_label = 'Quote Date:';
$currency   = $order['currency'] ?? 'TZS';

$order['subtotal']        = $order['subtotal']        ?? $order['total_amount'] ?? 0;
$order['tax_amount']      = $order['tax_amount']      ?? 0;
$order['discount_amount'] = $order['discount_amount'] ?? 0;
$order['shipping_cost']   = $order['shipping_cost']   ?? 0;
$order['grand_total']     = $order['grand_total']     ?? 0;

// Company Settings
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

// Bank / Account Settings (configured in company_profile.php, stored in system_settings)
$bank = ['bank_name'=>'','account_name'=>'','account_number'=>'','swift_code'=>'','check_payable_to'=>'','mpesa_paybill'=>'','mpesa_account_no'=>''];
try {
    $stmtB = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bank_name','account_name','account_number','swift_code','check_payable_to','mpesa_paybill','mpesa_account_no')");
    $stmtB->execute();
    while ($r = $stmtB->fetch(PDO::FETCH_ASSOC)) {
        $bank[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$has_bank = !empty($bank['bank_name']) || !empty($bank['mpesa_paybill']) || !empty($bank['check_payable_to']);

// Workflow signatories
$creator_name  = trim($order['creator_name']  ?? '');
$creator_role  = trim($order['creator_role']  ?? '');
$reviewer_name = trim($order['reviewer_name'] ?? '');
$reviewer_role = trim($order['reviewer_role'] ?? '');
$approver_name = trim($order['approver_name'] ?? '');
$approver_role = trim($order['approver_role'] ?? '');

$creator_label  = $creator_name  ? $creator_name  . ($creator_role  ? ' (' . ucfirst($creator_role)  . ')' : '') : 'Unknown';
$reviewer_label = $reviewer_name ? $reviewer_name . ($reviewer_role ? ' (' . ucfirst($reviewer_role) . ')' : '') : 'Not yet reviewed';
$approver_label = $approver_name ? $approver_name . ($approver_role ? ' (' . ucfirst($approver_role) . ')' : '') : 'Not yet approved';

// ── Customer address — de-duplicate postal_address vs address ──
// Users often type the same value into both the address and the postal
// address fields, which printed the location twice. Drop the postal line
// when it is already contained in the (fuller) address line, and prefix
// "P.O. Box" only when the value isn't already marked as a P.O. Box.
$cust_postal  = trim($order['c_postal_address'] ?? '');
$cust_address = trim($order['c_address'] ?? '');

if ($cust_postal !== '' && $cust_address !== ''
    && stripos($cust_address, $cust_postal) !== false) {
    $cust_postal = '';
}

$addr_lines = [];
if ($cust_postal !== '') {
    $addr_lines[] = preg_match('/^\s*p\.?\s*o\.?\s*box/i', $cust_postal)
        ? $cust_postal
        : 'P.O. Box ' . $cust_postal;
}
if ($cust_address !== '') {
    $addr_lines[] = $cust_address;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $doc_title ?> #<?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 20px 20px 0 20px;
            background: #fff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-info { flex: 1; padding-right: 20px; }
        .company-info h1 {
            color: #0d6efd;
            font-size: 22px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0 0 10px 0;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .company-addr-row { display: flex; align-items: flex-start; gap: 14px; }
        .company-addr-row img { max-height: 60px; width: auto; flex-shrink: 0; object-fit: contain; }
        .company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
        .doc-title-box {
            text-align: right;
            background: #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 220px;
        }
        .doc-title-box h2 {
            margin: 0 0 10px 0;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .doc-title-box p {
            margin: 4px 0;
            font-size: 12px;
            color: #fff;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .doc-title-box strong { font-weight: 600; }
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
        .box {
            width: 48%;
            background: #f4f6f8;
            padding: 14px 16px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box h3 {
            font-size: 11px;
            color: #1a252f;
            padding-bottom: 7px;
            margin-bottom: 10px;
            border-bottom: 1.5px solid #3498db;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .box p { margin: 5px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th {
            background: #34495e;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 9px 10px;
            text-align: left;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) {
            background: #f9fafb;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td {
            height: 0.9cm;
            padding: 2px 10px;
            vertical-align: middle;
            font-size: 13px;
            line-height: 2.2;
            color: #1a252f;
        }
        .text-right  { text-align: right;  }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700;   }

        /* ── TOTALS SECTION (bank details left / amounts right) ── */
        .totals-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }
        .bank-details {
            flex: 1;
            background: #f4f6f8;
            padding: 14px 16px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .bank-details h3 {
            font-size: 11px;
            color: #1a252f;
            padding-bottom: 7px;
            margin-bottom: 10px;
            border-bottom: 1.5px solid #3498db;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .bank-details p { margin: 3px 0; color: #1a252f; font-size: 11px; }
        .bank-details strong { color: #1a252f; font-weight: 700; font-size: 11px; display: block; margin-bottom: 2px; }
        .bank-section { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e4e8ec; }
        .bank-section:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }

        .totals {
            width: 310px;
            min-width: 260px;
            flex-shrink: 0;
            background: #f4f6f8;
            padding: 14px 18px;
            border-radius: 6px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
            color: #1a252f;
            border-bottom: 1px solid #e4e8ec;
        }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total {
            border-top: 2px solid #3498db;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #1a252f;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div {
            background: #f4f6f8;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 3px solid #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .notes-section strong {
            color: #1a252f;
            display: block;
            margin-bottom: 5px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .notes-section p { color: #1a252f; font-size: 11px; }

        /* ── SIGNATURE ── */
        .signature-box {
            margin-top: 46px;
            display: flex;
            justify-content: space-around;
            gap: 40px;
        }
        .signature-line {
            width: 210px;
            padding-top: 7px;
            text-align: center;
            border-top: 1.5px solid #1a252f;
            font-size: 11px;
            color: #1a252f;
            font-weight: 600;
        }
        .signature-line small {
            display: block;
            margin-top: 4px;
            font-size: 10px;
            font-weight: 400;
            color: #495057;
        }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box, .totals, .bank-details, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">Print Document</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer; font-weight:600; background:#fff; border:1px solid #dee2e6; border-radius:4px;">Close Tab</button>
    </div>

    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars(getUrl($comp['logo'])) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?>
                    <p><?= htmlspecialchars($comp['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?>
                    <p>P.O. Box <?= htmlspecialchars($comp['postal_address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['phone'])): ?>
                    <p>Phone: <?= htmlspecialchars($comp['phone']) ?></p>
                    <?php endif; ?>
                    <?php
                    $we = [];
                    if (!empty($comp['website'])) $we[] = 'Web: '   . htmlspecialchars($comp['website']);
                    if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                    if ($we): ?>
                    <p><?= implode(' | ', $we) ?></p>
                    <?php endif; ?>
                    <?php
                    $tv = [];
                    if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                    if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                    if ($tv): ?>
                    <p><?= implode(' | ', $tv) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="doc-title-box">
            <h2><?= $doc_title ?></h2>
            <p><strong><?= $doc_label ?></strong> <?= htmlspecialchars($order['order_number']) ?></p>
            <p><strong><?= $date_label ?></strong> <?= date('d M Y', strtotime($order['order_date'])) ?></p>
            <?php if (!empty($order['quote_valid_until'])): ?>
            <p><strong>Valid Until:</strong> <?= date('d M Y', strtotime($order['quote_valid_until'])) ?></p>
            <?php endif; ?>
            <p><strong>Status:</strong> <?= strtoupper($order['status']) ?></p>
        </div>
    </div>

    <!-- CUSTOMER + QUOTATION INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Customer</h3>
            <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
            <?php if (!empty($order['company_name'])): ?>
            <p><?= htmlspecialchars($order['company_name']) ?></p>
            <?php endif; ?>
            <?php foreach ($addr_lines as $addr_line): ?>
            <p><?= htmlspecialchars($addr_line) ?></p>
            <?php endforeach; ?>
            <?php if (!empty($order['c_phone'])): ?>
            <p><?= htmlspecialchars($order['c_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($order['c_email'])): ?>
            <p><?= htmlspecialchars($order['c_email']) ?></p>
            <?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($order['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($order['c_tin']);
            if (!empty($order['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($order['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Quotation Information</h3>
            <?php if (!empty($order['project_contract_no'])): ?>
            <p><strong>Contract No:</strong> <?= htmlspecialchars($order['project_contract_no']) ?></p>
            <?php endif; ?>
            <?php if (!empty($order['project_name'])): ?>
            <p><strong>Project:</strong> <?= htmlspecialchars($order['project_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($order['warehouse_name'])): ?>
            <p><strong>Warehouse:</strong> <?= htmlspecialchars($order['warehouse_name']) ?></p>
            <?php endif; ?>
            <p><strong>Salesperson:</strong> <?= htmlspecialchars($order['salesperson_name'] ?? 'N/A') ?></p>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($creator_label) ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th class="text-center">Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
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

    <!-- TOTALS + ACCOUNT DETAILS -->
    <div class="totals-section">

        <!-- Account / Bank Details (left) -->
        <?php if ($has_bank): ?>
        <div class="bank-details">
            <h3>Account Details</h3>
            <?php if (!empty($bank['bank_name'])): ?>
            <div class="bank-section">
                <strong>Bank Transfer</strong>
                <p>Bank: <?= htmlspecialchars($bank['bank_name']) ?></p>
                <?php if (!empty($bank['account_name'])): ?>
                <p>Account Name: <?= htmlspecialchars($bank['account_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($bank['account_number'])): ?>
                <p>Account No: <?= htmlspecialchars($bank['account_number']) ?></p>
                <?php endif; ?>
                <?php if (!empty($bank['swift_code'])): ?>
                <p>SWIFT / BIC: <?= htmlspecialchars($bank['swift_code']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['mpesa_paybill'])): ?>
            <div class="bank-section">
                <strong>Mobile Money</strong>
                <p>Paybill: <?= htmlspecialchars($bank['mpesa_paybill']) ?></p>
                <?php if (!empty($bank['mpesa_account_no'])): ?>
                <p>Account: <?= htmlspecialchars($bank['mpesa_account_no']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bank['check_payable_to'])): ?>
            <div class="bank-section">
                <strong>Cheque</strong>
                <p>Payable to: <?= htmlspecialchars($bank['check_payable_to']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="flex:1;"></div>
        <?php endif; ?>

        <!-- Amounts (right) -->
        <div class="totals">
            <div class="totals-row">
                <span>Subtotal:</span>
                <span><?= $currency ?> <?= number_format($order['subtotal'], 2) ?></span>
            </div>
            <?php if (floatval($order['discount_amount']) > 0): ?>
            <div class="totals-row">
                <span>Discount:</span>
                <span>-<?= $currency ?> <?= number_format($order['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if (floatval($order['tax_amount']) > 0): ?>
            <div class="totals-row">
                <span>Tax:</span>
                <span><?= $currency ?> <?= number_format($order['tax_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if (floatval($order['shipping_cost']) > 0): ?>
            <div class="totals-row">
                <span>Shipping:</span>
                <span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="totals-row grand-total">
                <span>GRAND TOTAL:</span>
                <span><?= $currency ?> <?= number_format($order['grand_total'], 2) ?></span>
            </div>
        </div>

    </div>

    <!-- NOTES + TERMS -->
    <div class="notes-section">
        <?php if (!empty($order['notes'])): ?>
        <div>
            <strong>Notes:</strong>
            <p><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($order['terms_conditions'])): ?>
        <div>
            <strong>Terms &amp; Conditions:</strong>
            <p><?= nl2br(htmlspecialchars($order['terms_conditions'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- SIGNATURE — workflow signatories -->
    <div class="signature-box">
        <div class="signature-line">
            Created By<br>
            <small><?= htmlspecialchars($creator_label) ?></small>
        </div>
        <div class="signature-line">
            Reviewed By<br>
            <small><?= htmlspecialchars($reviewer_label) ?></small>
        </div>
        <div class="signature-line">
            Approved By<br>
            <small><?= htmlspecialchars($approver_label) ?></small>
        </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
