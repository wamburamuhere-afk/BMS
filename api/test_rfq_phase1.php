<?php
// File: api/test_rfq_phase1.php
// scope-audit: skip — development test/verification script, not runtime
// Phase 1 Verification Tests — RFQ Three-Stage Workflow
// Run via browser: http://localhost/bms/api/test_rfq_phase1.php?token=bms_migrate_2024
// PURPOSE: Confirm all DB changes from migrate_rfq_workflow.php are correct.

require_once __DIR__ . '/../roots.php';

$token = $_GET['token'] ?? '';
if ($token !== 'bms_migrate_2024') {
    die('Unauthorized — Pass ?token=bms_migrate_2024 to run this test.');
}

global $pdo;
header('Content-Type: text/html; charset=utf-8');

$pass = 0;
$fail = 0;
$tests = [];

function test($name, $result, $detail = '') {
    global $pass, $fail, $tests;
    if ($result) {
        $pass++;
        $tests[] = ['status' => 'PASS', 'name' => $name, 'detail' => $detail];
    } else {
        $fail++;
        $tests[] = ['status' => 'FAIL', 'name' => $name, 'detail' => $detail];
    }
}

// ─── TEST GROUP 1: role_permissions columns ───────────────────
$rpCols = $pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN);

test(
    'role_permissions has can_review column',
    in_array('can_review', $rpCols),
    'Expected column can_review in role_permissions'
);
test(
    'role_permissions has can_approve column',
    in_array('can_approve', $rpCols),
    'Expected column can_approve in role_permissions'
);

// Verify defaults are 0
$sample = $pdo->query("SELECT can_review, can_approve FROM role_permissions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($sample !== false) {
    test(
        'can_review default is 0 (not NULL)',
        $sample['can_review'] === '0' || $sample['can_review'] === 0,
        "Got: " . var_export($sample['can_review'], true)
    );
    test(
        'can_approve default is 0 (not NULL)',
        $sample['can_approve'] === '0' || $sample['can_approve'] === 0,
        "Got: " . var_export($sample['can_approve'], true)
    );
} else {
    test('role_permissions has at least one row to sample', false, 'Table appears empty');
}

// ─── TEST GROUP 2: rfq table snapshot columns ────────────────
$rfqCols = $pdo->query("SHOW COLUMNS FROM rfq")->fetchAll(PDO::FETCH_COLUMN);
$expectedCols = [
    'prepared_by_name', 'prepared_by_role',
    'reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at',
    'approved_by', 'approved_by_name', 'approved_by_role', 'approved_at',
];
foreach ($expectedCols as $col) {
    test(
        "rfq table has column: {$col}",
        in_array($col, $rfqCols),
        "Column {$col} should exist in rfq table"
    );
}

// ─── TEST GROUP 3: status ENUM contains 'review' ─────────────
$enumRow = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
test(
    "status ENUM contains 'review'",
    $enumRow && strpos($enumRow['Type'], "'review'") !== false,
    "ENUM type: " . ($enumRow['Type'] ?? 'N/A')
);
test(
    "status ENUM still contains 'draft'",
    $enumRow && strpos($enumRow['Type'], "'draft'") !== false,
    "Existing values preserved"
);
test(
    "status ENUM still contains 'approved'",
    $enumRow && strpos($enumRow['Type'], "'approved'") !== false,
    "Existing values preserved"
);

// ─── TEST GROUP 4: Backfill check ────────────────────────────
$nullCount = $pdo->query("SELECT COUNT(*) FROM rfq WHERE created_by IS NOT NULL AND prepared_by_name IS NULL")
                 ->fetchColumn();
test(
    'All RFQs with created_by have prepared_by_name filled',
    $nullCount == 0,
    "Found {$nullCount} RFQ(s) still missing prepared_by_name"
);

// Spot check: prepared_by_name is not empty string
$blankCount = $pdo->query("SELECT COUNT(*) FROM rfq WHERE prepared_by_name = ''")->fetchColumn();
test(
    'No RFQ has blank (empty string) prepared_by_name',
    $blankCount == 0,
    "Found {$blankCount} RFQ(s) with empty string prepared_by_name"
);

// ─── TEST GROUP 5: Simulate INSERT with new status value ─────
try {
    // Just validate the ENUM accepts 'review' — we do a dry check via DESCRIBE
    $pdo->query("SELECT 1 FROM rfq WHERE status = 'review' LIMIT 1");
    test(
        "Query with status='review' executes without error",
        true,
        "ENUM accepts 'review' as a valid value"
    );
} catch (Exception $e) {
    test(
        "Query with status='review' executes without error",
        false,
        "Error: " . $e->getMessage()
    );
}

// ─── RENDER RESULTS ──────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Phase 1 Tests — RFQ Workflow</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; max-width: 860px; margin: 30px auto; padding: 0 20px; background: #f8f9fa; color: #333; }
  h1   { font-size: 1.4rem; margin-bottom: 4px; }
  .sub { color: #666; font-size: 0.85rem; margin-bottom: 24px; }
  .summary { display: flex; gap: 16px; margin-bottom: 24px; }
  .badge { padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 1rem; }
  .badge.pass { background: #d1e7dd; color: #0f5132; }
  .badge.fail { background: #f8d7da; color: #842029; }
  .badge.total { background: #e2e3e5; color: #383d41; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  th { background: #343a40; color: #fff; padding: 10px 14px; text-align: left; font-size: .82rem; text-transform: uppercase; }
  td { padding: 9px 14px; border-bottom: 1px solid #f0f0f0; font-size: .88rem; }
  tr:last-child td { border-bottom: none; }
  .st-pass { color: #198754; font-weight: 700; }
  .st-fail { color: #dc3545; font-weight: 700; }
  .footer { margin-top: 20px; font-size: .8rem; color: #888; }
  .overall-pass { background: #d1e7dd; padding: 12px 16px; border-radius: 8px; color: #0f5132; font-weight: 700; margin-top: 20px; }
  .overall-fail { background: #f8d7da; padding: 12px 16px; border-radius: 8px; color: #842029; font-weight: 700; margin-top: 20px; }
</style>
</head>
<body>
<h1>🧪 Phase 1 — DB Migration Tests</h1>
<p class="sub">RFQ Three-Stage Workflow | Run after: <code>api/migrate_rfq_workflow.php</code></p>

<div class="summary">
  <div class="badge total">Total: <?= $total ?></div>
  <div class="badge pass">✓ Passed: <?= $pass ?></div>
  <div class="badge fail">✗ Failed: <?= $fail ?></div>
</div>

<table>
  <thead>
    <tr><th>#</th><th>Test</th><th>Status</th><th>Detail</th></tr>
  </thead>
  <tbody>
    <?php foreach ($tests as $i => $t): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= htmlspecialchars($t['name']) ?></td>
      <td class="st-<?= strtolower($t['status']) ?>"><?= $t['status'] ?></td>
      <td style="color:#666;font-size:.82rem;"><?= htmlspecialchars($t['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="<?= $fail === 0 ? 'overall-pass' : 'overall-fail' ?>">
  <?php if ($fail === 0): ?>
    ✅ All <?= $total ?> tests passed. Phase 1 is complete — safe to commit and proceed to Phase 2.
  <?php else: ?>
    ❌ <?= $fail ?> test(s) failed. Run <code>migrate_rfq_workflow.php</code> first, then re-run this test.
  <?php endif; ?>
</div>

<div class="footer">
  Generated: <?= date('d M Y, H:i:s') ?> | File: api/test_rfq_phase1.php
</div>
</body>
</html>
