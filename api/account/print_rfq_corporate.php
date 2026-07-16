<?php
// File: api/account/print_rfq_corporate.php
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
// retinting "Corporate" here also retints every other document that uses the
// Corporate layout.
$accent = getSetting('print_template_color_corporate', '#000000');
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
            color: #1a1a1a;
            line-height: 1.5;
            padding: 20px 20px 0 20px;
            background: #fff;
        }

        /* ── COMPANY STRIP (name, logo, contacts) ── */
        .company-strip { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid #d8d8d8; }
        .company-strip img { max-height: 46px; width: auto; object-fit: contain; }
        .company-strip h1 { font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .company-strip p { font-size: 10px; color: #444; margin: 0; }

        /* ── BLACK TITLE BAR ── */
        .title-bar { background: var(--accent); color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .title-bar h2 { font-size: 20px; font-weight: 800; letter-spacing: 1.5px; }
        .title-bar .meta { text-align: right; font-size: 11.5px; }
        .title-bar .meta p { margin: 2px 0; }

        /* ── SECTION LABEL BARS ── */
        .section-label { background: var(--accent); color: #fff; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 6px 12px; margin-bottom: 0; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .section-body { border: 1px solid var(--accent); border-top: none; padding: 12px 14px; margin-bottom: 18px; }
        .section-body p { margin: 3px 0; font-size: 11.5px; }
        .section-body strong { font-weight: 700; }

        .two-col { display: flex; gap: 16px; }
        .two-col > div { width: 50%; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #ddd; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .status-badge { display: inline-block; padding: 5px 14px; background: var(--accent); color: #fff; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.6px; border-radius: 3px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print { .no-print { display: none !important; } body { margin: 0 !important; } }
    </style>
    <?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <!-- COMPANY STRIP -->
    <div class="company-strip">
        <?php if (!empty($comp['logo'])): ?>
        <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <?php
            $line = [];
            if (!empty($comp['address'])) $line[] = htmlspecialchars($comp['address']);
            if (!empty($comp['phone']))   $line[] = 'Tel: ' . htmlspecialchars($comp['phone']);
            if (!empty($comp['email']))   $line[] = htmlspecialchars($comp['email']);
            if ($line): ?><p><?= implode(' &nbsp;|&nbsp; ', $line) ?></p><?php endif; ?>
            <?php
            $tv = [];
            if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
            if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
            if (!empty($comp['website'])) $tv[] = 'Web: ' . htmlspecialchars($comp['website']);
            if ($tv): ?><p><?= implode(' &nbsp;|&nbsp; ', $tv) ?></p><?php endif; ?>
        </div>
    </div>

    <!-- TITLE BAR -->
    <div class="title-bar">
        <h2>REQUEST FOR QUOTATION</h2>
        <div class="meta">
            <p><strong>RFQ #:</strong> <?= htmlspecialchars($rfq['rfq_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($rfq['rfq_date'])) ?></p>
        </div>
    </div>

    <!-- VENDOR + RFQ INFORMATION -->
    <div class="two-col">
        <div>
            <div class="section-label">Vendor</div>
            <div class="section-body">
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
        </div>
        <div>
            <div class="section-label">RFQ Information</div>
            <div class="section-body">
                <p><strong>Response Deadline:</strong> <?= !empty($rfq['deadline_date']) ? date('d M Y', strtotime($rfq['deadline_date'])) : 'Not specified' ?></p>
                <?php if (!empty($rfq['project_contract_no'])): ?><p><strong>Contract No:</strong> <?= htmlspecialchars($rfq['project_contract_no']) ?></p><?php endif; ?>
                <?php if (!empty($rfq['project_name'])): ?><p><strong>Project:</strong> <?= htmlspecialchars($rfq['project_name']) ?></p><?php endif; ?>
                <?php if (!empty($rfq['warehouse_name'])): ?><p><strong>Warehouse:</strong> <?= htmlspecialchars($rfq['warehouse_name']) ?></p><?php endif; ?>
                <p><strong>Created By:</strong> <?= htmlspecialchars($rfq['username'] ?? 'N/A') ?></p>
                <p style="margin-top:8px;"><span class="status-badge"><?= strtoupper($status) ?></span></p>
            </div>
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
