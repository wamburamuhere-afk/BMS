<?php
// File: api/test_rfq_phase3.php
// scope-audit: skip — development test/verification script, not runtime
// Phase 3 Verification Tests — review_rfq.php, approve_rfq.php, create_rfq.php
// Run via browser: http://localhost/bms/api/test_rfq_phase3.php?token=bms_migrate_2024

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

$token = $_GET['token'] ?? '';
if ($token !== 'bms_migrate_2024') {
    die('Unauthorized — Pass ?token=bms_migrate_2024 to run this test.');
}

global $pdo;
header('Content-Type: text/html; charset=utf-8');

$pass = 0; $fail = 0; $tests = [];

function test($name, $result, $detail = '') {
    global $pass, $fail, $tests;
    if ($result) { $pass++; $tests[] = ['status'=>'PASS','name'=>$name,'detail'=>$detail]; }
    else         { $fail++; $tests[] = ['status'=>'FAIL','name'=>$name,'detail'=>$detail]; }
}

// ─── TEST GROUP 1: File & Route existence ────────────────────
test('review_rfq.php file exists',  file_exists(__DIR__ . '/review_rfq.php'),  'api/review_rfq.php must exist');
test('approve_rfq.php file exists', file_exists(__DIR__ . '/approve_rfq.php'), 'api/approve_rfq.php must exist');
test('create_rfq.php file exists',  file_exists(__DIR__ . '/create_rfq.php'),  'api/create_rfq.php must exist');

// Route registration in roots.php
$rootsContent = file_get_contents(__DIR__ . '/../roots.php');
test('roots.php registers api/review_rfq',  strpos($rootsContent, "'api/review_rfq'")  !== false, 'Route must be in roots.php routing table');
test('roots.php registers api/approve_rfq', strpos($rootsContent, "'api/approve_rfq'") !== false, 'Route must be in roots.php routing table');

// ─── TEST GROUP 2: review_rfq.php content checks ─────────────
$reviewContent = file_get_contents(__DIR__ . '/review_rfq.php');
test("review_rfq checks canReview()",         strpos($reviewContent, 'canReview') !== false,         'Must enforce permission');
test("review_rfq enforces status = 'draft'",  strpos($reviewContent, "'draft'") !== false,           'Must check current status is draft');
test("review_rfq saves reviewed_by_name",     strpos($reviewContent, 'reviewed_by_name') !== false,  'Must save reviewer name snapshot');
test("review_rfq saves reviewed_by_role",     strpos($reviewContent, 'reviewed_by_role') !== false,  'Must save reviewer role snapshot');
test("review_rfq saves reviewed_at",          strpos($reviewContent, 'reviewed_at') !== false,       'Must save timestamp');
test("review_rfq sets status to 'review'",    strpos($reviewContent, "'review'") !== false,          'Must update status to review');

// ─── TEST GROUP 3: approve_rfq.php content checks ────────────
$approveContent = file_get_contents(__DIR__ . '/approve_rfq.php');
test("approve_rfq checks canApprove()",        strpos($approveContent, 'canApprove') !== false,        'Must enforce permission');
test("approve_rfq enforces status = 'review'", strpos($approveContent, "'review'") !== false,          'Must check current status is review (sequential)');
test("approve_rfq saves approved_by_name",     strpos($approveContent, 'approved_by_name') !== false,  'Must save approver name snapshot');
test("approve_rfq saves approved_by_role",     strpos($approveContent, 'approved_by_role') !== false,  'Must save approver role snapshot');
test("approve_rfq saves approved_at",          strpos($approveContent, 'approved_at') !== false,       'Must save timestamp');
test("approve_rfq sets status to 'approved'",  strpos($approveContent, "'approved'") !== false,        'Must update to approved');

// ─── TEST GROUP 4: create_rfq.php content checks ─────────────
$createContent = file_get_contents(__DIR__ . '/create_rfq.php');
test("create_rfq saves prepared_by_name", strpos($createContent, 'prepared_by_name') !== false, 'Must save creator name at creation time');
test("create_rfq saves prepared_by_role", strpos($createContent, 'prepared_by_role') !== false, 'Must save creator role at creation time');

