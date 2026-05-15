<?php
/**
 * Test: assign_sc_to_project API
 * Run: visit /scratch/test_assign_sc_project.php while logged into BMS
 *
 * Tests:
 *  1. Assign   — valid SC + valid project           → success
 *  2. Duplicate — assign same pair again             → success (INSERT IGNORE, no error)
 *  3. No IDs   — missing supplier_id / project_id   → validation error
 *  4. Bad SC   — non-existent supplier_id            → "not found" error
 *  5. Bad Proj — non-existent project_id             → "not found" error
 *  6. Unassign — remove the assignment from test 1  → success
 *  7. Unassign again — already removed               → success (DELETE is idempotent)
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">Not logged in — please log into BMS first.</p>');
}

// ── Pick real IDs from DB ─────────────────────────────────────────────────────
$sc   = $pdo->query("SELECT supplier_id, supplier_name FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$proj = $pdo->query("SELECT project_id, project_name FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$sc || !$proj) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">No sub-contractor or project found in DB — seed some data first.</p>');
}

$SC_ID   = $sc['supplier_id'];
$PROJ_ID = $proj['project_id'];

// ── Helper: call API via HTTP POST ────────────────────────────────────────────
function call_api(array $payload): array {
    $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $url  = $base . '/bms/api/assign_sc_to_project.php';

    // Forward the session cookie so isAuthenticated() passes
    $cookie = session_name() . '=' . session_id();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['_curl_error' => $err];
    $decoded = json_decode($raw, true);
    return $decoded ?? ['_raw' => $raw];
}

// ── Test cases ────────────────────────────────────────────────────────────────
$tests = [
    [
        'label'    => "1. Assign — SC #{$SC_ID} ({$sc['supplier_name']}) → Project #{$PROJ_ID} ({$proj['project_name']})",
        'payload'  => ['action' => 'assign', 'supplier_id' => $SC_ID, 'project_id' => $PROJ_ID],
        'expect'   => true,
    ],
    [
        'label'    => '2. Duplicate assign — same pair again (INSERT IGNORE)',
        'payload'  => ['action' => 'assign', 'supplier_id' => $SC_ID, 'project_id' => $PROJ_ID],
        'expect'   => true,
    ],
    [
        'label'    => '3. Missing IDs — no supplier_id or project_id',
        'payload'  => ['action' => 'assign'],
        'expect'   => false,
    ],
    [
        'label'    => '4. Bad SC — supplier_id = 999999',
        'payload'  => ['action' => 'assign', 'supplier_id' => 999999, 'project_id' => $PROJ_ID],
        'expect'   => false,
    ],
    [
        'label'    => '5. Bad Project — project_id = 999999',
        'payload'  => ['action' => 'assign', 'supplier_id' => $SC_ID, 'project_id' => 999999],
        'expect'   => false,
    ],
    [
        'label'    => "6. Unassign — remove SC #{$SC_ID} from Project #{$PROJ_ID}",
        'payload'  => ['action' => 'unassign', 'supplier_id' => $SC_ID, 'project_id' => $PROJ_ID],
        'expect'   => true,
    ],
    [
        'label'    => '7. Unassign again — already removed (DELETE idempotent)',
        'payload'  => ['action' => 'unassign', 'supplier_id' => $SC_ID, 'project_id' => $PROJ_ID],
        'expect'   => true,
    ],
];

// ── Run & collect results ─────────────────────────────────────────────────────
$results = [];
foreach ($tests as $t) {
    $res    = call_api($t['payload']);
    $passed = isset($res['success']) && $res['success'] === $t['expect'];
    $results[] = [
        'label'   => $t['label'],
        'expect'  => $t['expect'] ? 'success=true' : 'success=false',
        'got'     => isset($res['success']) ? ('success=' . ($res['success'] ? 'true' : 'false')) : 'no success key',
        'message' => $res['message'] ?? ($res['_curl_error'] ?? ($res['_raw'] ?? '—')),
        'passed'  => $passed,
    ];
}

$total  = count($results);
$passed = count(array_filter($results, fn($r) => $r['passed']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test — Assign SC to Project</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI',sans-serif; padding:32px; }
        h4   { font-weight:700; }
        .pass { background:#dcfce7; border-left:4px solid #16a34a; }
        .fail { background:#fee2e2; border-left:4px solid #dc2626; }
        code  { font-size:.82rem; }
    </style>
</head>
<body>
<h4 class="mb-1">Assign SC to Project — API Test</h4>
<p class="text-muted mb-4" style="font-size:.85rem">
    SC: <strong>#<?= $SC_ID ?> — <?= htmlspecialchars($sc['supplier_name']) ?></strong> &nbsp;|&nbsp;
    Project: <strong>#<?= $PROJ_ID ?> — <?= htmlspecialchars($proj['project_name']) ?></strong>
</p>

<div class="mb-3">
    <span class="badge <?= $passed === $total ? 'bg-success' : 'bg-danger' ?> fs-6">
        <?= $passed ?> / <?= $total ?> passed
    </span>
</div>

<div class="d-flex flex-column gap-2">
<?php foreach ($results as $r): ?>
<div class="rounded p-3 <?= $r['passed'] ? 'pass' : 'fail' ?>">
    <div class="fw-bold mb-1"><?= htmlspecialchars($r['label']) ?></div>
    <div class="d-flex gap-4" style="font-size:.82rem">
        <span>Expected: <code><?= $r['expect'] ?></code></span>
        <span>Got: <code><?= $r['got'] ?></code></span>
        <span>Message: <code><?= htmlspecialchars($r['message']) ?></code></span>
        <span class="ms-auto fw-bold <?= $r['passed'] ? 'text-success' : 'text-danger' ?>">
            <?= $r['passed'] ? '✓ PASS' : '✗ FAIL' ?>
        </span>
    </div>
</div>
<?php endforeach; ?>
</div>

</body>
</html>
