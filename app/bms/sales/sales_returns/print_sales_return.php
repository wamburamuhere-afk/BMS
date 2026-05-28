<?php
// File: app/bms/sales/sales_returns/print_sales_return.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';
require_once __DIR__ . '/../../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");

// Phase 5a — print pages get a canView gate (admin auto-bypass)
if (!canView('sales_returns')) die("Access Denied");

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($return_id <= 0) die("Invalid Return ID");

global $pdo;

// Phase C — sales_returns has no direct project_id; resolve via invoice/SO.
if ($return_id > 0) {
    $_p = $pdo->prepare("
        SELECT COALESCE(i.project_id, so.project_id) AS pid
        FROM sales_returns sr
        LEFT JOIN invoices i      ON sr.invoice_id      = i.invoice_id
        LEFT JOIN sales_orders so ON sr.sales_order_id  = so.sales_order_id
        WHERE sr.sales_return_id = ? LIMIT 1
    ");
    $_p->execute([$return_id]);
    $_pid = $_p->fetchColumn();
    if ($_pid && !userCan('project', (int)$_pid)) {
        http_response_code(403);
        die('Access denied: this return belongs to a project not in your scope.');
    }
}

try {
    // Fetch return details
    $stmt = $pdo->prepare("
        SELECT 
            sr.sales_return_id,
            sr.return_number,
            sr.return_date,
            sr.total_amount,
            sr.reason,
            sr.status,
            so.currency,
            so.order_number,
            c.customer_name,
            c.company_name,
            c.email as c_email,
            c.phone as c_phone,
            c.address as c_address,
            c.postal_address as c_postal_address,
            c.tax_id as c_tin,
            c.vat_number as c_vrn,
            u.first_name as creator_first,
            u.last_name as creator_last,
            u.username as creator_username,
            COALESCE(u.user_role,  u.role)                                          AS creator_role,
            TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
            COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
            TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
            COALESCE(ua.user_role, ua.role)                                        AS approver_role
        FROM sales_returns sr
        LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        LEFT JOIN users u  ON sr.created_by  = u.user_id
        LEFT JOIN users ur ON sr.reviewed_by = ur.user_id
        LEFT JOIN users ua ON sr.approved_by = ua.user_id
        WHERE sr.sales_return_id = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return) die("Return not found");

    // Log Activity
    $action = "Print Sales Return";
    $user_name = $_SESSION['username'] ?? 'User';
    $description = "$user_name printed Sales Return #{$return['return_number']}";
    require_once __DIR__ . '/../../../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], $action, $description);

    // Fetch items
    $stmtItems = $pdo->prepare("
        SELECT sri.*, p.product_name, p.sku, p.unit
        FROM sales_return_items sri
        LEFT JOIN products p ON sri.product_id = p.product_id
        WHERE sri.sales_return_id = ?
    ");
    $stmtItems->execute([$return_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$doc_title   = 'SALES RETURN';
$doc_label   = 'Return #:';
$date_label  = 'Return Date:';
$currency    = $return['currency'] ?? 'TZS';

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

// ── Three-approval signature data (Created / Reviewed / Approved By) ──
$creator_name = trim(($return['creator_first'] ?? '') . ' ' . ($return['creator_last'] ?? ''))
                ?: ($return['creator_username'] ?? '');
$wf_sigs = getWorkflowSignatures($pdo, 'sales_return', $return_id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => trim($return['creator_role']  ?? ''),
    'reviewed_by_name'   => trim($return['reviewer_name'] ?? ''),
    'reviewed_by_role'   => trim($return['reviewer_role'] ?? ''),
    'approved_by_name'   => trim($return['approver_name'] ?? ''),
    'approved_by_role'   => trim($return['approver_role'] ?? ''),
    'created_sig_path'   => $wf_sigs['created']['sig_path']   ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']  ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']  ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at'] ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']  ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at'] ?? null,
    '__include_css'      => false,
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $doc_title ?> #<?= htmlspecialchars($return['return_number']) ?></title>
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

        /* ── HEADER ── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 3px solid #3498db; /* Blue to match Sales Order */
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
        .company-addr-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .company-addr-row img {
            max-height: 60px;
            width: auto;
            flex-shrink: 0;
            object-fit: contain;
        }
        .company-addr-info p {
            margin: 2px 0;
            color: #1a252f;
            font-size: 11px;
            font-weight: 500;
        }

        /* ── TITLE BOX ── */
        .doc-title-box {
            text-align: right;
            background: #3498db; /* Blue to match Sales Order */
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

        /* ── INFO BOXES ── */
        .details-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 14px;
        }
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
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }

        /* ── ITEMS TABLE ── */
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
        tbody tr {
            border-bottom: 1px solid #e4e8ec;
        }
        tbody tr:nth-child(even) {
            background: #f9fafb;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td {
            height: 0.75cm;
            padding: 2px 10px;
            vertical-align: middle;
            font-size: 13px;
            line-height: 1.6;
            color: #1a252f;
        }
        .text-right  { text-align: right;  }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700;   }

        /* ── TOTALS ── */
        .totals {
            float: right;
            width: 310px;
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

        /* ── NOTES ── */
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
            font-size: 11px;
            color: #1a252f;
            font-weight: 600;
        }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box, .totals, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; }
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
                <img src="<?= htmlspecialchars('../../../../' . $comp['logo']) ?>" alt="Logo">
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
            <p><strong><?= $doc_label ?></strong> <?= htmlspecialchars($return['return_number']) ?></p>
            <p><strong><?= $date_label ?></strong> <?= date('d M Y', strtotime($return['return_date'])) ?></p>
            <p><strong>Ref Order:</strong> <?= htmlspecialchars($return['order_number'] ?? 'N/A') ?></p>
            <p><strong>Status:</strong> <?= strtoupper($return['status']) ?></p>
        </div>
    </div>

    <!-- CUSTOMER + RETURN INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Credit To</h3>
            <p><strong><?= htmlspecialchars($return['customer_name']) ?></strong></p>
            <?php if (!empty($return['company_name'])): ?>
            <p><?= htmlspecialchars($return['company_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($return['c_postal_address'])): ?>
            <p>P.O. Box <?= htmlspecialchars($return['c_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($return['c_address'])): ?>
            <p><?= htmlspecialchars($return['c_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($return['c_phone'])): ?>
            <p><?= htmlspecialchars($return['c_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($return['c_email'])): ?>
            <p><?= htmlspecialchars($return['c_email']) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Return Information</h3>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars(trim(($return['creator_first'] ?? '') . ' ' . ($return['creator_last'] ?? '')) ?: $return['creator_username'] ?: 'System') ?></p>
            <p><strong>Currency:</strong> <?= htmlspecialchars($currency) ?></p>
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
                <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($item['total_amount'] ?? $lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row grand-total">
            <span>TOTAL REFUND:</span>
            <span><?= $currency ?> <?= number_format($return['total_amount'], 2) ?></span>
        </div>
    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($return['reason'])): ?>
        <div>
            <strong>Reason for Return:</strong>
            <p><?= nl2br(htmlspecialchars($return['reason'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- SIGNATURE -->
    <?php include __DIR__ . '/../../../../includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