// ─── TEST GROUP 5: DB status ENUM has 'review' ───────────────
try {
    $enumRow = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    test("rfq.status ENUM contains 'review'",   $enumRow && strpos($enumRow['Type'], "'review'")   !== false, $enumRow['Type'] ?? 'N/A');
    test("rfq.status ENUM contains 'approved'", $enumRow && strpos($enumRow['Type'], "'approved'") !== false, 'Existing value preserved');
    test("rfq.status ENUM contains 'draft'",    $enumRow && strpos($enumRow['Type'], "'draft'")    !== false, 'Existing value preserved');
} catch (Exception $e) {
    test('DB status ENUM readable', false, $e->getMessage());
}

// ─── TEST GROUP 6: Snapshot DB columns exist ─────────────────
try {
    $rfqCols = $pdo->query("SHOW COLUMNS FROM rfq")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['prepared_by_name','prepared_by_role',
              'reviewed_by','reviewed_by_name','reviewed_by_role','reviewed_at',
              'approved_by','approved_by_name','approved_by_role','approved_at'] as $col) {
        test("rfq.{$col} exists", in_array($col, $rfqCols), "Required by Phase 1 migration");
    }
} catch (Exception $e) {
    test('rfq snapshot columns readable', false, $e->getMessage());
}

// ─── TEST GROUP 7: Sequential logic simulation ────────────────
// Test that review only works on draft, approve only works on review
// We test the file content logic — not actual DB calls
test(
    "approve_rfq rejects draft status (sequential check in code)",
    strpos($approveContent, "'draft'") !== false && strpos($approveContent, 'must be reviewed') !== false,
    'approve_rfq must reject draft status with clear message'
);

// ─── RENDER ──────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Phase 3 Tests — API Endpoints</title>
<style>
  body{font-family:'Segoe UI',sans-serif;max-width:950px;margin:30px auto;padding:0 20px;background:#f8f9fa;color:#333}
  h1{font-size:1.4rem;margin-bottom:4px}
  .sub{color:#666;font-size:.85rem;margin-bottom:24px}
  .summary{display:flex;gap:16px;margin-bottom:24px}
  .badge{padding:10px 20px;border-radius:8px;font-weight:700;font-size:1rem}
  .badge.pass{background:#d1e7dd;color:#0f5132}
  .badge.fail{background:#f8d7da;color:#842029}
  .badge.total{background:#e2e3e5;color:#383d41}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
  th{background:#343a40;color:#fff;padding:10px 14px;text-align:left;font-size:.82rem;text-transform:uppercase}
  td{padding:9px 14px;border-bottom:1px solid #f0f0f0;font-size:.88rem}
  tr:last-child td{border-bottom:none}
  .st-pass{color:#198754;font-weight:700}
  .st-fail{color:#dc3545;font-weight:700}
  .footer{margin-top:20px;font-size:.8rem;color:#888}
  .overall-pass{background:#d1e7dd;padding:12px 16px;border-radius:8px;color:#0f5132;font-weight:700;margin-top:20px}
  .overall-fail{background:#f8d7da;padding:12px 16px;border-radius:8px;color:#842029;font-weight:700;margin-top:20px}
</style>
</head>
<body>
<h1>🧪 Phase 3 — API Endpoint Tests</h1>
<p class="sub">RFQ Three-Stage Workflow | Tests for: <code>api/review_rfq.php</code>, <code>api/approve_rfq.php</code>, <code>api/create_rfq.php</code></p>

<div class="summary">
  <div class="badge total">Total: <?= $total ?></div>
  <div class="badge pass">✓ Passed: <?= $pass ?></div>
  <div class="badge fail">✗ Failed: <?= $fail ?></div>
</div>

<table>
  <thead><tr><th>#</th><th>Test</th><th>Status</th><th>Detail</th></tr></thead>
  <tbody>
    <?php foreach ($tests as $i => $t): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= htmlspecialchars($t['name']) ?></td>
      <td class="st-<?= strtolower($t['status']) ?>"><?= $t['status'] ?></td>
      <td style="color:#666;font-size:.82rem;"><?= htmlspecialchars($t['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="<?= $fail===0 ? 'overall-pass' : 'overall-fail' ?>">
  <?php if ($fail===0): ?>
    ✅ All <?= $total ?> tests passed. Phase 3 complete — safe to commit and proceed to Phase 4.
  <?php else: ?>
    ❌ <?= $fail ?> test(s) failed. Review details above.
  <?php endif; ?>
</div>

<div class="footer">Generated: <?= date('d M Y, H:i:s') ?> | File: api/test_rfq_phase3.php</div>
</body>
</html>
