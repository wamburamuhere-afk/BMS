<?php
// File: app/bms/purchase/print_purchase_return_navy.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';

if (!isAuthenticated()) die("Unauthorized");
if (!canView('purchase_returns')) die("Access Denied");

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
assertScopeForRecordHtml('purchase_returns', 'purchase_return_id', $return_id);

try {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            pr.*,
            s.supplier_name, s.company_name as supplier_company, s.phone as s_phone,
            s.email as s_email, s.address as s_address, s.tax_id as s_tin,
            s.vat_number as s_vrn, s.postal_address as s_postal_address,
            grn.receipt_number as grn_ref_number,
            w.warehouse_name,
            u.username as created_by_name,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))    AS creator_name,
            COALESCE(u.user_role, u.role)                                          AS creator_role,
            TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
            COALESCE(ur.user_role, ur.role)                                        AS reviewer_role,
            TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
            COALESCE(ua.user_role, ua.role)                                        AS approver_role
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_receipts grn ON pr.receipt_id = grn.receipt_id
        LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
        LEFT JOIN users u  ON pr.created_by  = u.user_id
        LEFT JOIN users ur ON pr.reviewed_by = ur.user_id
        LEFT JOIN users ua ON pr.approved_by = ua.user_id
        WHERE pr.purchase_return_id = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return) die("Purchase Return not found");

    $stmtItems = $pdo->prepare("
        SELECT pri.*, p.product_name, p.sku, p.unit
        FROM purchase_return_items pri
        LEFT JOIN products p ON pri.product_id = p.product_id
        WHERE pri.purchase_return_id = ? ORDER BY pri.return_item_id
    ");
    $stmtItems->execute([$return_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$currency = $return['currency'] ?? 'TZS';

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

$wf_sigs = getWorkflowSignatures($pdo, 'purchase_return', $return_id);
$wf = [
    'created_by_name'    => trim($return['creator_name']  ?? '') ?: ($return['created_by_name'] ?? ''),
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
    '__include_css'      => true,
];

// Print-template accent color — shared per LAYOUT NAME (not per document type), so
// retinting "Navy" here also retints every other document that uses the Navy layout.
// Configurable from Company Profile > Print Template Colors; falls back to the
// original design color when unset.
$accent = getSetting('print_template_color_navy', '#0f1f3d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Return #<?= htmlspecialchars($return['return_number']) ?></title>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #1a252f;
            line-height: 1.5;
            padding: 0 20px;
            background: #fff;
        }

        /* ── NAVY HEADER BAND ── */
        .navy-header {
            background: var(--accent);
            color: #fff;
            padding: 22px 26px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-radius: 0 0 10px 10px;
            margin: 0 -20px 22px -20px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .navy-header .company-side { display: flex; gap: 14px; align-items: flex-start; }
        .navy-header .company-side img { max-height: 54px; width: auto; object-fit: contain; background: #fff; border-radius: 4px; padding: 3px; }
        .navy-header h1 { font-size: 19px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; color: #fff; }
        .navy-header .company-side p { font-size: 10.5px; color: #cdd7ec; margin: 1px 0; }
        .navy-header .ret-side { text-align: right; }
        .navy-header .ret-side h2 { font-size: 22px; font-weight: 800; letter-spacing: 1px; margin-bottom: 8px; color: #fff; }
        .navy-header .ret-side p { font-size: 11.5px; color: #fff; margin: 3px 0; }
        .navy-header .ret-side strong { font-weight: 700; }

        /* ── INFO BOXES ── */
        .details-grid { display: flex; justify-content: space-between; margin-bottom: 22px; gap: 16px; }
        .box { width: 50%; background: #f4f6fb; padding: 14px 16px; border-radius: 6px; border-left: 4px solid var(--accent); print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .box h3 { font-size: 11px; color: var(--accent); padding-bottom: 7px; margin-bottom: 10px; border-bottom: 1.5px solid var(--accent); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .box p { margin: 3px 0; color: #1a252f; font-size: 11.5px; }
        .box strong { color: #1a252f; font-weight: 600; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #f7f8fc; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        /* ── TOTALS ── */
        .totals { float: right; width: 310px; background: var(--accent); color: #fff; padding: 14px 18px; border-radius: 6px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.15); }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand-total { border-top: 2px solid #fff; border-bottom: none; margin-top: 8px; padding-top: 10px; font-size: 14px; font-weight: 700; }

        /* ── NOTES ── */
        .notes-section { clear: both; padding-top: 22px; margin-top: 14px; }
        .notes-section > div { background: #f4f6fb; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid var(--accent); }
        .notes-section strong { color: var(--accent); display: block; margin-bottom: 5px; font-size: 11.5px; font-weight: 700; }
        .notes-section p { color: #1a252f; font-size: 11px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box, .notes-section > div { box-shadow: none; border: 1px solid #e0e0e0; }
        }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin:20px 0; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <!-- HEADER -->
    <div class="navy-header">
        <div class="company-side">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                <?php if (!empty($comp['postal_address'])): ?><p>P.O. Box <?= htmlspecialchars($comp['postal_address']) ?></p><?php endif; ?>
                <?php if (!empty($comp['phone'])): ?><p>Phone: <?= htmlspecialchars($comp['phone']) ?></p><?php endif; ?>
                <?php
                $we = [];
                if (!empty($comp['website'])) $we[] = 'Web: ' . htmlspecialchars($comp['website']);
                if (!empty($comp['email']))   $we[] = 'Email: ' . htmlspecialchars($comp['email']);
                if ($we): ?><p><?= implode(' | ', $we) ?></p><?php endif; ?>
                <?php
                $tv = [];
                if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
                if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
                if ($tv): ?><p><?= implode(' | ', $tv) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="ret-side">
            <h2>PURCHASE RETURN</h2>
            <p><strong>Return #:</strong> <?= htmlspecialchars($return['return_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($return['return_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($return['status']) ?></p>
        </div>
    </div>

    <!-- VENDOR + RETURN INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Vendor</h3>
            <p><strong><?= htmlspecialchars($return['supplier_name']) ?></strong></p>
            <?php if (!empty($return['supplier_company'])): ?><p><?= htmlspecialchars($return['supplier_company']) ?></p><?php endif; ?>
            <?php if (!empty($return['s_postal_address'])): ?><p><?= htmlspecialchars($return['s_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($return['s_address'])): ?><p><?= htmlspecialchars($return['s_address']) ?></p><?php endif; ?>
            <?php if (!empty($return['s_phone'])): ?><p><?= htmlspecialchars($return['s_phone']) ?></p><?php endif; ?>
            <?php if (!empty($return['s_email'])): ?><p><?= htmlspecialchars($return['s_email']) ?></p><?php endif; ?>
            <?php
            $s_tv = [];
            if (!empty($return['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($return['s_tin']);
            if (!empty($return['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($return['s_vrn']);
            if ($s_tv): ?><p><?= implode(' | ', $s_tv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>Return Information</h3>
            <p><strong>Reason:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $return['reason'] ?? 'N/A'))) ?></p>
            <?php if (!empty($return['grn_ref_number'])): ?><p><strong>Related GRN:</strong> <?= htmlspecialchars($return['grn_ref_number']) ?></p><?php endif; ?>
            <?php if (!empty($return['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($return['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($return['created_by_name'] ?? 'N/A') ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th class="text-center" style="width:100px;">Product Code</th>
                <th>Item / Description</th>
                <th class="text-right" style="width:80px;">Qty</th>
                <th class="text-right" style="width:105px;">Unit Price</th>
                <th class="text-right" style="width:115px;">Total (<?= $currency ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $subtotal = 0; $totalTax = 0;
            foreach ($items as $i => $item):
                $lineBase  = floatval($item['quantity']) * floatval($item['unit_price']);
                $lineTax   = floatval($item['tax_amount'] ?? 0);
                $lineTotal = $lineBase + $lineTax;
                $subtotal += $lineBase;
                $totalTax += $lineTax;
                $unit = !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '';
            ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= !empty($item['sku']) ? htmlspecialchars($item['sku']) : '—' ?></td>
                <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                <td class="text-right"><?= floatval($item['quantity']) ?><?= $unit ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right fw-bold"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- TOTALS -->
    <div class="totals">
        <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
        <div class="totals-row"><span>VAT (18%):</span><span><?= $currency ?> <?= number_format($totalTax, 2) ?></span></div>
        <div class="totals-row grand-total"><span>TOTAL RETURN VALUE:</span><span><?= $currency ?> <?= number_format($subtotal + $totalTax, 2) ?></span></div>
    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <?php if (!empty($return['reason_details'])): ?>
        <div><strong>Reason Details:</strong><p><?= nl2br(htmlspecialchars($return['reason_details'])) ?></p></div>
        <?php endif; ?>
        <?php if (!empty($return['notes'])): ?>
        <div><strong>Additional Notes:</strong><p><?= nl2br(htmlspecialchars($return['notes'])) ?></p></div>
        <?php endif; ?>
    </div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
