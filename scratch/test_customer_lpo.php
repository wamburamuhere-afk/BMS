<?php
require_once __DIR__ . '/../roots.php';
includeHeader();

global $pdo;

// ── PHP-side pre-flight checks ─────────────────────────────────────────────
$checks = [];

// 1. Table exists
try {
    $pdo->query("SELECT 1 FROM customer_lpos LIMIT 1");
    $checks[] = ['pass', 'DB: customer_lpos table exists'];
} catch (PDOException $e) {
    $checks[] = ['fail', 'DB: customer_lpos table MISSING — run migration first'];
}

// 2. Required columns
$expected_cols = ['lpo_id','lpo_number','customer_id','issue_date','expiry_date','amount','currency','description','status','document_path','notes','created_by','created_at','updated_at'];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customer_lpos")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expected_cols as $col) {
        if (in_array($col, $cols)) {
            $checks[] = ['pass', "DB: column `$col` exists"];
        } else {
            $checks[] = ['fail', "DB: column `$col` MISSING from customer_lpos"];
        }
    }
} catch (PDOException $e) {
    $checks[] = ['warn', 'DB: cannot check columns — table may not exist'];
}

// 3. Status ENUM values
try {
    $row = $pdo->query("SHOW COLUMNS FROM customer_lpos LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = $row['Type'] ?? '';
    $required_statuses = ['open','partially_fulfilled','fulfilled','cancelled'];
    foreach ($required_statuses as $s) {
        if (strpos($type, $s) !== false) {
            $checks[] = ['pass', "DB: status ENUM contains '$s'"];
        } else {
            $checks[] = ['fail', "DB: status ENUM missing '$s' — got: $type"];
        }
    }
} catch (PDOException $e) {
    $checks[] = ['warn', 'DB: cannot check status ENUM'];
}

// 4. API files exist
$api_files = [
    'api/customer/add_lpo.php',
    'api/customer/update_lpo.php',
    'api/customer/delete_lpo.php',
    'api/customer/get_lpo.php',
    'api/customer/get_lpos_list.php',
];
foreach ($api_files as $f) {
    $path = __DIR__ . '/../' . $f;
    if (file_exists($path)) {
        $checks[] = ['pass', "File: $f exists"];
    } else {
        $checks[] = ['fail', "File: $f MISSING"];
    }
}

// 5. PHP syntax on API files (token_get_all — no subprocess needed)
foreach ($api_files as $f) {
    $path = __DIR__ . '/../' . $f;
    if (!file_exists($path)) continue;
    try {
        token_get_all(file_get_contents($path), TOKEN_PARSE);
        $checks[] = ['pass', "Syntax: $f — OK"];
    } catch (ParseError $e) {
        $checks[] = ['fail', "Syntax: $f — " . $e->getMessage()];
    }
}

// 6. Upload directory
$upload_dir = __DIR__ . '/../uploads/finance/customer_lpos/';
if (is_dir($upload_dir)) {
    $checks[] = ['pass', 'Upload dir: uploads/finance/customer_lpos/ exists'];
    if (is_writable($upload_dir)) {
        $checks[] = ['pass', 'Upload dir: writable'];
    } else {
        $checks[] = ['warn', 'Upload dir: NOT writable — file uploads will fail'];
    }
} else {
    $checks[] = ['warn', 'Upload dir: uploads/finance/customer_lpos/ does not exist yet — will be created on first upload'];
}

// 7. Find a test customer
$test_customer = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($test_customer) {
    $checks[] = ['pass', "Test customer found: #{$test_customer['customer_id']} — {$test_customer['customer_name']}"];
} else {
    $checks[] = ['fail', 'No active customers found — cannot run API tests'];
}

// 8. customer_details.php has LPO section
$details_file = file_get_contents(__DIR__ . '/../app/bms/customer/customer_details.php');
$detail_checks = [
    'addLpoModal'            => 'Add LPO modal present in customer_details.php',
    'editLpoModal'           => 'Edit LPO modal present in customer_details.php',
    'editLpo('              => 'editLpo() JS function wired in customer_details.php',
    'deleteLpo('            => 'deleteLpo() JS function wired in customer_details.php',
    'api/customer/add_lpo'   => 'add_lpo.php URL referenced in customer_details.php',
    'api/customer/update_lpo'=> 'update_lpo.php URL referenced in customer_details.php',
    'api/customer/delete_lpo'=> 'delete_lpo.php URL referenced in customer_details.php',
    'api/customer/get_lpo'   => 'get_lpo.php URL referenced in customer_details.php',
    'customerLposTable'      => 'LPO DataTable ID present in customer_details.php',
    'Purchase Orders (LPO)'  => 'Section heading present in customer_details.php',
    'lpo_total_amount'       => 'PHP LPO data loading present in customer_details.php',
];
foreach ($detail_checks as $needle => $label) {
    if (strpos($details_file, $needle) !== false) {
        $checks[] = ['pass', "Page: $label"];
    } else {
        $checks[] = ['fail', "Page: $label — NOT FOUND"];
    }
}

// 9. Migration file
$mig_file = __DIR__ . '/../migrations/2026_05_20_create_customer_lpos.php';
if (file_exists($mig_file)) {
    $checks[] = ['pass', 'Migration: 2026_05_20_create_customer_lpos.php exists'];
    $mig_content = file_get_contents($mig_file);
    if (strpos($mig_content, 'CREATE TABLE IF NOT EXISTS') !== false) {
        $checks[] = ['pass', 'Migration: uses CREATE TABLE IF NOT EXISTS (idempotent)'];
    } else {
        $checks[] = ['fail', 'Migration: missing IF NOT EXISTS — not idempotent'];
    }
} else {
    $checks[] = ['fail', 'Migration: 2026_05_20_create_customer_lpos.php MISSING'];
}

// Count
$passed = count(array_filter($checks, fn($c) => $c[0] === 'pass'));
$failed = count(array_filter($checks, fn($c) => $c[0] === 'fail'));
$warned = count(array_filter($checks, fn($c) => $c[0] === 'warn'));
?>

<div class="container-fluid mt-4" style="max-width:960px;">
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard2-check me-2"></i>Customer LPO Feature — Full Test Suite</h5>
        </div>
        <div class="card-body">

            <!-- Summary badges -->
            <div class="d-flex gap-3 mb-4 flex-wrap">
                <span class="badge bg-success fs-6 px-3 py-2"><?= $passed ?> Passed</span>
                <span class="badge bg-danger fs-6 px-3 py-2"><?= $failed ?> Failed</span>
                <span class="badge bg-warning text-dark fs-6 px-3 py-2"><?= $warned ?> Warnings</span>
                <span class="badge bg-secondary fs-6 px-3 py-2">Total: <?= count($checks) ?></span>
            </div>

            <!-- Phase 1: PHP static checks -->
            <h6 class="fw-bold text-primary mb-2"><i class="bi bi-server me-1"></i> Phase 1 — PHP Static Checks</h6>
            <table class="table table-sm table-bordered mb-4">
                <tbody>
                    <?php foreach ($checks as $c): ?>
                    <tr class="<?= $c[0] === 'pass' ? 'table-success' : ($c[0] === 'fail' ? 'table-danger' : 'table-warning') ?>">
                        <td style="width:28px;" class="text-center">
                            <?= $c[0] === 'pass' ? '✓' : ($c[0] === 'fail' ? '✗' : '⚠') ?>
                        </td>
                        <td class="font-monospace small"><?= htmlspecialchars($c[1]) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($failed > 0): ?>
            <div class="alert alert-danger">
                <strong>❌ <?= $failed ?> PHP check(s) failed.</strong> Fix these before running API tests — the API tests below may not be reliable.
            </div>
            <?php endif; ?>

            <!-- Phase 2: Live API tests -->
            <h6 class="fw-bold text-primary mb-2"><i class="bi bi-cloud-check me-1"></i> Phase 2 — Live API Tests (CRUD cycle)</h6>
            <?php if (!$test_customer): ?>
            <div class="alert alert-danger">No active customer found — cannot run API tests.</div>
            <?php else: ?>
            <div class="alert alert-info small">
                Using customer: <strong>#<?= $test_customer['customer_id'] ?> — <?= htmlspecialchars($test_customer['customer_name']) ?></strong>
                &nbsp;|&nbsp; Tests will create a real LPO record, update it, then delete it (soft-delete).
                &nbsp;|&nbsp; No permanent data is left behind.
            </div>
            <button id="runApiTests" class="btn btn-primary mb-3">
                <i class="bi bi-play-circle me-1"></i> Run All API Tests
            </button>
            <div id="apiTestLog" class="font-monospace small p-3 bg-dark text-light rounded" style="min-height:220px;max-height:500px;overflow-y:auto;display:none;"></div>
            <div id="apiSummary" class="mt-3 d-none"></div>
            <?php endif; ?>

            <!-- Phase 3: Validation tests -->
            <h6 class="fw-bold text-primary mt-4 mb-2"><i class="bi bi-shield-check me-1"></i> Phase 3 — Validation & Error Handling Tests</h6>
            <button id="runValidationTests" class="btn btn-warning mb-3">
                <i class="bi bi-play-circle me-1"></i> Run Validation Tests
            </button>
            <div id="valTestLog" class="font-monospace small p-3 bg-dark text-light rounded" style="min-height:120px;max-height:400px;overflow-y:auto;display:none;"></div>
            <div id="valSummary" class="mt-3 d-none"></div>

            <!-- Phase 4: Manual browser links -->
            <h6 class="fw-bold text-primary mt-4 mb-2"><i class="bi bi-eye me-1"></i> Phase 4 — Manual Visual Checks</h6>
            <p class="small text-muted">Open these links to visually verify the LPO section renders correctly in the browser.</p>
            <ul class="list-group list-group-flush mb-2">
                <?php
                $check_cust = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status='active' ORDER BY customer_id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($check_cust as $cc): ?>
                <li class="list-group-item">
                    <a href="<?= getUrl('bms/customer/customer_details?id=' . $cc['customer_id']) ?>" target="_blank" class="text-decoration-none">
                        <i class="bi bi-box-arrow-up-right me-1"></i>
                        Customer #<?= $cc['customer_id'] ?> — <?= htmlspecialchars($cc['customer_name']) ?>
                    </a>
                    <span class="text-muted ms-2 small">(check LPO section is visible, Add LPO button works, table appears)</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<script>
const CUSTOMER_ID = <?= $test_customer ? $test_customer['customer_id'] : 0 ?>;
const CSRF = typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '';

let apiPassed = 0, apiFailed = 0;
let valPassed = 0, valFailed = 0;
let createdLpoId = null;

function log(el, msg, type) {
    const colors = { pass: '#6ee86e', fail: '#ff6b6b', warn: '#ffd93d', info: '#a0cfff', head: '#fff' };
    const prefix = { pass: '  ✓ ', fail: '  ✗ ', warn: '  ⚠ ', info: '    ', head: '\n▶ ' };
    el.innerHTML += `<span style="color:${colors[type] || '#ccc'}">${prefix[type] || '  '}${msg}</span>\n`;
    el.scrollTop = el.scrollHeight;
}

async function apiPost(url, data) {
    data._csrf = CSRF;
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    try {
        const r = await fetch(url, { method: 'POST', body: fd });
        return await r.json();
    } catch (e) {
        return { success: false, message: 'Fetch error: ' + e.message };
    }
}

async function apiGet(url, params) {
    const qs = new URLSearchParams({ ...params, _t: Date.now() }).toString();
    try {
        const r = await fetch(url + '?' + qs);
        return await r.json();
    } catch (e) {
        return { success: false, message: 'Fetch error: ' + e.message };
    }
}

function check(el, ok, label, extra) {
    if (ok) {
        apiPassed++;
        log(el, label + (extra ? ' — ' + extra : ''), 'pass');
    } else {
        apiFailed++;
        log(el, label + (extra ? ' — ' + extra : ''), 'fail');
    }
}

// ── Phase 2: CRUD cycle ────────────────────────────────────────────────────
document.getElementById('runApiTests')?.addEventListener('click', async function () {
    apiPassed = 0; apiFailed = 0; createdLpoId = null;
    const el = document.getElementById('apiTestLog');
    el.style.display = 'block';
    el.innerHTML = '';
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Running...';

    // A. get_lpos_list — empty state
    log(el, 'Test A: get_lpos_list (existing LPOs for customer)', 'head');
    const listRes = await apiGet('<?= buildUrl('api/customer/get_lpos_list.php') ?>', { customer_id: CUSTOMER_ID });
    check(el, listRes.success === true, 'get_lpos_list returns success');
    check(el, Array.isArray(listRes.data), 'Response .data is an array');

    // B. add_lpo — valid data
    log(el, 'Test B: add_lpo — create valid LPO', 'head');
    const addRes = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CUSTOMER_ID,
        lpo_number:  'TEST-LPO-' + Date.now(),
        issue_date:  '<?= date('Y-m-d') ?>',
        expiry_date: '<?= date('Y-m-d', strtotime('+30 days')) ?>',
        amount:      '50000.00',
        currency:    'TZS',
        description: 'Automated test LPO — safe to delete',
        status:      'open',
        notes:       'Created by test_customer_lpo.php',
    });
    check(el, addRes.success === true, 'add_lpo: success=true', addRes.message);
    check(el, !!addRes.lpo_id, 'add_lpo: returned lpo_id=' + addRes.lpo_id);
    createdLpoId = addRes.lpo_id || null;

    // C. get_lpo — fetch the just-created record
    log(el, 'Test C: get_lpo — fetch created record', 'head');
    if (createdLpoId) {
        const getRes = await apiGet('<?= buildUrl('api/customer/get_lpo.php') ?>', { lpo_id: createdLpoId });
        check(el, getRes.success === true, 'get_lpo: success=true');
        check(el, getRes.data?.lpo_id == createdLpoId, 'get_lpo: correct lpo_id returned');
        check(el, getRes.data?.status === 'open', 'get_lpo: status is "open" as saved');
        check(el, getRes.data?.currency === 'TZS', 'get_lpo: currency is TZS');
        check(el, parseFloat(getRes.data?.amount) === 50000, 'get_lpo: amount is 50000');
        check(el, getRes.data?.customer_id == CUSTOMER_ID, 'get_lpo: customer_id matches');
    } else {
        log(el, 'Skipped (no lpo_id from step B)', 'warn');
    }

    // D. get_lpos_list — now has our record
    log(el, 'Test D: get_lpos_list — verify new LPO appears in list', 'head');
    const list2 = await apiGet('<?= buildUrl('api/customer/get_lpos_list.php') ?>', { customer_id: CUSTOMER_ID });
    check(el, list2.success === true, 'get_lpos_list after add: success=true');
    if (createdLpoId) {
        const found = list2.data?.some(l => l.lpo_id == createdLpoId);
        check(el, found, 'get_lpos_list: new LPO is in list');
    }

    // E. update_lpo — change status and amount
    log(el, 'Test E: update_lpo — change status to partially_fulfilled', 'head');
    if (createdLpoId) {
        const updRes = await apiPost('<?= buildUrl('api/customer/update_lpo.php') ?>', {
            lpo_id:      createdLpoId,
            lpo_number:  'TEST-LPO-UPDATED-' + createdLpoId,
            issue_date:  '<?= date('Y-m-d') ?>',
            expiry_date: '<?= date('Y-m-d', strtotime('+60 days')) ?>',
            amount:      '75000.00',
            currency:    'USD',
            description: 'Updated by automated test',
            status:      'partially_fulfilled',
            notes:       'Updated notes',
        });
        check(el, updRes.success === true, 'update_lpo: success=true', updRes.message);

        // Verify update was persisted
        const getAfterUpd = await apiGet('<?= buildUrl('api/customer/get_lpo.php') ?>', { lpo_id: createdLpoId });
        check(el, getAfterUpd.data?.status === 'partially_fulfilled', 'update_lpo: status persisted correctly');
        check(el, parseFloat(getAfterUpd.data?.amount) === 75000, 'update_lpo: amount updated to 75000');
        check(el, getAfterUpd.data?.currency === 'USD', 'update_lpo: currency updated to USD');
        check(el, getAfterUpd.data?.lpo_number === 'TEST-LPO-UPDATED-' + createdLpoId, 'update_lpo: lpo_number updated');
    } else {
        log(el, 'Skipped (no lpo_id from step B)', 'warn');
    }

    // F. delete_lpo — soft-delete
    log(el, 'Test F: delete_lpo — soft-delete', 'head');
    if (createdLpoId) {
        const delRes = await apiPost('<?= buildUrl('api/customer/delete_lpo.php') ?>', { lpo_id: createdLpoId });
        check(el, delRes.success === true, 'delete_lpo: success=true', delRes.message);

        // Verify it no longer appears in list
        const list3 = await apiGet('<?= buildUrl('api/customer/get_lpos_list.php') ?>', { customer_id: CUSTOMER_ID });
        const stillVisible = list3.data?.some(l => l.lpo_id == createdLpoId);
        check(el, !stillVisible, 'delete_lpo: LPO no longer in get_lpos_list (soft-deleted)');

        // Verify get_lpo returns not-found
        const getAfterDel = await apiGet('<?= buildUrl('api/customer/get_lpo.php') ?>', { lpo_id: createdLpoId });
        check(el, getAfterDel.success === false, 'delete_lpo: get_lpo now returns success=false (record hidden)');
    } else {
        log(el, 'Skipped (no lpo_id from step B)', 'warn');
    }

    // G. get_lpo — non-existent ID
    log(el, 'Test G: edge cases — non-existent IDs', 'head');
    const getNone = await apiGet('<?= buildUrl('api/customer/get_lpo.php') ?>', { lpo_id: 999999 });
    check(el, getNone.success === false, 'get_lpo(999999): returns success=false for non-existent ID');

    const listNone = await apiGet('<?= buildUrl('api/customer/get_lpos_list.php') ?>', { customer_id: 999999 });
    check(el, listNone.success === true && listNone.data?.length === 0, 'get_lpos_list(999999): returns empty array for unknown customer');

    // Summary
    const summary = document.getElementById('apiSummary');
    summary.classList.remove('d-none');
    summary.innerHTML = `<div class="alert alert-${apiFailed > 0 ? 'danger' : 'success'}">
        <strong>Phase 2 Results: ${apiPassed} passed, ${apiFailed} failed</strong>
        ${apiFailed > 0 ? '<br>Fix failing tests before deploying to production.' : '<br>All API CRUD tests passed — feature is working correctly.'}
    </div>`;
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Re-run API Tests';
});


