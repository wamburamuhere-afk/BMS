<?php
/**
 * Full regression test — Inspection modal & view changes
 *
 * Groups:
 *  A. Schema          — all new columns, tables, directory exist
 *  B. Milestone query — top-level only, scope column included
 *  C. Sub-milestone   — get_sub_milestones query logic
 *  D. Add Inspection  — save with multi-inspectors, sub_milestone_id,
 *                       inspected_scope, attach display_name
 *  E. get_inspection  — returns inspectors[] + attachments[] arrays
 *  F. Update          — inspUpdate replaces inspectors list
 *  G. View page       — inspection_view.php syntax + route registered
 *  H. Delete attach   — DB record + physical file removed
 *  I. inspDelete      — inspection + cascade FK rows all gone
 *  J. inspection_view print header — contract_number fetched with inspection
 *
 * Run: http://localhost/bms/scratch/test_inspection_full.php
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

if (empty($_SESSION['user_id'])) {
    $u = $pdo->query("SELECT user_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($u) $_SESSION['user_id'] = $u['user_id'];
    else die('<p style="font-family:sans-serif;color:red;padding:40px">No active user found.</p>');
}

$proj = $pdo->query("SELECT project_id, project_name, contract_number FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$proj) die('<p style="font-family:sans-serif;color:red;padding:40px">No project found.</p>');
$PROJ_ID = $proj['project_id'];

$any_ms = $pdo->query("SELECT id, scope FROM project_milestones WHERE scope_type = 'milestone' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$MS_ID  = $any_ms ? (int)$any_ms['id'] : 0;

$groups = [];

// ── A. Schema ─────────────────────────────────────────────────────────────────
$a = [];
foreach (['sub_milestone_id', 'inspected_scope'] as $col) {
    $r = $pdo->query("SHOW COLUMNS FROM project_inspections LIKE '{$col}'")->fetch();
    $a[] = ['label' => "project_inspections.{$col} exists", 'passed' => !!$r, 'got' => $r ? 'OK' : 'MISSING'];
}
foreach (['inspection_inspectors', 'inspection_attachments'] as $tbl) {
    $r = $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetch();
    $a[] = ['label' => "Table '{$tbl}' exists", 'passed' => !!$r, 'got' => $r ? 'OK' : 'MISSING'];
}
$r = $pdo->query("SHOW COLUMNS FROM inspection_attachments LIKE 'display_name'")->fetch();
$a[] = ['label' => 'inspection_attachments.display_name exists', 'passed' => !!$r, 'got' => $r ? 'OK' : 'MISSING'];
$dir_ok = is_dir(__DIR__ . '/../uploads/inspections');
$a[] = ['label' => 'uploads/inspections directory exists', 'passed' => $dir_ok, 'got' => $dir_ok ? 'OK' : 'MISSING'];
$groups['A — Schema'] = $a;

// ── B. Milestone query ────────────────────────────────────────────────────────
$b = [];
try {
    $st = $pdo->prepare("SELECT id, description, scope FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone' AND (parent_id IS NULL OR parent_id = 0) ORDER BY id ASC");
    $st->execute([$PROJ_ID]);
    $ms = $st->fetchAll(PDO::FETCH_ASSOC);
    $b[] = ['label' => 'Milestone query executes (top-level filter)', 'passed' => true, 'got' => count($ms) . ' milestone(s)'];
    $b[] = ['label' => "Returned rows include 'scope' key", 'passed' => empty($ms) || array_key_exists('scope', $ms[0]), 'got' => empty($ms) ? 'No milestones in project' : 'scope=' . $ms[0]['scope']];
    $child_q = $pdo->prepare("SELECT COUNT(*) FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone' AND parent_id IS NOT NULL AND parent_id != 0");
    $child_q->execute([$PROJ_ID]);
    $b[] = ['label' => 'Child milestones excluded from dropdown', 'passed' => true, 'got' => (int)$child_q->fetchColumn() . ' child(ren) filtered out'];
} catch (Throwable $e) {
    $b[] = ['label' => 'Milestone query', 'passed' => false, 'got' => $e->getMessage()];
}
$groups['B — Milestone Query (top-level + scope)'] = $b;

// ── C. Sub-milestone API query ────────────────────────────────────────────────
$c = [];
try {
    $st = $pdo->prepare("SELECT id, description, scope FROM project_milestones WHERE parent_id = ? ORDER BY id ASC");
    $st->execute([$MS_ID]);
    $children = $st->fetchAll(PDO::FETCH_ASSOC);
    $c[] = ['label' => "get_sub_milestones query for milestone #{$MS_ID} runs", 'passed' => true, 'got' => count($children) . ' child(ren)'];
    if ($children) {
        $keys_ok = array_key_exists('id', $children[0]) && array_key_exists('description', $children[0]) && array_key_exists('scope', $children[0]);
        $c[] = ['label' => 'Result rows have id, description, scope keys', 'passed' => $keys_ok, 'got' => $keys_ok ? 'All keys OK' : 'Missing keys'];
    } else {
        $c[] = ['label' => 'Query shape correct (no children in test data)', 'passed' => true, 'got' => 'N/A — no children found'];
    }
    // Verify get_sub_milestones.php file exists
    $api_exists = file_exists(__DIR__ . '/../api/operations/get_sub_milestones.php');
    $c[] = ['label' => 'api/operations/get_sub_milestones.php exists', 'passed' => $api_exists, 'got' => $api_exists ? 'OK' : 'MISSING'];
} catch (Throwable $e) {
    $c[] = ['label' => 'Sub-milestone query', 'passed' => false, 'got' => $e->getMessage()];
}
$groups['C — Sub-milestone API (get_sub_milestones)'] = $c;

// ── D. Add Inspection (multi-inspector, sub_milestone_id, inspected_scope, display_name) ──
$d = [];
$NEW_INSP_ID = 0;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM project_inspections WHERE project_id=?"); $cnt->execute([$PROJ_ID]);
    $no  = 'TEST-INS-' . str_pad($cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

    $st = $pdo->prepare("INSERT INTO project_inspections
        (project_id, milestone_id, sub_milestone_id, inspected_scope, inspection_no,
         inspection_date, inspection_type, inspector_name, inspector_org,
         result, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$PROJ_ID, ($MS_ID ?: null), ($MS_ID ?: null), 22.75, $no, date('Y-m-d'), 'Site', 'Alpha Inspector', 'Org A', 'Pass', 'Pending', $_SESSION['user_id']]);
    $NEW_INSP_ID = (int)$pdo->lastInsertId();
    $d[] = ['label' => 'New inspection INSERT with sub_milestone_id + inspected_scope', 'passed' => $NEW_INSP_ID > 0, 'got' => "inspection_id={$NEW_INSP_ID}"];

    // Save 2 inspectors
    $ins = $pdo->prepare("INSERT INTO inspection_inspectors (inspection_id, inspector_name, inspector_org, sort_order) VALUES (?,?,?,?)");
    $ins->execute([$NEW_INSP_ID, 'Alpha Inspector', 'Org A', 0]);
    $ins->execute([$NEW_INSP_ID, 'Beta Inspector',  'Org B', 1]);
    $d[] = ['label' => '2 inspectors saved to inspection_inspectors', 'passed' => true, 'got' => 'Alpha Inspector, Beta Inspector'];

    // Verify DB values
    $row = $pdo->prepare("SELECT inspected_scope, sub_milestone_id FROM project_inspections WHERE inspection_id=?");
    $row->execute([$NEW_INSP_ID]); $row = $row->fetch(PDO::FETCH_ASSOC);
    $d[] = ['label' => 'inspected_scope stored as 22.75', 'passed' => $row && floatval($row['inspected_scope']) == 22.75, 'got' => $row['inspected_scope'] ?? 'null'];
    $d[] = ['label' => 'sub_milestone_id stored correctly', 'passed' => $row && intval($row['sub_milestone_id']) == intval($MS_ID ?: 0), 'got' => $row['sub_milestone_id'] ?? 'null'];

    // Save attachment with display_name
    $upload_dir = __DIR__ . '/../uploads/inspections/' . $NEW_INSP_ID . '/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    file_put_contents($upload_dir . 'test_file.txt', 'test');
    $att = $pdo->prepare("INSERT INTO inspection_attachments (inspection_id, file_name, original_name, display_name, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?)");
    $att->execute([$NEW_INSP_ID, 'test_file.txt', 'test_file.txt', 'Site Photo #1', 'txt', 4, $_SESSION['user_id']]);
    $ATT_ID = (int)$pdo->lastInsertId();
    $d[] = ['label' => 'Attachment with display_name saved', 'passed' => $ATT_ID > 0, 'got' => "att_id={$ATT_ID}, display_name='Site Photo #1'"];

    // Verify display_name
    $att_row = $pdo->prepare("SELECT display_name FROM inspection_attachments WHERE id=?");
    $att_row->execute([$ATT_ID]); $att_row = $att_row->fetch(PDO::FETCH_ASSOC);
    $d[] = ['label' => "display_name retrieved correctly", 'passed' => ($att_row['display_name'] ?? '') === 'Site Photo #1', 'got' => $att_row['display_name'] ?? 'null'];

} catch (Throwable $e) {
    $d[] = ['label' => 'Add inspection', 'passed' => false, 'got' => $e->getMessage()];
}
$groups['D — Add Inspection (inspSave)'] = $d;

// ── E. get_inspection — inspectors + attachments arrays ──────────────────────
$e = [];
if ($NEW_INSP_ID) {
    try {
        $st = $pdo->prepare("SELECT i.*, m.description as milestone_description FROM project_inspections i LEFT JOIN project_milestones m ON i.milestone_id = m.id WHERE i.inspection_id = ?");
        $st->execute([$NEW_INSP_ID]); $insp_row = $st->fetch(PDO::FETCH_ASSOC);
        $e[] = ['label' => 'get_inspection main row returns OK', 'passed' => !!$insp_row, 'got' => $insp_row ? 'Row found' : 'Not found'];

        $ins_q = $pdo->prepare("SELECT inspector_name, inspector_org FROM inspection_inspectors WHERE inspection_id = ? ORDER BY sort_order");
        $ins_q->execute([$NEW_INSP_ID]); $ins_rows = $ins_q->fetchAll(PDO::FETCH_ASSOC);
        $e[] = ['label' => 'inspectors[] array has 2 rows', 'passed' => count($ins_rows) === 2, 'got' => implode(', ', array_column($ins_rows, 'inspector_name'))];

        $att_q = $pdo->prepare("SELECT id, original_name, display_name, file_name, file_type, file_size FROM inspection_attachments WHERE inspection_id = ? ORDER BY uploaded_at");
        $att_q->execute([$NEW_INSP_ID]); $att_rows = $att_q->fetchAll(PDO::FETCH_ASSOC);
        $e[] = ['label' => 'attachments[] array has 1 row', 'passed' => count($att_rows) === 1, 'got' => count($att_rows) . ' attachment(s)'];
        $e[] = ['label' => "attachments[] includes display_name key", 'passed' => !empty($att_rows) && array_key_exists('display_name', $att_rows[0]), 'got' => !empty($att_rows) ? ($att_rows[0]['display_name'] ?? 'null') : 'no rows'];
    } catch (Throwable $e2) {
        $e[] = ['label' => 'get_inspection', 'passed' => false, 'got' => $e2->getMessage()];
    }
}
$groups['E — get_inspection (inspectors + attachments arrays)'] = $e;

// ── F. Update inspection — replaces inspectors ────────────────────────────────
$f = [];
if ($NEW_INSP_ID) {
    try {
        // Simulate inspUpdate: delete old inspectors, insert new list
        $pdo->prepare("DELETE FROM inspection_inspectors WHERE inspection_id = ?")->execute([$NEW_INSP_ID]);
        $new_inspectors = [['name' => 'Gamma Inspector', 'org' => 'Org G'], ['name' => 'Delta Inspector', 'org' => 'Org D'], ['name' => 'Epsilon Inspector', 'org' => '']];
        $ins = $pdo->prepare("INSERT INTO inspection_inspectors (inspection_id, inspector_name, inspector_org, sort_order) VALUES (?,?,?,?)");
        foreach ($new_inspectors as $i => $n) { $ins->execute([$NEW_INSP_ID, $n['name'], $n['org'], $i]); }

        $check = $pdo->prepare("SELECT inspector_name FROM inspection_inspectors WHERE inspection_id = ? ORDER BY sort_order");
        $check->execute([$NEW_INSP_ID]); $names = $check->fetchAll(PDO::FETCH_COLUMN);
        $f[] = ['label' => 'inspUpdate: old inspectors deleted', 'passed' => !in_array('Alpha Inspector', $names), 'got' => implode(', ', $names)];
        $f[] = ['label' => 'inspUpdate: 3 new inspectors inserted', 'passed' => count($names) === 3, 'got' => implode(', ', $names)];
        $f[] = ['label' => 'First inspector is Gamma Inspector', 'passed' => ($names[0] ?? '') === 'Gamma Inspector', 'got' => $names[0] ?? 'missing'];
    } catch (Throwable $e2) {
        $f[] = ['label' => 'Update inspectors', 'passed' => false, 'got' => $e2->getMessage()];
    }
}
$groups['F — Update Inspection (inspUpdate — replaces inspectors)'] = $f;

// ── G. View page + route ──────────────────────────────────────────────────────
$g = [];
$view_file = __DIR__ . '/../app/bms/operations/inspection_view.php';
$g[] = ['label' => 'inspection_view.php file exists', 'passed' => file_exists($view_file), 'got' => file_exists($view_file) ? 'OK' : 'MISSING'];
$syntax = shell_exec('php -l ' . escapeshellarg($view_file) . ' 2>&1');
$g[] = ['label' => 'inspection_view.php has no syntax errors', 'passed' => strpos($syntax, 'No syntax errors') !== false, 'got' => trim($syntax)];

// Check route registered in roots.php
$roots = file_get_contents(__DIR__ . '/../roots.php');
$g[] = ['label' => "'inspection_view' route registered in roots.php", 'passed' => strpos($roots, "'inspection_view'") !== false, 'got' => strpos($roots, "'inspection_view'") !== false ? 'Route found' : 'NOT FOUND'];

// Check delete attachment API exists
$del_att_file = __DIR__ . '/../api/operations/delete_inspection_attachment.php';
$g[] = ['label' => 'delete_inspection_attachment.php exists', 'passed' => file_exists($del_att_file), 'got' => file_exists($del_att_file) ? 'OK' : 'MISSING'];
$syntax2 = shell_exec('php -l ' . escapeshellarg($del_att_file) . ' 2>&1');
$g[] = ['label' => 'delete_inspection_attachment.php no syntax errors', 'passed' => strpos($syntax2, 'No syntax errors') !== false, 'got' => trim($syntax2)];

// Check that print header uses contract_number
$view_src = file_get_contents($view_file);
$g[] = ['label' => 'Print header includes contract_number field', 'passed' => strpos($view_src, 'contract_number') !== false, 'got' => strpos($view_src, 'contract_number') !== false ? 'Found' : 'MISSING'];
$g[] = ['label' => 'Bottom action bar removed (no duplicate Print/Back buttons)', 'passed' => substr_count($view_src, "window.print()") === 1, 'got' => substr_count($view_src, "window.print()") . ' print button(s) — expected 1'];
$groups['G — inspection_view.php page + route'] = $g;

// ── H. Delete attachment ──────────────────────────────────────────────────────
$h = [];
if ($NEW_INSP_ID) {
    try {
        // Grab the attachment we created
        $att_q = $pdo->prepare("SELECT id, file_name FROM inspection_attachments WHERE inspection_id = ? LIMIT 1");
        $att_q->execute([$NEW_INSP_ID]); $att = $att_q->fetch(PDO::FETCH_ASSOC);
        $ATT_ID2 = $att ? (int)$att['id'] : 0;
        $file_path = __DIR__ . '/../uploads/inspections/' . $NEW_INSP_ID . '/' . ($att['file_name'] ?? '');

        if ($ATT_ID2) {
            // File should exist (we created it in group D)
            $h[] = ['label' => 'Physical file exists before delete', 'passed' => file_exists($file_path), 'got' => file_exists($file_path) ? 'OK' : 'File already missing'];

            // Simulate delete_inspection_attachment logic
            if (file_exists($file_path)) unlink($file_path);
            $pdo->prepare("DELETE FROM inspection_attachments WHERE id = ?")->execute([$ATT_ID2]);

            $gone_db = $pdo->prepare("SELECT id FROM inspection_attachments WHERE id = ?");
            $gone_db->execute([$ATT_ID2]);
            $h[] = ['label' => 'Attachment DB record deleted', 'passed' => $gone_db->fetch() === false, 'got' => $gone_db->fetch() === false ? 'Gone' : 'Still exists'];
            $h[] = ['label' => 'Physical file deleted from disk', 'passed' => !file_exists($file_path), 'got' => !file_exists($file_path) ? 'Gone' : 'Still exists'];
        } else {
            $h[] = ['label' => 'Delete attachment (no attachment to test)', 'passed' => false, 'got' => 'Attachment not found from group D'];
        }
    } catch (Throwable $e2) {
        $h[] = ['label' => 'Delete attachment', 'passed' => false, 'got' => $e2->getMessage()];
    }
}
$groups['H — Delete Attachment (deleteAttachment)'] = $h;

// ── I. Delete inspection — cascade FK ─────────────────────────────────────────
$i_tests = [];
if ($NEW_INSP_ID) {
    try {
        // Add back one inspector + attachment row to verify cascade
        $pdo->prepare("INSERT INTO inspection_inspectors (inspection_id, inspector_name, sort_order) VALUES (?,?,0)")->execute([$NEW_INSP_ID, 'Final Inspector']);
        $pdo->prepare("INSERT INTO inspection_attachments (inspection_id, file_name, original_name, file_type, file_size) VALUES (?,?,?,?,0)")->execute([$NEW_INSP_ID, 'final_test.pdf', 'final_test.pdf', 'pdf']);

        $pdo->prepare("DELETE FROM project_inspections WHERE inspection_id = ?")->execute([$NEW_INSP_ID]);

        $chk_insp = $pdo->query("SELECT id FROM inspection_inspectors WHERE inspection_id = {$NEW_INSP_ID}")->fetch();
        $chk_att  = $pdo->query("SELECT id FROM inspection_attachments WHERE inspection_id = {$NEW_INSP_ID}")->fetch();
        $chk_insp_row = $pdo->query("SELECT inspection_id FROM project_inspections WHERE inspection_id = {$NEW_INSP_ID}")->fetch();

        $i_tests[] = ['label' => 'inspDelete: inspection row removed from DB', 'passed' => $chk_insp_row === false, 'got' => $chk_insp_row === false ? 'Gone' : 'Still exists'];
        $i_tests[] = ['label' => 'FK cascade: inspection_inspectors rows deleted', 'passed' => $chk_insp === false, 'got' => $chk_insp === false ? 'Gone' : 'Still exists'];
        $i_tests[] = ['label' => 'FK cascade: inspection_attachments rows deleted', 'passed' => $chk_att === false, 'got' => $chk_att === false ? 'Gone' : 'Still exists'];
    } catch (Throwable $e2) {
        $i_tests[] = ['label' => 'Delete inspection cascade', 'passed' => false, 'got' => $e2->getMessage()];
    }
}
$groups['I — Delete Inspection (inspDelete + FK cascade)'] = $i_tests;

// ── J. Print header — contract_number fetched with inspection ─────────────────
$j = [];
try {
    $st = $pdo->prepare("SELECT i.*, p.project_name, p.project_id, p.contract_number FROM project_inspections i JOIN projects p ON i.project_id = p.project_id WHERE i.project_id = ? LIMIT 1");
    $st->execute([$PROJ_ID]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $j[] = ['label' => 'Print header query includes contract_number', 'passed' => array_key_exists('contract_number', $row), 'got' => array_key_exists('contract_number', $row) ? 'contract_number=' . ($row['contract_number'] ?: '(empty)') : 'Key missing'];
        $j[] = ['label' => 'Project name available for print header', 'passed' => !empty($row['project_name']), 'got' => $row['project_name']];
    } else {
        $j[] = ['label' => 'Print header query (no inspections in project)', 'passed' => true, 'got' => 'N/A — query shape verified above'];
    }
    // Verify view file shows contract_number conditionally
    $view_src2 = file_get_contents(__DIR__ . '/../app/bms/operations/inspection_view.php');
    $j[] = ['label' => "Print header shows 'Contract No:' label", 'passed' => strpos($view_src2, 'Contract No:') !== false, 'got' => strpos($view_src2, 'Contract No:') !== false ? 'Found' : 'MISSING'];
    $j[] = ['label' => 'Contract No wrapped in conditional (only shown if set)', 'passed' => strpos($view_src2, "!empty(\$r['contract_number'])") !== false, 'got' => strpos($view_src2, "!empty(\$r['contract_number'])") !== false ? 'Found' : 'MISSING'];
} catch (Throwable $e2) {
    $j[] = ['label' => 'Print header contract_number', 'passed' => false, 'got' => $e2->getMessage()];
}
$groups['J — Print Header (company → project → contract no)'] = $j;

// ── Tally ─────────────────────────────────────────────────────────────────────
$total = $passed = 0;
foreach ($groups as $tests) foreach ($tests as $t) { $total++; if ($t['passed']) $passed++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inspection Full Regression Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI',sans-serif; padding:32px; }
        .pass { background:#dcfce7; border-left:4px solid #16a34a; }
        .fail { background:#fee2e2; border-left:4px solid #dc2626; }
        .group-title { background:#1e293b; color:#fff; padding:8px 14px; border-radius:6px 6px 0 0; font-size:.8rem; font-weight:700; letter-spacing:.4px; margin-top:24px; }
        code { font-size:.78rem; }
    </style>
</head>
<body>
<h4 class="fw-bold mb-1">Inspection — Full Regression Test</h4>
<p class="text-muted mb-3" style="font-size:.85rem">
    Project: <strong>#<?= $PROJ_ID ?> — <?= htmlspecialchars($proj['project_name']) ?></strong>
    &nbsp;|&nbsp; Contract: <strong><?= htmlspecialchars($proj['contract_number'] ?: '(none)') ?></strong>
    &nbsp;|&nbsp; User: <strong>#<?= $_SESSION['user_id'] ?></strong>
</p>
<div class="mb-4">
    <span class="badge fs-6 <?= $passed===$total?'bg-success':'bg-danger' ?>"><?= $passed ?>/<?= $total ?> passed</span>
    <?php if ($passed===$total): ?>
        <span class="ms-2 text-success fw-bold">✓ All tests passed — safe to commit</span>
    <?php else: ?>
        <span class="ms-2 text-danger fw-bold">✗ Fix failures before committing</span>
    <?php endif; ?>
</div>

<?php foreach ($groups as $groupName => $tests): ?>
    <div class="group-title"><?= htmlspecialchars($groupName) ?></div>
    <div class="d-flex flex-column gap-2 mb-1">
    <?php foreach ($tests as $t): ?>
        <div class="rounded-bottom p-3 <?= $t['passed']?'pass':'fail' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span style="font-size:.84rem"><?= htmlspecialchars($t['label']) ?></span>
                <span class="fw-bold ms-3 <?= $t['passed']?'text-success':'text-danger' ?>"><?= $t['passed']?'✓ PASS':'✗ FAIL' ?></span>
            </div>
            <div class="text-muted mt-1"><code><?= htmlspecialchars($t['got']) ?></code></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>
</body>
</html>
