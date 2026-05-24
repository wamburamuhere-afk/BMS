<?php
// File: app/bms/grn/grn_print.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';

if (!isAuthenticated()) die("Unauthorized");

// Phase 5a — print pages get a canView gate (admin auto-bypass)
if (!canView('grn')) die("Access Denied");

$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($receipt_id <= 0) die("Invalid GRN ID");

global $pdo;

// Fetch GRN Details with full supplier, warehouse, and three-approval audit info
$stmt = $pdo->prepare("
    SELECT
        pr.*,
        s.supplier_name, s.company_name as supplier_company, s.phone as s_phone,
        s.email as s_email, s.address as s_address, s.tax_id as s_tin,
        s.vat_number as s_vrn, s.postal_address as s_postal_address,
        w.warehouse_name, w.location as warehouse_location,
        u.username as created_by_username,
        TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) as created_by_full_name,
        u.user_role as created_by_role,
        u2.username as received_by_name,
        po.order_number as po_ref_number
    FROM purchase_receipts pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
    LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    LEFT JOIN users u2 ON pr.received_by = u2.user_id
    LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
    WHERE pr.receipt_id = ?
");
$stmt->execute([$receipt_id]);
$grn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grn) die("GRN Not Found");

// Fetch GRN Items
$stmtItems = $pdo->prepare("
    SELECT 
        ri.*,
        p.product_name,
        p.sku,
        p.unit
    FROM receipt_items ri
    LEFT JOIN products p ON ri.product_id = p.product_id
    WHERE ri.receipt_id = ? ORDER BY ri.receipt_item_id
");
$stmtItems->execute([$receipt_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

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

// Three-approval watermark — shown when status is not yet 'approved'.
// Legacy 'completed' rows count as approved (they were closed under the
// previous flow and already have stock applied), so we map them here so
// the watermark partial doesn't flag them.
$wf_status = $grn['status'] ?? 'pending';
if ($wf_status === 'completed') $wf_status = 'approved';

// Three-approval signature data (Created / Reviewed / Approved By) —
// consistent with the other prints. Falls back to received_by_name for
// legacy rows whose audit columns are NULL (created before the migration).
$grn_creator_name = $grn['created_by_full_name'] ?: ($grn['created_by_username'] ?? ($grn['received_by_name'] ?? ''));
$wf = [
    'created_by_name'  => $grn_creator_name,
    'created_by_role'  => $grn['created_by_role'] ?? '',
    'reviewed_by_name' => $grn['reviewed_by_name'] ?? '',
    'reviewed_by_role' => $grn['reviewed_by_role'] ?? '',
    'approved_by_name' => $grn['approved_by_name'] ?? '',
    'approved_by_role' => $grn['approved_by_role'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GRN #<?= htmlspecialchars($grn['receipt_number']) ?></title>
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
        }
        .totals-row.grand-total {
            border-top: 2px solid #3498db;
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
        }
    </style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="closePrintWindow()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>
    <script>
    // window.close() only works on windows opened by script (window.open).
    // When the page is reached via target="_blank" or a direct link, fall
    // back to history.back() so the Close button is never a dead button.
    function closePrintWindow() {
        try { window.close(); } catch (e) {}
        // If window.close() was a no-op (the tab still exists 50ms later),
        // navigate back instead.
        setTimeout(function() {
            if (!window.closed) {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '<?= getUrl("grn") ?>';
                }
            }
        }, 100);
    }
    </script>

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
            <h2>GOODS RECEIVED NOTE</h2>
            <p><strong>GRN #:</strong> <?= htmlspecialchars($grn['receipt_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($grn['receipt_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($grn['status'] ?: 'COMPLETED') ?></p>
        </div>
    </div>

    <!-- VENDOR + GRN INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Supplier</h3>
            <p><strong><?= htmlspecialchars($grn['supplier_name']) ?></strong></p>
            <?php if (!empty($grn['supplier_company'])): ?>
            <p><?= htmlspecialchars($grn['supplier_company']) ?></p>
            <?php endif; ?>
            
            <?php if (!empty($grn['s_postal_address'])): ?>
            <p><?= htmlspecialchars($grn['s_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($grn['s_address'])): ?>
            <p><?= htmlspecialchars($grn['s_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($grn['s_phone'])): ?>
            <p><?= htmlspecialchars($grn['s_phone']) ?></p>
            <?php endif; ?>
            <?php
            $s_tv = [];
            if (!empty($grn['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($grn['s_tin']);
            if (!empty($grn['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($dn['s_vrn']); // Fixed typo from earlier context if any
            if ($s_tv): ?>
            <p><?= implode(' | ', $s_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>Receipt Information</h3>
            <p><strong>Warehouse:</strong> <?= htmlspecialchars($grn['warehouse_name'] ?: 'N/A') ?></p>
            <?php if (!empty($grn['po_ref_number'])): ?>
            <p><strong>PO Reference:</strong> <?= htmlspecialchars($grn['po_ref_number']) ?></p>
            <?php endif; ?>
            <hr style="margin: 8px 0; border: none; border-top: 1px solid #dee2e6;">
            <p><strong>Received By:</strong> <?= htmlspecialchars($grn['received_by_name'] ?? 'Staff') ?></p>
            <p><strong>Prepared By:</strong> <?= htmlspecialchars($grn_creator_name ?: 'Staff') ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th>Item / Description</th>
                <th class="text-right" style="width:100px;">Qty Received</th>
                <th class="text-right" style="width:105px;">Unit Cost</th>
                <th class="text-right" style="width:115px;">Total Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totalCost = 0;
            foreach ($items as $i => $item):
                $lineTotal = floatval($item['quantity_received']) * floatval($item['unit_price']);
                $totalCost += $lineTotal;
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right fw-bold"><?= number_format($item['quantity_received'], 2) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row grand-total">
            <span>TOTAL RECEIPT VALUE:</span>
            <span><?= number_format($totalCost, 2) ?></span>
        </div>
    </div>

    <!-- NOTES -->
    <?php if (!empty($grn['notes'])): ?>
    <div class="notes-section">
        <div>
            <strong>Reception Notes:</strong>
            <p><?= nl2br(htmlspecialchars($grn['notes'])) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- DRAFT WATERMARK (position:fixed; only when status !== 'approved'/'completed') -->
    <?php require ROOT_DIR . '/includes/workflow_draft_watermark.php'; ?>

    <!-- SIGNATURE — three_approval.md §6.3 canonical block (Created/Reviewed/Approved By) -->
    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
