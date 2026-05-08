<?php
// File: api/account/print_rfq.php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!isAuthenticated()) die('Unauthorized');

$rfq_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rfq_id) die('Invalid RFQ ID');

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

// Printed-by info
$printed_by   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (!$printed_by) $printed_by = 'System';
$printed_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User';
$printed_at   = date('d M, Y') . ' at ' . date('H:i:s');
$copy_year    = date('Y');

$status = $rfq['status'] ?? 'draft';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RFQ #<?= htmlspecialchars($rfq['rfq_number']) ?></title>
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
        .po-title {
            text-align: right;
            background: #3498db;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            padding: 16px 22px;
            border-radius: 8px;
            min-width: 195px;
        }
        .po-title h2 {
            margin: 0 0 10px 0;
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 1px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .po-title p {
            margin: 4px 0;
            font-size: 12px;
            color: #fff;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .po-title strong { font-weight: 600; }

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
        .box p { margin: 5px 0; color: #1a252f; font-size: 11.5px; }
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

        /* ── FOOTER ── */
        .print-footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 3px 22px;
            text-align: center;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .print-footer p { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1.2; }
        .print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

        .footer-spacer { height: 50px; }

        @page { margin: 10mm 8mm 16mm 8mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0 !important; padding: 0 !important; }
            .footer-spacer { display: none !important; }
            .box { box-shadow: none; border: 1px solid #e0e0e0; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px; cursor:pointer;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px; cursor:pointer;">Close</button>
    </div>

    <!-- HEADER -->
    <div class="header">
        <div class="company-info">
            <h1><?= htmlspecialchars($comp['name']) ?></h1>
            <div class="company-addr-row">
                <?php if (!empty($comp['logo'])): ?>
                <img src="<?= htmlspecialchars('../../' . $comp['logo']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="company-addr-info">
                    <?php if (!empty($comp['address'])): ?>
                    <p><?= htmlspecialchars($comp['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($comp['postal_address'])): ?>
                    <p><?= htmlspecialchars($comp['postal_address']) ?></p>
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

        <div class="po-title">
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
            <?php if (!empty($rfq['supplier_company'])): ?>
            <p><?= htmlspecialchars($rfq['supplier_company']) ?></p>
            <?php endif; ?>
            
            <?php if (!empty($rfq['s_postal_address'])): ?>
            <p><?= htmlspecialchars($rfq['s_postal_address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($rfq['s_address'])): ?>
            <p><?= htmlspecialchars($rfq['s_address']) ?></p>
            <?php endif; ?>

            <?php if (!empty($rfq['s_phone'])): ?>
            <p><?= htmlspecialchars($rfq['s_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($rfq['s_email'])): ?>
            <p><?= htmlspecialchars($rfq['s_email']) ?></p>
            <?php endif; ?>
            <?php
            $s_tv = [];
            if (!empty($rfq['s_tin'])) $s_tv[] = 'TIN: ' . htmlspecialchars($rfq['s_tin']);
            if (!empty($rfq['s_vrn'])) $s_tv[] = 'VRN: ' . htmlspecialchars($rfq['s_vrn']);
            if ($s_tv): ?>
            <p><?= implode(' | ', $s_tv) ?></p>
            <?php endif; ?>
        </div>
        <div class="box">
            <h3>RFQ Information</h3>
            <p><strong>Response Deadline:</strong> <?= !empty($rfq['deadline_date']) ? date('d M Y', strtotime($rfq['deadline_date'])) : 'Not specified' ?></p>
            <?php if (!empty($rfq['project_contract_no'])): ?>
            <p><strong>Contract No:</strong> <?= htmlspecialchars($rfq['project_contract_no']) ?></p>
            <?php endif; ?>
            <?php if (!empty($rfq['project_name'])): ?>
            <p><strong>Project:</strong> <?= htmlspecialchars($rfq['project_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($rfq['warehouse_name'])): ?>
            <p><strong>Warehouse:</strong> <?= htmlspecialchars($rfq['warehouse_name']) ?></p>
            <?php endif; ?>
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

    <!-- AUTHORIZATION -->
    <table class="auth-table" style="width:100%;border-collapse:collapse;margin-top:36px;page-break-inside:avoid;">
        <thead>
            <tr>
                <th style="width:33.33%;text-align:center;background:#f8f9fa;border:1px solid #dee2e6;padding:7px 10px;font-size:8.5pt;text-transform:uppercase;letter-spacing:.5px;color:#495057;font-weight:700;">
                    Prepared By
                </th>
                <th style="width:33.33%;text-align:center;background:#f8f9fa;border:1px solid #dee2e6;padding:7px 10px;font-size:8.5pt;text-transform:uppercase;letter-spacing:.5px;color:#0d6efd;font-weight:700;">
                    Reviewed By
                </th>
                <th style="width:33.33%;text-align:center;background:#f8f9fa;border:1px solid #dee2e6;padding:7px 10px;font-size:8.5pt;text-transform:uppercase;letter-spacing:.5px;color:#198754;font-weight:700;">
                    Approved By
                </th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <!-- Prepared By -->
                <td style="border:1px solid #dee2e6;padding:10px 14px;vertical-align:top;min-height:70px;">
                    <?php if (!empty($rfq['prepared_by_name'])): ?>
                    <div style="font-weight:700;font-size:9pt;"><?= htmlspecialchars($rfq['prepared_by_name']) ?></div>
                    <div style="color:#666;font-size:8pt;"><?= htmlspecialchars($rfq['prepared_by_role'] ?? '') ?></div>
                    <?php else: ?>
                    <div style="color:#aaa;font-size:8pt;font-style:italic;">&nbsp;</div>
                    <?php endif; ?>
                    <div style="border-bottom:1px solid #333;margin-top:32px;"></div>
                    <div style="font-size:7.5pt;color:#888;margin-top:3px;text-align:center;">Signature</div>
                </td>

                <!-- Reviewed By -->
                <td style="border:1px solid #dee2e6;padding:10px 14px;vertical-align:top;min-height:70px;">
                    <?php if (!empty($rfq['reviewed_by_name'])): ?>
                    <div style="font-weight:700;font-size:9pt;"><?= htmlspecialchars($rfq['reviewed_by_name']) ?></div>
                    <div style="color:#666;font-size:8pt;"><?= htmlspecialchars($rfq['reviewed_by_role'] ?? '') ?></div>
                    <?php if (!empty($rfq['reviewed_at'])): ?>
                    <div style="color:#888;font-size:7.5pt;"><?= date('d M Y', strtotime($rfq['reviewed_at'])) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div style="color:#aaa;font-size:8pt;font-style:italic;">&nbsp;</div>
                    <?php endif; ?>
                    <div style="border-bottom:1px solid #333;margin-top:28px;"></div>
                    <div style="font-size:7.5pt;color:#888;margin-top:3px;text-align:center;">Signature</div>
                </td>

                <!-- Approved By -->
                <td style="border:1px solid #dee2e6;padding:10px 14px;vertical-align:top;min-height:70px;">
                    <?php if (!empty($rfq['approved_by_name'])): ?>
                    <div style="font-weight:700;font-size:9pt;"><?= htmlspecialchars($rfq['approved_by_name']) ?></div>
                    <div style="color:#666;font-size:8pt;"><?= htmlspecialchars($rfq['approved_by_role'] ?? '') ?></div>
                    <?php if (!empty($rfq['approved_at'])): ?>
                    <div style="color:#888;font-size:7.5pt;"><?= date('d M Y', strtotime($rfq['approved_at'])) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div style="color:#aaa;font-size:8pt;font-style:italic;">&nbsp;</div>
                    <?php endif; ?>
                    <div style="border-bottom:1px solid #333;margin-top:28px;"></div>
                    <div style="font-size:7.5pt;color:#888;margin-top:3px;text-align:center;">Signature</div>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="footer-spacer"></div>

    <!-- FOOTER -->
    <div class="print-footer">
        <p>This document was Printed by <strong><?= htmlspecialchars($printed_by) ?></strong> &mdash; <?= htmlspecialchars(ucfirst($printed_role)) ?> on <?= $printed_at ?></p>
        <p class="brand">Powered By BJP Technologies &copy; <?= $copy_year ?>, All Rights Reserved</p>
    </div>

</body>
</html>
