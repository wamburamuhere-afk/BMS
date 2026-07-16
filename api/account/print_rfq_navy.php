<?php
// File: api/account/print_rfq_navy.php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

if (!isAuthenticated()) die('Unauthorized');

$rfq_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rfq_id) die('Invalid RFQ ID');
assertScopeForRecordHtml('rfq', 'rfq_id', $rfq_id);

global $pdo;

$stmt = $pdo->prepare("
    SELECT r.*, s.supplier_name, s.company_name AS supplier_company,
           s.address AS s_address, s.postal_address AS s_postal_address,
           s.phone AS s_phone, s.email AS s_email,
           s.tax_id AS s_tin, s.vat_number AS s_vrn,
           w.warehouse_name, p.project_name, p.contract_number as project_contract_no,
           u.first_name, u.last_name, u.username
    FROM rfq r
    LEFT JOIN suppliers s  ON r.supplier_id  = s.supplier_id
    LEFT JOIN warehouses w ON r.warehouse_id = w.warehouse_id
    LEFT JOIN projects   p ON r.project_id   = p.project_id
    LEFT JOIN users      u ON r.created_by   = u.user_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rfq) die('RFQ not found');

$stmt2 = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id = ? ORDER BY item_order");
$stmt2->execute([$rfq_id]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

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

$status = $rfq['status'] ?? 'draft';

$wf_sigs      = getWorkflowSignatures($pdo, 'rfq', $rfq_id);
$rfq_creator  = trim(($rfq['first_name'] ?? '') . ' ' . ($rfq['last_name'] ?? ''))
                ?: ($rfq['username'] ?? '');
$wf = [
    'created_by_name'    => $rfq_creator,
    'created_by_role'    => '',
    'reviewed_by_name'   => $rfq['reviewed_by_name'] ?? '',
    'reviewed_by_role'   => $rfq['reviewed_by_role'] ?? '',
    'approved_by_name'   => $rfq['approved_by_name'] ?? '',
    'approved_by_role'   => $rfq['approved_by_role'] ?? '',
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
$accent = getSetting('print_template_color_navy', '#0f1f3d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RFQ #<?= htmlspecialchars($rfq['rfq_number']) ?></title>
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
        .navy-header .rfq-side { text-align: right; }
        .navy-header .rfq-side h2 { font-size: 20px; font-weight: 800; letter-spacing: 1px; margin-bottom: 8px; color: #fff; }
        .navy-header .rfq-side p { font-size: 11.5px; color: #fff; margin: 3px 0; }
        .navy-header .rfq-side strong { font-weight: 700; }

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

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; }
            .box { box-shadow: none; border: 1px solid #e0e0e0; }
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
            <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div>
                <h1><?= htmlspecialchars($comp['name']) ?></h1>
                <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
                <?php if (!empty($comp['postal_address'])): ?><p><?= htmlspecialchars($comp['postal_address']) ?></p><?php endif; ?>
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
        <div class="rfq-side">
            <h2>REQUEST FOR QUOTATION</h2>
            <p><strong>RFQ #:</strong> <?= htmlspecialchars($rfq['rfq_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($rfq['rfq_date'])) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($status) ?></p>
        </div>
    </div>

    <!-- VENDOR + RFQ INFO -->
    <div class="details-grid">
        <div class="box">
            <h3>Vendor</h3>
            <p><strong><?= htmlspecialchars($rfq['supplier_name'] ?? '—') ?></strong></p>
            <?php if (!empty($rfq['supplier_company'])): ?><p><?= htmlspecialchars($rfq['supplier_company']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['s_postal_address'])): ?><p><?= htmlspecialchars($rfq['s_postal_address']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['s_address'])): ?><p><?= htmlspecialchars($rfq['s_address']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['s_phone'])): ?><p><?= htmlspecialchars($rfq['s_phone']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['s_email'])): ?><p><?= htmlspecialchars($rfq['s_email']) ?></p><?php endif; ?>
            <?php
            $s_tv = [];
            if (!empty($rfq['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($rfq['s_tin']);
            if (!empty($rfq['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($rfq['s_vrn']);
            if ($s_tv): ?><p><?= implode(' | ', $s_tv) ?></p><?php endif; ?>
        </div>
        <div class="box">
            <h3>RFQ Information</h3>
            <p><strong>Response Deadline:</strong> <?= !empty($rfq['deadline_date']) ? date('d M Y', strtotime($rfq['deadline_date'])) : 'Not specified' ?></p>
            <?php if (!empty($rfq['project_contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($rfq['project_contract_no']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($rfq['project_name']) ?></p><?php endif; ?>
            <?php if (!empty($rfq['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($rfq['warehouse_name']) ?></p><?php endif; ?>
            <p><strong>Created By:</strong> <?= htmlspecialchars($rfq['username'] ?? 'N/A') ?></p>
        </div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">S/NO</th>
                <th>Item / Description</th>
                <th class="text-center" style="width:120px;">Unit</th>
                <th class="text-right" style="width:100px;">Qty</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="4" style="text-align:center;color:#999;padding:20px;">No items found</td></tr>
            <?php else: ?>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td class="text-center"><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                    <td class="text-right"><?= floatval($item['qty']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
