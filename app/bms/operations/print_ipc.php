<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';

if (!isAuthenticated()) die("Unauthorized");

$ipc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ipc_id <= 0) die("Invalid IPC ID");

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT ipc.*,
            p.project_name, p.contract_number,
            c.customer_name, c.company_name,
            c.email AS c_email, c.phone AS c_phone,
            c.address AS c_address, c.postal_address AS c_postal_address,
            c.tax_id AS c_tin, c.vat_number AS c_vrn,
            i.invoice_number,
            so.order_number,
            u.first_name AS creator_first, u.last_name AS creator_last, COALESCE(u.user_role, u.role) AS creator_role,
            ur.first_name AS reviewer_first, ur.last_name AS reviewer_last, COALESCE(ur.user_role, ur.role) AS reviewer_role,
            ua.first_name AS approver_first, ua.last_name AS approver_last, COALESCE(ua.user_role, ua.role) AS approver_role
        FROM interim_payment_certificates ipc
        LEFT JOIN projects p ON ipc.project_id = p.project_id
        LEFT JOIN customers c ON p.customer_id = c.customer_id
        LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
        LEFT JOIN sales_orders so ON ipc.sales_order_id = so.sales_order_id
        LEFT JOIN users u  ON ipc.created_by   = u.user_id
        LEFT JOIN users ur ON ipc.reviewed_by  = ur.user_id
        LEFT JOIN users ua ON ipc.approved_by  = ua.user_id
        WHERE ipc.ipc_id = ?
    ");
    $stmt->execute([$ipc_id]);
    $ipc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ipc) die("IPC not found");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Company settings
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

// Parse items
$items = [];
try { $items = json_decode($ipc['items_json'] ?? '[]', true) ?: []; } catch (Exception $e) {}

// Compute totals
$subtotal = 0; $tax_sum = 0;
foreach ($items as $item) {
    $subtotal += floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
    $tax_sum  += floatval($item['tax_amount'] ?? 0);
}