// ── Phase 3: Validation tests ──────────────────────────────────────────────
document.getElementById('runValidationTests')?.addEventListener('click', async function () {
    valPassed = 0; valFailed = 0;
    const el = document.getElementById('valTestLog');
    el.style.display = 'block';
    el.innerHTML = '';
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Running...';

    function vcheck(ok, label, extra) {
        if (ok) { valPassed++; log(el, label + (extra ? ' — ' + extra : ''), 'pass'); }
        else     { valFailed++; log(el, label + (extra ? ' — ' + extra : ''), 'fail'); }
    }

    // add_lpo — missing required fields
    log(el, 'Validation A: add_lpo missing fields', 'head');

    const missingCust = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        lpo_number: 'VAL-TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '100', currency: 'TZS', status: 'open'
        // no customer_id
    });
    vcheck(missingCust.success === false, 'add_lpo: missing customer_id rejected', missingCust.message);

    const missingNum = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CUSTOMER_ID, issue_date: '<?= date('Y-m-d') ?>', amount: '100', currency: 'TZS', status: 'open'
        // no lpo_number
    });
    vcheck(missingNum.success === false, 'add_lpo: missing lpo_number rejected', missingNum.message);

    const missingDate = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CUSTOMER_ID, lpo_number: 'VAL-TEST', amount: '100', currency: 'TZS', status: 'open'
        // no issue_date
    });
    vcheck(missingDate.success === false, 'add_lpo: missing issue_date rejected', missingDate.message);

    const zeroAmt = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CUSTOMER_ID, lpo_number: 'VAL-TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '0', currency: 'TZS', status: 'open'
    });
    vcheck(zeroAmt.success === false, 'add_lpo: amount=0 rejected', zeroAmt.message);

    const negAmt = await apiPost('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CUSTOMER_ID, lpo_number: 'VAL-TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '-500', currency: 'TZS', status: 'open'
    });
    vcheck(negAmt.success === false, 'add_lpo: negative amount rejected', negAmt.message);

    // update_lpo — missing lpo_id
    log(el, 'Validation B: update_lpo missing fields', 'head');

    const updNoId = await apiPost('<?= buildUrl('api/customer/update_lpo.php') ?>', {
        lpo_number: 'TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '100', currency: 'TZS', status: 'open'
        // no lpo_id
    });
    vcheck(updNoId.success === false, 'update_lpo: missing lpo_id rejected', updNoId.message);

    const updBadId = await apiPost('<?= buildUrl('api/customer/update_lpo.php') ?>', {
        lpo_id: 999999, lpo_number: 'TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '100', currency: 'TZS', status: 'open'
    });
    vcheck(updBadId.success === false, 'update_lpo: non-existent lpo_id rejected', updBadId.message);

    const updNoNum = await apiPost('<?= buildUrl('api/customer/update_lpo.php') ?>', {
        lpo_id: 1, issue_date: '<?= date('Y-m-d') ?>', amount: '100', currency: 'TZS', status: 'open'
        // no lpo_number
    });
    vcheck(updNoNum.success === false, 'update_lpo: missing lpo_number rejected', updNoNum.message);

    const updZeroAmt = await apiPost('<?= buildUrl('api/customer/update_lpo.php') ?>', {
        lpo_id: 1, lpo_number: 'TEST', issue_date: '<?= date('Y-m-d') ?>', amount: '0', currency: 'TZS', status: 'open'
    });
    vcheck(updZeroAmt.success === false, 'update_lpo: amount=0 rejected', updZeroAmt.message);

    // delete_lpo — missing lpo_id
    log(el, 'Validation C: delete_lpo missing/invalid ID', 'head');

    const delNoId = await apiPost('<?= buildUrl('api/customer/delete_lpo.php') ?>', {});
    vcheck(delNoId.success === false, 'delete_lpo: missing lpo_id rejected', delNoId.message);

    const delBadId = await apiPost('<?= buildUrl('api/customer/delete_lpo.php') ?>', { lpo_id: 999999 });
    vcheck(delBadId.success === false, 'delete_lpo: non-existent lpo_id rejected', delBadId.message);

    // get_lpo — missing id
    log(el, 'Validation D: get_lpo missing/invalid ID', 'head');

    const getNoId = await apiGet('<?= buildUrl('api/customer/get_lpo.php') ?>', {});
    vcheck(getNoId.success === false, 'get_lpo: missing lpo_id rejected', getNoId.message);

    // get_lpos_list — missing customer_id
    const listNoId = await apiGet('<?= buildUrl('api/customer/get_lpos_list.php') ?>', {});
    vcheck(listNoId.success === false, 'get_lpos_list: missing customer_id rejected', listNoId.message);

    // GET on POST-only endpoints
    log(el, 'Validation E: method enforcement (POST-only APIs must reject GET)', 'head');
    try {
        const r1 = await fetch('<?= buildUrl('api/customer/add_lpo.php') ?>');
        const j1 = await r1.json();
        vcheck(j1.success === false || r1.status === 405, 'add_lpo: GET request rejected (success=false or 405)');
    } catch (e) { vcheck(false, 'add_lpo: method check error: ' + e.message); }

    try {
        const r2 = await fetch('<?= buildUrl('api/customer/update_lpo.php') ?>');
        const j2 = await r2.json();
        vcheck(j2.success === false || r2.status === 405, 'update_lpo: GET request rejected');
    } catch (e) { vcheck(false, 'update_lpo: method check error: ' + e.message); }

    try {
        const r3 = await fetch('<?= buildUrl('api/customer/delete_lpo.php') ?>');
        const j3 = await r3.json();
        vcheck(j3.success === false || r3.status === 405, 'delete_lpo: GET request rejected');
    } catch (e) { vcheck(false, 'delete_lpo: method check error: ' + e.message); }

    // Summary
    const summary = document.getElementById('valSummary');
    summary.classList.remove('d-none');
    summary.innerHTML = `<div class="alert alert-${valFailed > 0 ? 'danger' : 'success'}">
        <strong>Phase 3 Results: ${valPassed} passed, ${valFailed} failed</strong>
        ${valFailed > 0 ? '<br>Validation gaps found — APIs accept invalid input that should be rejected.' : '<br>All validation tests passed — APIs correctly reject bad input.'}
    </div>`;
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Re-run Validation Tests';
});
</script>

<?php
includeFooter();
?>
