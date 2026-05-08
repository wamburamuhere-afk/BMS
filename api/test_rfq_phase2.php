<?php
// File: api/test_rfq_phase2.php
// Phase 2 Verification Tests — Permission Helpers (canReview / canApprove)
// Run via browser: http://localhost/bms/api/test_rfq_phase2.php?token=bms_migrate_2024
// PURPOSE: Confirm permissions.php was updated correctly.

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
    if ($result) { $pass++; $tests[] = ['status'=>'PASS','name'=>$name,'detail'=>$detail]; }
    else         { $fail++; $tests[] = ['status'=>'FAIL','name'=>$name,'detail'=>$detail]; }
}

// ─── TEST GROUP 1: Function existence ────────────────────────
test('canReview() function exists',  function_exists('canReview'),  'Should be defined in core/permissions.php');
test('canApprove() function exists', function_exists('canApprove'), 'Should be defined in core/permissions.php');
test('canView() function still exists',   function_exists('canView'),   'Existing functions must not be broken');
test('canCreate() function still exists', function_exists('canCreate'), 'Existing functions must not be broken');
test('canEdit() function still exists',   function_exists('canEdit'),   'Existing functions must not be broken');
test('canDelete() function still exists', function_exists('canDelete'), 'Existing functions must not be broken');

// ─── TEST GROUP 2: Admin bypass ───────────────────────────────
// Admin (role_id=1) must always return true regardless of DB rows
$savedRoleId = $_SESSION['role_id'] ?? null;
$_SESSION['role_id'] = 1; // Simulate admin

test('canReview() returns true for admin',  canReview('rfq'),  'Admin must bypass all permission checks');
test('canApprove() returns true for admin', canApprove('rfq'), 'Admin must bypass all permission checks');
test('canView() returns true for admin',    canView('rfq'),    'Regression: admin bypass still works');

$_SESSION['role_id'] = $savedRoleId; // Restore

// ─── TEST GROUP 3: Non-admin with no permissions ──────────────
$savedRoleId = $_SESSION['role_id'];
$savedPerms  = $_SESSION['permissions'] ?? [];

// Simulate a non-admin user with no review/approve permissions
$_SESSION['role_id']  = 99; // fake non-admin role
$_SESSION['permissions'] = [
    'rfq' => [
        'view'    => true,
        'create'  => true,
        'edit'    => false,
        'delete'  => false,
        'review'  => false,
        'approve' => false,
    ]
];

test(
    'canReview() returns false when review=false in session',
    canReview('rfq') === false,
    'Non-admin with review=false should be blocked'
);
test(
    'canApprove() returns false when approve=false in session',
    canApprove('rfq') === false,
    'Non-admin with approve=false should be blocked'
);
test(
    'canView() still returns true when view=true in session',
    canView('rfq') === true,
    'Existing permissions must still work correctly'
);

// ─── TEST GROUP 4: Non-admin WITH permissions ─────────────────
$_SESSION['permissions']['rfq']['review']  = true;
$_SESSION['permissions']['rfq']['approve'] = true;

test(
    'canReview() returns true when review=true in session',
    canReview('rfq') === true,
    'Non-admin with review=true should be allowed'
);
test(
    'canApprove() returns true when approve=true in session',
    canApprove('rfq') === true,
    'Non-admin with approve=true should be allowed'
);

// Restore session
$_SESSION['role_id']    = $savedRoleId;
$_SESSION['permissions'] = $savedPerms;

// ─── TEST GROUP 5: loadUserPermissions includes review/approve ─
test(
    'Session permissions loaded for current user includes review key',
    array_key_exists('review', (array)($_SESSION['permissions'][array_key_first($_SESSION['permissions'] ?? ['x'=>[]])] ?? [])),
    'loadUserPermissions() must store review key in session after Phase 1 migration'
);
test(
    'Session permissions loaded for current user includes approve key',
    array_key_exists('approve', (array)($_SESSION['permissions'][array_key_first($_SESSION['permissions'] ?? ['x'=>[]])] ?? [])),
    'loadUserPermissions() must store approve key in session after Phase 1 migration'
);

// ─── TEST GROUP 6: DB column check (quick cross-verify) ───────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN);
    test('role_permissions.can_review exists (cross-check)',  in_array('can_review',  $cols), 'Phase 1 dependency');
    test('role_permissions.can_approve exists (cross-check)', in_array('can_approve', $cols), 'Phase 1 dependency');
} catch (Exception $e) {
    test('role_permissions columns readable', false, $e->getMessage());
}

// ─── RENDER ───────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Phase 2 Tests — Permission Helpers</title>
<style>
  body{font-family:'Segoe UI',sans-serif;max-width:900px;margin:30px auto;padding:0 20px;background:#f8f9fa;color:#333}
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
<h1>🧪 Phase 2 — Permission Helper Tests</h1>
<p class="sub">RFQ Three-Stage Workflow | Tests for: <code>core/permissions.php</code></p>

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
    ✅ All <?= $total ?> tests passed. Phase 2 is complete — safe to commit and proceed to Phase 3.
  <?php else: ?>
    ❌ <?= $fail ?> test(s) failed. Check that Phase 1 migration ran and permissions.php was updated correctly.
  <?php endif; ?>
</div>

<div class="footer">Generated: <?= date('d M Y, H:i:s') ?> | File: api/test_rfq_phase2.php</div>
</body>
</html>
