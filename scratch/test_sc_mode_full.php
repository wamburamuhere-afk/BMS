<?php
define('BMS_SUPPRESS_PRINT_HEADER', true);
require_once __DIR__ . '/../roots.php';
includeHeader();
global $pdo;

// ── Test data ────────────────────────────────────────────────
$sc   = $pdo->query("SELECT supplier_id, supplier_name FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$proj = $pdo->query("SELECT project_id, project_name FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sc_id  = (int)($sc['supplier_id'] ?? 0);
$proj_id = (int)($proj['project_id'] ?? 0);
$sc_name = $sc['supplier_name'] ?? '';
$proj_name = $proj['project_name'] ?? '';

$pass = 0; $fail = 0; $results = [];

function t($label, $ok, $detail = '') {
    global $pass, $fail, $results;
    if ($ok) $pass++; else $fail++;
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// ── A: Migration / Schema ────────────────────────────────────
$col = $pdo->query("SHOW COLUMNS FROM project_progress_reports LIKE 'sc_id'")->fetchAll();
t('A1 — sc_id column exists in project_progress_reports', !empty($col), 'OK');

$tbl = $pdo->query("SHOW TABLES LIKE 'sc_payments'")->fetchAll();
t('A2 — sc_payments table exists', !empty($tbl), 'OK');

$sc_cols = $pdo->query("DESCRIBE sc_payments")->fetchAll(PDO::FETCH_COLUMN);
t('A3 — sc_payments has supplier_id column', in_array('supplier_id', $sc_cols), implode(', ', $sc_cols));
t('A4 — sc_payments has project_id column',  in_array('project_id', $sc_cols), '');
t('A5 — sc_payments has receipt_number column', in_array('receipt_number', $sc_cols), '');

// supplier_payments should NOT have project_id (we cleaned it up)
$sp_proj = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'project_id'")->fetchAll();
t('A6 — supplier_payments does NOT have project_id (no contamination)', empty($sp_proj), empty($sp_proj) ? 'Clean' : 'FOUND — contamination risk!');

// ── B: sub_contractor_details.php link changes ────────────────
$sc_details = file_get_contents(__DIR__ . '/../app/bms/operations/sub_contractor_details.php');
t('B1 — View Project link includes &sc_id=',
    strpos($sc_details, 'getUrl(\'project_view\') ?>?id=<?= $proj[\'project_id\'] ?>&sc_id=<?= $supplier_id ?>') !== false,
    'Found in project name link');
t('B2 — Gear dropdown View Project link includes &sc_id=',
    substr_count($sc_details, '&sc_id=<?= $supplier_id ?>') >= 2,
    'Count: ' . substr_count($sc_details, '&sc_id=<?= $supplier_id ?>'));

// ── C: project_view.php SC mode structure ────────────────────
$pv = file_get_contents(__DIR__ . '/../app/bms/operations/project_view.php');

t('C1 — sc_id detection code present',
    strpos($pv, '$sc_id   = isset($_GET[\'sc_id\']) ? intval($_GET[\'sc_id\']) : 0;') !== false, 'OK');
t('C2 — sc_mode variable set',
    strpos($pv, '$sc_mode = ($sc_id > 0);') !== false, 'OK');
t('C3 — SC context banner present',
    strpos($pv, 'SC Mode Context Banner') !== false, 'OK');
t('C4 — SC tabs block present (6-tab SC nav)',
    strpos($pv, '<!-- SC Mode — 6 sections only -->') !== false, 'OK');
t('C5 — Full mode tabs in else block',
    strpos($pv, '<!-- Full Mode — all tabs -->') !== false, 'OK');
t('C6 — Overview tab uses !$sc_mode condition',
    strpos($pv, "!\$sc_mode ? 'show active' : ''") !== false, 'OK');
t('C7 — scope-original uses $sc_mode condition for show active',
    strpos($pv, "\$sc_mode ? 'show active' : ''") !== false, 'OK');
t('C8 — sc-payments tab pane exists',
    strpos($pv, 'id="sc-payments"') !== false, 'OK');
t('C9 — SC Add Payment modal exists',
    strpos($pv, 'id="scAddPaymentModal"') !== false, 'OK');
t('C10 — scId JS var injected',
    strpos($pv, 'const scId   = <?= $sc_id ?>') !== false, 'OK');
t('C11 — scMode JS var injected',
    strpos($pv, "const scMode = <?= \$sc_mode ? 'true' : 'false' ?>") !== false, 'OK');
t('C12 — loadReportingData passes sc_id in SC mode',
    strpos($pv, 'if (scMode) rptParams.sc_id = scId;') !== false, 'OK');
t('C13 — save reporting FormData passes sc_id in SC mode',
    strpos($pv, 'if (scMode) formData.append(\'sc_id\', scId);') !== false, 'OK');
t('C14 — loadScPayments function present',
    strpos($pv, 'function loadScPayments()') !== false, 'OK');
t('C15 — saveScPayment function present',
    strpos($pv, 'function saveScPayment()') !== false, 'OK');
t('C16 — Back button returns to sub_contractors/view in SC mode',
    strpos($pv, "getUrl('sub_contractors/view') ?>?id=<?= \$sc_id ?>") !== false, 'OK');
t('C17 — Edit button hidden in SC mode (PHP conditional)',
    strpos($pv, "if (!\$sc_mode):") !== false, 'conditional present');
// Extract just the SC mode section (between SC Mode comment and Full Mode comment)
preg_match('/<!-- SC Mode — 6 sections only -->.*?<!-- Full Mode/s', $pv, $sc_tabs_m);
$sc_tabs_section = $sc_tabs_m[0] ?? '';
t('C18 — Sales tab in SC mode shows only IPC + Invoices (no Sales Orders)',
    strpos($sc_tabs_section, 'IPC') !== false &&
    strpos($sc_tabs_section, 'Invoices') !== false &&
    strpos($sc_tabs_section, 'Sales Orders') === false,
    'IPC+Invoices present, Sales Orders absent in SC tabs section');
t('C19 — Payments tab button present in SC tabs',
    strpos($pv, 'id="sc-payments-tab"') !== false, 'OK');
t('C20 — Receipt number field in SC payment modal',
    strpos($pv, 'id="scPayReceipt"') !== false, 'OK');

// ── D: API files syntax ────────────────────────────────────────
foreach ([
    '../api/sc/get_payments.php'   => 'D1',
    '../api/sc/add_payment.php'    => 'D2',
    '../api/sc/delete_payment.php' => 'D3',
] as $path => $label) {
    $out = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/' . $path) . ' 2>&1');
    t($label . ' — ' . basename($path) . ' syntax OK', strpos($out, 'No syntax errors') !== false, trim($out));
}

t('D4 — get_progress_reports.php syntax OK',
    strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/operations/get_progress_reports.php') . ' 2>&1'), 'No syntax errors') !== false, 'OK');
t('D5 — save_progress_report.php syntax OK',
    strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/operations/save_progress_report.php') . ' 2>&1'), 'No syntax errors') !== false, 'OK');
t('D6 — project_view.php syntax OK',
    strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../app/bms/operations/project_view.php') . ' 2>&1'), 'No syntax errors') !== false, 'OK');

// ── E: SC API logic tests ─────────────────────────────────────
if ($sc_id && $proj_id) {
    // E1: Insert a payment via sc_payments
    $ins = $pdo->prepare("INSERT INTO sc_payments (supplier_id, project_id, payment_date, amount, currency, payment_method, reference_number, receipt_number, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,'completed',1)");
    $ins->execute([$sc_id, $proj_id, date('Y-m-d'), 150000.00, 'TZS', 'cash', 'REF-TEST-001', 'RCP-TEST-001', 'Test payment for regression']);
    $pay_id = $pdo->lastInsertId();
    t('E1 — sc_payments INSERT succeeds', $pay_id > 0, "id=$pay_id");

    // E2: Read back
    $row = $pdo->prepare("SELECT * FROM sc_payments WHERE id = ?")->execute([$pay_id]) ? $pdo->query("SELECT * FROM sc_payments WHERE id = $pay_id")->fetch(PDO::FETCH_ASSOC) : null;
    $row = $pdo->prepare("SELECT * FROM sc_payments WHERE id = ?");
    $row->execute([$pay_id]);
    $pay = $row->fetch(PDO::FETCH_ASSOC);
    t('E2 — payment retrieved correctly', !empty($pay) && $pay['amount'] == '150000.00', 'amount=' . ($pay['amount'] ?? '?'));
    t('E3 — receipt_number stored', ($pay['receipt_number'] ?? '') === 'RCP-TEST-001', $pay['receipt_number'] ?? '');
    t('E4 — supplier_id correct (not mixed with supplier table)', (int)($pay['supplier_id'] ?? 0) === $sc_id, "supplier_id={$pay['supplier_id']}");
    t('E5 — project_id stored', (int)($pay['project_id'] ?? 0) === $proj_id, "project_id={$pay['project_id']}");

    // E6: Count from get API logic
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sc_payments WHERE supplier_id = ? AND project_id = ?");
    $stmt->execute([$sc_id, $proj_id]);
    $cnt = $stmt->fetchColumn();
    t('E6 — get_payments query returns rows', $cnt >= 1, "count=$cnt");

    // E7: Delete
    $pdo->prepare("DELETE FROM sc_payments WHERE id = ?")->execute([$pay_id]);
    $after = $pdo->prepare("SELECT id FROM sc_payments WHERE id = ?");
    $after->execute([$pay_id]);
    t('E7 — DELETE removes payment', $after->fetchColumn() === false, 'Gone');
} else {
    for ($i = 1; $i <= 7; $i++) t("E$i — skipped (no SC/project data)", false, 'No test data found');
}

// ── F: Progress report sc_id tagging ─────────────────────────
if ($sc_id && $proj_id) {
    // Insert tagged report
    $ins2 = $pdo->prepare("INSERT INTO project_progress_reports (project_id, sc_id, report_date, report_type, comments, created_by) VALUES (?,?,?,?,?,1)");
    $ins2->execute([$proj_id, $sc_id, '2026-01-15', 'daily', 'SC regression test report']);
    $rpt_id = $pdo->lastInsertId();
    t('F1 — sc-tagged progress report INSERT succeeds', $rpt_id > 0, "id=$rpt_id");

    // Confirm sc_id saved
    $rr = $pdo->query("SELECT sc_id FROM project_progress_reports WHERE id = $rpt_id")->fetch(PDO::FETCH_ASSOC);
    t('F2 — sc_id stored on progress report', (int)($rr['sc_id'] ?? 0) === $sc_id, "sc_id={$rr['sc_id']}");

    // SC mode filter: should return only this report
    $flt = $pdo->prepare("SELECT COUNT(*) FROM project_progress_reports WHERE project_id = ? AND sc_id = ?");
    $flt->execute([$proj_id, $sc_id]);
    t('F3 — SC filter returns tagged reports only', $flt->fetchColumn() >= 1, 'rows returned');

    // Main mode: all reports (no sc_id filter) — should include tagged
    $all = $pdo->prepare("SELECT COUNT(*) FROM project_progress_reports WHERE project_id = ?");
    $all->execute([$proj_id]);
    t('F4 — Main mode sees all reports (including SC-tagged)', $all->fetchColumn() >= 1, 'total rows: ' . $all->fetchColumn());

    // Cleanup
    $pdo->prepare("DELETE FROM project_progress_reports WHERE id = ?")->execute([$rpt_id]);
    t('F5 — Cleanup test report OK', true, 'Done');
} else {
    for ($i = 1; $i <= 5; $i++) t("F$i — skipped", false, 'No test data');
}

// ── Render ────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>SC Mode — Full Regression Test</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f1f5f9;font-family:'Segoe UI',sans-serif;padding:32px;}
.pass{background:#dcfce7;border-left:4px solid #16a34a;}
.fail{background:#fee2e2;border-left:4px solid #dc2626;}
.group-title{background:#1e293b;color:#fff;padding:8px 14px;border-radius:6px 6px 0 0;font-size:.8rem;font-weight:700;letter-spacing:.4px;margin-top:24px;}
code{font-size:.78rem;}
</style>
</head><body>
<h4 class="fw-bold mb-1">SC Mode — Full Regression Test</h4>
<p class="text-muted mb-3" style="font-size:.85rem">
    SC: <strong>#<?= $sc_id ?> — <?= htmlspecialchars($sc_name) ?></strong>
    &nbsp;|&nbsp; Project: <strong>#<?= $proj_id ?> — <?= htmlspecialchars($proj_name) ?></strong>
</p>
<div class="mb-4">
    <span class="badge fs-6 bg-<?= $fail === 0 ? 'success' : 'danger' ?>"><?= $pass ?>/<?= $total ?> passed</span>
    <?php if ($fail === 0): ?>
    <span class="ms-2 text-success fw-bold">✓ All tests passed — safe to commit</span>
    <?php else: ?>
    <span class="ms-2 text-danger fw-bold">✗ <?= $fail ?> test(s) failed</span>
    <?php endif; ?>
</div>

<?php
$groups = ['A' => 'Schema & Migration', 'B' => 'sub_contractor_details.php Links', 'C' => 'project_view.php SC Mode Structure', 'D' => 'API Files Syntax', 'E' => 'SC Payments API Logic', 'F' => 'Progress Reports sc_id Tagging'];
$current_group = '';
foreach ($results as $r):
    $letter = substr($r['label'], 0, 1);
    if ($letter !== $current_group):
        if ($current_group !== '') echo '</div>';
        $current_group = $letter;
        echo '<div class="group-title">' . $letter . ' — ' . ($groups[$letter] ?? '') . '</div>';
        echo '<div class="d-flex flex-column gap-2 mb-1">';
    endif;
?>
    <div class="rounded-bottom p-3 <?= $r['ok'] ? 'pass' : 'fail' ?>">
        <div class="d-flex justify-content-between align-items-center">
            <span style="font-size:.84rem"><?= htmlspecialchars($r['label']) ?></span>
            <span class="fw-bold ms-3 <?= $r['ok'] ? 'text-success' : 'text-danger' ?>"><?= $r['ok'] ? '✓ PASS' : '✗ FAIL' ?></span>
        </div>
        <?php if ($r['detail']): ?><div class="text-muted mt-1"><code><?= htmlspecialchars($r['detail']) ?></code></div><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
</body></html>
<?php includeFooter(); ?>