$net_payable = floatval($ipc['net_payable'] ?? 0);
$fmt = fn($n) => 'TZS ' . number_format(floatval($n), 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IPC <?= htmlspecialchars($ipc['ipc_number'] ?? $ipc_id) ?></title>
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

        /* HEADER */
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

        /* TITLE BOX */
        .doc-title-box {
            text-align: right;
            background: #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 240px;
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
        .doc-title-box p { margin: 4px 0; font-size: 12px; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .doc-title-box strong { font-weight: 600; }

        /* INFO BOXES */
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
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }

        /* ITEMS TABLE */
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
        tbody tr:nth-child(even) { background: #f9fafb; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid #3498db; }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right  { text-align: right;  }
        .text-center { text-align: center; }
        .fw-bold     { font-weight: 700;   }

        /* TOTALS */
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

        /* NOTES */
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
        .notes-section strong { color: #1a252f; display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; }
        .notes-section p { color: #1a252f; font-size: 11px; }

        /* SIGNATURE */
        .signature-box { margin-top: 46px; display: flex; justify-content: space-around; gap: 40px; }
        .signature-line {
            width: 210px;
            padding-top: 7px;
            text-align: center;
            border-top: 1.5px solid #1a252f;
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
                <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
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
            <h2>Interim Payment Certificate</h2>
            <p><strong>IPC No:</strong> <?= htmlspecialchars($ipc['ipc_number'] ?? '—') ?></p>
            <p><strong>Date:</strong> <?= $ipc['ipc_date'] ? date('d M Y', strtotime($ipc['ipc_date'])) : '—' ?></p>
            <?php if (!empty($ipc['period_from']) || !empty($ipc['period_to'])): ?>
            <p><strong>Period:</strong> <?= $ipc['period_from'] ? date('d M Y', strtotime($ipc['period_from'])) : '—' ?> &mdash; <?= $ipc['period_to'] ? date('d M Y', strtotime($ipc['period_to'])) : '—' ?></p>
            <?php endif; ?>
            <p><strong>Status:</strong> <?= strtoupper($ipc['status'] ?? 'DRAFT') ?></p>
        </div>
    </div>

    <!-- CLIENT + PROJECT INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Client</h3>
            <p><strong><?= htmlspecialchars($ipc['customer_name'] ?? '—') ?></strong></p>
            <?php if (!empty($ipc['company_name'])): ?>
            <p><?= htmlspecialchars($ipc['company_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['c_postal_address'])): ?>
            <p>P.O. Box <?= htmlspecialchars($ipc['c_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['c_address'])): ?>
            <p><?= htmlspecialchars($ipc['c_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['c_phone'])): ?>
            <p><?= htmlspecialchars($ipc['c_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['c_email'])): ?>
            <p><?= htmlspecialchars($ipc['c_email']) ?></p>
            <?php endif; ?>
            <?php
            $c_tv = [];
            if (!empty($ipc['c_tin'])) $c_tv[] = 'TIN: ' . htmlspecialchars($ipc['c_tin']);
            if (!empty($ipc['c_vrn'])) $c_tv[] = 'VRN: ' . htmlspecialchars($ipc['c_vrn']);
            if ($c_tv): ?>
            <p><?= implode(' | ', $c_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Project Information</h3>
            <p><strong>Project:</strong> <?= htmlspecialchars($ipc['project_name'] ?? '—') ?></p>
            <?php if (!empty($ipc['contract_number'])): ?>
            <p><strong>Contract No:</strong> <?= htmlspecialchars($ipc['contract_number']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['order_number'])): ?>
            <p><strong>Sales Order:</strong> <?= htmlspecialchars($ipc['order_number']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ipc['invoice_number'])): ?>
            <p><strong>Invoice:</strong> <?= htmlspecialchars($ipc['invoice_number']) ?></p>
            <?php endif; ?>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars(trim(($ipc['creator_first'] ?? '') . ' ' . ($ipc['creator_last'] ?? '')) ?: 'System') ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th>Item / Description</th>
                <th class="text-center" style="width:70px;">Qty</th>
                <th class="text-center" style="width:60px;">Unit</th>
                <th class="text-right" style="width:120px;">Unit Price</th>
                <th class="text-center" style="width:65px;">Tax %</th>
                <th class="text-right" style="width:130px;">Total (TZS)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="7" style="text-align:center; color:#888; padding:16px;">No items recorded.</td></tr>
            <?php else: ?>
            <?php foreach ($items as $i => $item):
                $line_sub = floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? $item['item_name'] ?? '—') ?></td>
                <td class="text-center"><?= htmlspecialchars($item['quantity'] ?? '') ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit'] ?? '') ?></td>
                <td class="text-right"><?= number_format(floatval($item['unit_price'] ?? 0), 2) ?></td>
                <td class="text-center"><?= floatval($item['tax_percent'] ?? 0) ?>%</td>
                <td class="text-right fw-bold"><?= number_format(floatval($item['total'] ?? $line_sub), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row">
            <span>Subtotal:</span>
            <span>TZS <?= number_format($subtotal, 2) ?></span>
        </div>
        <?php if ($tax_sum > 0): ?>
        <div class="totals-row">
            <span>Tax:</span>
            <span>TZS <?= number_format($tax_sum, 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="totals-row grand-total">
            <span>NET PAYABLE:</span>
            <span>TZS <?= number_format($net_payable, 2) ?></span>
        </div>
    </div>

    <!-- NOTES -->
    <?php if (!empty($ipc['notes'])): ?>
    <div class="notes-section">
        <div>
            <strong>Notes:</strong>
            <p><?= nl2br(htmlspecialchars($ipc['notes'])) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- SIGNATURE -->
    <?php
    $creator_name  = trim(($ipc['creator_first'] ?? '') . ' ' . ($ipc['creator_last'] ?? '')) ?: 'System';
    $creator_role  = ucfirst($ipc['creator_role'] ?? '');
    $reviewer_name = trim(($ipc['reviewer_first'] ?? '') . ' ' . ($ipc['reviewer_last'] ?? ''));
    $reviewer_role = ucfirst($ipc['reviewer_role'] ?? '');
    $approver_name = trim(($ipc['approver_first'] ?? '') . ' ' . ($ipc['approver_last'] ?? ''));
    $approver_role = ucfirst($ipc['approver_role'] ?? '');
    ?>
    <div class="signature-box">
        <div class="signature-line">
            Created By<br>
            <span style="font-weight:800;"><?= htmlspecialchars($creator_name) ?></span><br>
            <?php if ($creator_role): ?><span style="font-weight:400;font-size:10px;color:#555;">(<?= htmlspecialchars($creator_role) ?>)</span><?php endif; ?>
        </div>
        <div class="signature-line">
            Reviewed By<br>
            <?php if ($reviewer_name): ?>
            <span style="font-weight:800;"><?= htmlspecialchars($reviewer_name) ?></span><br>
            <?php if ($reviewer_role): ?><span style="font-weight:400;font-size:10px;color:#555;">(<?= htmlspecialchars($reviewer_role) ?>)</span><?php endif; ?>
            <?php else: ?>
            <span style="font-weight:400;font-size:10px;color:#aaa;">Not yet reviewed</span>
            <?php endif; ?>
        </div>
        <div class="signature-line">
            Approved By<br>
            <?php if ($approver_name): ?>
            <span style="font-weight:800;"><?= htmlspecialchars($approver_name) ?></span><br>
            <?php if ($approver_role): ?><span style="font-weight:400;font-size:10px;color:#555;">(<?= htmlspecialchars($approver_role) ?>)</span><?php endif; ?>
            <?php else: ?>
            <span style="font-weight:400;font-size:10px;color:#aaa;">Not yet approved</span>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
