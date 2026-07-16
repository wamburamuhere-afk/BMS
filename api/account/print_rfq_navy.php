<?php
// File: api/account/print_rfq_navy.php
// "Striped" layout — a genuine letter-format RFQ (To:/Subject:/item table/signature),
// visually distinct from the boxed-panel Navy/Corporate/Banded family used by
// Purchase Order, Return Note, and Debit Note. Route name kept as "navy" for the
// picker wiring already in place; the design itself is unrelated to that family.
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

// Accent color — dedicated to this RFQ-only design family (not shared with the
// Navy/Corporate/Banded set used by other document types).
$accent = getSetting('print_template_color_rfq_striped', '#d9601a');
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
            line-height: 1.6;
            padding: 26px 26px 0 26px;
            background: #fff;
            position: relative;
        }

        /* ── DIAGONAL STRIPE CORNER DECORATION ── */
        .stripe-corner {
            position: absolute;
            top: 0; left: 0;
            width: 230px; height: 70px;
            overflow: hidden;
            pointer-events: none;
        }
        .stripe-corner div {
            position: absolute;
            width: 300px; height: 4px;
            background: var(--accent);
            opacity: 0.85;
            transform: rotate(-28deg);
            transform-origin: left center;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .stripe-corner div:nth-child(1) { top: 8px;  left: -40px; width: 180px; opacity: 0.35; }
        .stripe-corner div:nth-child(2) { top: 16px; left: -40px; width: 220px; opacity: 0.55; }
        .stripe-corner div:nth-child(3) { top: 24px; left: -40px; width: 260px; opacity: 0.85; }

        /* ── HEADER ── */
        .letter-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 26px; padding-bottom: 16px; border-bottom: 2px solid var(--accent); }
        .letter-header .company-block { display: flex; align-items: center; gap: 12px; }
        .letter-header img { max-height: 46px; width: auto; object-fit: contain; }
        .letter-header h1 { font-size: 18px; font-weight: 800; letter-spacing: 0.3px; }
        .letter-header .meta { text-align: right; font-size: 11px; }
        .letter-header .meta p { margin: 2px 0; }

        .company-contacts { font-size: 10px; color: #555; margin-bottom: 20px; }
        .company-contacts p { margin: 1px 0; }

        /* ── TO / SUBJECT (plain letter text, not boxed) ── */
        .to-block { margin-bottom: 16px; font-size: 11.5px; }
        .to-block .label { font-weight: 700; letter-spacing: 0.5px; color: var(--accent); font-size: 10px; text-transform: uppercase; margin-bottom: 4px; }
        .to-block p { margin: 1px 0; }
        .subject-line { font-weight: 700; font-size: 12px; margin-bottom: 18px; }
        .subject-line span { color: var(--accent); }

        .rfq-meta-row { display: flex; gap: 30px; margin-bottom: 18px; font-size: 11.5px; }
        .rfq-meta-row div strong { display: block; color: var(--accent); font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }

        /* ── ITEMS TABLE ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: var(--accent); color: #fff; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 9px 10px; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr { border-bottom: 1px solid #e4e8ec; }
        tbody tr:nth-child(even) { background: #fdf6f1; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tbody tr:last-child { border-bottom: 2px solid var(--accent); }
        tbody tr td { height: 0.75cm; padding: 2px 10px; vertical-align: middle; font-size: 13px; line-height: 1.6; color: #1a252f; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }

        .closing-line { margin: 18px 0 4px; font-size: 11.5px; }

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
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <div class="stripe-corner"><div></div><div></div><div></div></div>

    <!-- HEADER -->
    <div class="letter-header">
        <div class="company-block">
            <?php if (!empty($comp['logo'])): ?>
            <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
        </div>
        <div class="meta">
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($rfq['rfq_date'])) ?></p>
            <p><strong>RFQ #:</strong> <?= htmlspecialchars($rfq['rfq_number']) ?></p>
            <p><strong>Status:</strong> <?= strtoupper($status) ?></p>
        </div>
    </div>

    <!-- COMPANY CONTACTS -->
    <div class="company-contacts">
        <?php if (!empty($comp['address'])): ?><p><?= htmlspecialchars($comp['address']) ?></p><?php endif; ?>
        <?php
        $ln = [];
        if (!empty($comp['phone'])) $ln[] = 'Phone: ' . htmlspecialchars($comp['phone']);
        if (!empty($comp['email'])) $ln[] = 'Email: ' . htmlspecialchars($comp['email']);
        if (!empty($comp['website'])) $ln[] = 'Web: ' . htmlspecialchars($comp['website']);
        if ($ln): ?><p><?= implode(' &nbsp;|&nbsp; ', $ln) ?></p><?php endif; ?>
        <?php
        $tv = [];
        if (!empty($comp['tin'])) $tv[] = 'TIN: ' . htmlspecialchars($comp['tin']);
        if (!empty($comp['vrn'])) $tv[] = 'VRN: ' . htmlspecialchars($comp['vrn']);
        if ($tv): ?><p><?= implode(' &nbsp;|&nbsp; ', $tv) ?></p><?php endif; ?>
    </div>

    <!-- TO -->
    <div class="to-block">
        <div class="label">To</div>
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

    <!-- SUBJECT -->
    <div class="subject-line">SUBJECT: <span>REQUEST FOR QUOTATION (RFQ)</span></div>

    <!-- RFQ META (real fields only) -->
    <div class="rfq-meta-row">
        <div><strong>Response Deadline</strong><?= !empty($rfq['deadline_date']) ? date('d M Y', strtotime($rfq['deadline_date'])) : 'Not specified' ?></div>
        <?php if (!empty($rfq['project_name'])): ?><div><strong>Project</strong><?= htmlspecialchars($rfq['project_name']) ?></div><?php endif; ?>
        <?php if (!empty($rfq['project_contract_no'])): ?><div><strong>Contract No</strong><?= htmlspecialchars($rfq['project_contract_no']) ?></div><?php endif; ?>
        <?php if (!empty($rfq['warehouse_name'])): ?><div><strong>Warehouse</strong><?= htmlspecialchars($rfq['warehouse_name']) ?></div><?php endif; ?>
        <div><strong>Created By</strong><?= htmlspecialchars($rfq['username'] ?? 'N/A') ?></div>
    </div>

    <!-- ITEMS TABLE -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:38px;">No.</th>
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

    <div class="closing-line">Sincerely,</div>

    <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

</body>
</html>
