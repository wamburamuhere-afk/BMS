<?php
require_once __DIR__ . '/../roots.php';
includeHeader();

global $pdo;

$checks = [];

// ── Phase 1: Static PHP checks ─────────────────────────────────────────────

// 1. Migration files
$mig_files = [
    'migrations/2026_05_20_create_customer_lpos.php',
    'migrations/2026_05_20_lpo_status_workflow.php',
];
foreach ($mig_files as $f) {
    $path = __DIR__ . '/../' . $f;
    $checks[] = [file_exists($path) ? 'pass' : 'fail', "File: $f exists"];
    if (file_exists($path)) {
        try { token_get_all(file_get_contents($path), TOKEN_PARSE); $checks[] = ['pass', "Syntax: $f OK"]; }
        catch (ParseError $e) { $checks[] = ['fail', "Syntax: $f — " . $e->getMessage()]; }
    }
}

// 2. API files
$api_files = [
    'api/customer/add_lpo.php',
    'api/customer/update_lpo.php',
    'api/customer/delete_lpo.php',
    'api/customer/get_lpo.php',
    'api/customer/get_lpos_list.php',
    'api/customer/change_lpo_status.php',
];
foreach ($api_files as $f) {
    $path = __DIR__ . '/../' . $f;
    $checks[] = [file_exists($path) ? 'pass' : 'fail', "File: $f exists"];
    if (file_exists($path)) {
        try { token_get_all(file_get_contents($path), TOKEN_PARSE); $checks[] = ['pass', "Syntax: $f OK"]; }
        catch (ParseError $e) { $checks[] = ['fail', "Syntax: $f — " . $e->getMessage()]; }
    }
}

// 3. customer_details.php syntax
$cdp = __DIR__ . '/../app/bms/customer/customer_details.php';
try { token_get_all(file_get_contents($cdp), TOKEN_PARSE); $checks[] = ['pass', 'Syntax: customer_details.php OK']; }
catch (ParseError $e) { $checks[] = ['fail', 'Syntax: customer_details.php — ' . $e->getMessage()]; }

// 4. DB: table exists
try {
    $pdo->query("SELECT 1 FROM customer_lpos LIMIT 1");
    $checks[] = ['pass', 'DB: customer_lpos table exists'];
} catch (PDOException $e) {
    $checks[] = ['fail', 'DB: customer_lpos table MISSING — run migration'];
}

// 5. DB: ENUM has all required statuses
try {
    $col = $pdo->query("SHOW COLUMNS FROM customer_lpos LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    foreach (['pending','reviewed','approved','open','partially_fulfilled','fulfilled','cancelled'] as $s) {
        $checks[] = [strpos($type, $s) !== false ? 'pass' : 'fail', "DB: status ENUM contains '$s'"];
    }
    $checks[] = [strpos($type, "'pending'") !== false ? 'pass' : 'fail', "DB: default status is 'pending' (check ENUM order)"];
} catch (PDOException $e) {
    $checks[] = ['warn', 'DB: cannot check ENUM — ' . $e->getMessage()];
}

// 6. customer_details.php contains all required markers
$cdContent = file_get_contents($cdp);
$markers = [
    'viewLpoModal'                  => 'View LPO modal present',
    'viewLpo('                      => 'viewLpo() JS function present',
    'changeLpoStatus('              => 'changeLpoStatus() JS function present',
    'printLpoDetails('              => 'printLpoDetails() JS function present',
    'viewLpoEditBtn'                => 'View modal Edit button present',
    'viewLpoReviewBtn'              => 'View modal Review button present',
    'viewLpoApproveBtn'             => 'View modal Approve button present',
    'bi bi-printer'                 => 'Print button icon present',
    'change_lpo_status.php'         => 'change_lpo_status API URL referenced',
    'LPO Number is auto-generated'  => 'Auto-generate note in Add modal',
    'Status will be set to'         => 'Pending status note in Add modal',
    'Mark Reviewed'                 => 'Mark Reviewed button in view modal',
    'Approve'                       => 'Approve button in view modal',
    'pending'                       => 'Pending badge color defined',
    'reviewed'                      => 'Reviewed badge color defined',
    'approved'                      => 'Approved badge color defined',
    'customer_display_name'         => 'customer_display_name used in viewLpo JS',
    'document_url'                  => 'document_url used in viewLpo JS',
    'View Details'                  => 'View Details in gear dropdown',
    'bi bi-eye text-info'           => 'View Details icon correct color',
    'auto-generate note'            => 'n/a', // skip
];
unset($markers['auto-generate note']); // remove placeholder
foreach ($markers as $needle => $label) {
    $checks[] = [strpos($cdContent, $needle) !== false ? 'pass' : 'fail', "Page: $label"];
}

// 7. add_lpo.php: does NOT reference $_POST['lpo_number'] for user input (auto-generated)
$addContent = file_get_contents(__DIR__ . '/../api/customer/add_lpo.php');
$checks[] = [strpos($addContent, "POST['lpo_number']") === false ? 'pass' : 'fail',
    'add_lpo.php: lpo_number NOT taken from POST (auto-generated)'];
$checks[] = [strpos($addContent, "status      = 'pending'") !== false || strpos($addContent, "status = 'pending'") !== false ? 'pass' : 'fail',
    'add_lpo.php: status hardcoded to pending'];
$checks[] = [strpos($addContent, 'LPO-') !== false ? 'pass' : 'fail',
    'add_lpo.php: LPO number prefix LPO- present'];
$checks[] = [strpos($addContent, 'str_pad') !== false ? 'pass' : 'fail',
    'add_lpo.php: str_pad used for zero-padded number'];

// 8. change_lpo_status.php: transitions table correct
$ccsContent = file_get_contents(__DIR__ . '/../api/customer/change_lpo_status.php');
$checks[] = [strpos($ccsContent, "'pending'  => 'reviewed'") !== false || strpos($ccsContent, "'pending' => 'reviewed'") !== false ? 'pass' : 'fail',
    'change_lpo_status.php: pending→reviewed transition defined'];
$checks[] = [strpos($ccsContent, "'reviewed' => 'approved'") !== false ? 'pass' : 'fail',
    'change_lpo_status.php: reviewed→approved transition defined'];
$checks[] = [strpos($ccsContent, 'logActivity') !== false ? 'pass' : 'fail',
    'change_lpo_status.php: logActivity called'];
$checks[] = [strpos($ccsContent, 'csrf_check') !== false ? 'pass' : 'fail',
    'change_lpo_status.php: CSRF check present'];

// 9. get_lpo.php: joins customer name
$getLpoContent = file_get_contents(__DIR__ . '/../api/customer/get_lpo.php');
$checks[] = [strpos($getLpoContent, 'customer_display_name') !== false ? 'pass' : 'fail',
    'get_lpo.php: customer_display_name in query'];
$checks[] = [strpos($getLpoContent, 'document_url') !== false ? 'pass' : 'fail',
    'get_lpo.php: document_url returned'];
$checks[] = [strpos($getLpoContent, 'LEFT JOIN customers') !== false ? 'pass' : 'fail',
    'get_lpo.php: LEFT JOIN customers present'];

// 10. Find test customer
$test_customer = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$checks[] = [$test_customer ? 'pass' : 'fail', $test_customer
    ? "Test customer: #{$test_customer['customer_id']} — {$test_customer['customer_name']}"
    : 'No active customers found'];

// Summary counts
$passed = count(array_filter($checks, fn($c) => $c[0] === 'pass'));
$failed = count(array_filter($checks, fn($c) => $c[0] === 'fail'));
$warned = count(array_filter($checks, fn($c) => $c[0] === 'warn'));
?>

<div class="container-fluid mt-4" style="max-width:960px;">
<div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-clipboard2-pulse me-2"></i> LPO Workflow — Full Test Suite (View/Status/Auto-number)</h5>
    </div>
    <div class="card-body">

        <div class="d-flex gap-3 mb-4 flex-wrap">
            <span class="badge bg-success fs-6 px-3 py-2"><?= $passed ?> Passed</span>
            <span class="badge bg-danger  fs-6 px-3 py-2"><?= $failed ?> Failed</span>
            <span class="badge bg-warning text-dark fs-6 px-3 py-2"><?= $warned ?> Warnings</span>
            <span class="badge bg-secondary fs-6 px-3 py-2">Total: <?= count($checks) ?></span>
        </div>

        <?php if ($failed > 0): ?>
        <div class="alert alert-danger"><strong>❌ <?= $failed ?> static check(s) failed.</strong> Fix before running API tests.</div>
        <?php else: ?>
        <div class="alert alert-success"><strong>✓ All static checks passed.</strong></div>
        <?php endif; ?>

        <!-- Phase 1: Static -->
        <h6 class="fw-bold text-primary mb-2"><i class="bi bi-server me-1"></i> Phase 1 — Static Checks</h6>
        <table class="table table-sm table-bordered mb-4">
            <tbody>
                <?php foreach ($checks as $c): ?>
                <tr class="<?= $c[0]==='pass'?'table-success':($c[0]==='fail'?'table-danger':'table-warning') ?>">
                    <td style="width:28px" class="text-center"><?= $c[0]==='pass'?'✓':($c[0]==='fail'?'✗':'⚠') ?></td>
                    <td class="font-monospace small"><?= htmlspecialchars($c[1]) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Phase 2: Status Workflow CRUD -->
        <h6 class="fw-bold text-primary mb-2"><i class="bi bi-arrow-repeat me-1"></i> Phase 2 — Status Workflow: create → review → approve → delete</h6>
        <?php if (!$test_customer): ?>
        <div class="alert alert-danger">No customer found — cannot run API tests.</div>
        <?php else: ?>
        <div class="alert alert-info small">
            Using customer <strong>#<?= $test_customer['customer_id'] ?> — <?= htmlspecialchars($test_customer['customer_name']) ?></strong>.
            Creates a real LPO, advances it through pending → reviewed → approved, then soft-deletes it.
        </div>
        <button id="runWorkflowTests" class="btn btn-primary mb-3"><i class="bi bi-play-circle me-1"></i> Run Workflow Tests</button>
        <div id="workflowLog"    class="font-monospace small p-3 bg-dark text-light rounded mb-3" style="min-height:200px;max-height:500px;overflow-y:auto;display:none;"></div>
        <div id="workflowSummary" class="d-none"></div>
        <?php endif; ?>

        <!-- Phase 3: Validation -->
        <h6 class="fw-bold text-primary mt-4 mb-2"><i class="bi bi-shield-check me-1"></i> Phase 3 — Validation & Edge Cases</h6>
        <button id="runValidation" class="btn btn-warning mb-3"><i class="bi bi-play-circle me-1"></i> Run Validation Tests</button>
        <div id="valLog"     class="font-monospace small p-3 bg-dark text-light rounded mb-3" style="min-height:140px;max-height:400px;overflow-y:auto;display:none;"></div>
        <div id="valSummary" class="d-none"></div>

        <!-- Phase 4: Visual -->
        <h6 class="fw-bold text-primary mt-4 mb-2"><i class="bi bi-eye me-1"></i> Phase 4 — Manual Visual Checks</h6>
        <p class="small text-muted mb-2">Open each customer and verify: LPO section visible, Add LPO button works (no LPO# input, no status select), gear dropdown shows "View Details" first, View Details modal opens with Print/Edit/Review/Approve buttons.</p>
        <ul class="list-group list-group-flush">
            <?php
            $custs = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status='active' ORDER BY customer_id LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($custs as $c): ?>
            <li class="list-group-item py-2">
                <a href="<?= getUrl('bms/customer/customer_details?id='.$c['customer_id']) ?>" target="_blank" class="text-decoration-none fw-semibold">
                    <i class="bi bi-box-arrow-up-right me-1"></i> #<?= $c['customer_id'] ?> — <?= htmlspecialchars($c['customer_name']) ?>
                </a>
                <ul class="small text-muted mt-1 mb-0">
                    <li>LPO section visible (stat cards, table)</li>
                    <li>Add LPO — no LPO Number field, no Status field, info banner shown</li>
                    <li>Gear → View Details opens modal with correct data</li>
                    <li>View modal: Print opens print window; Edit closes modal and opens edit</li>
                    <li>After creating LPO: gear → View Details → "Mark Reviewed" button visible</li>
                    <li>After reviewing: gear → View Details → "Approve" button visible</li>
                    <li>After approving: no workflow buttons (terminal state)</li>
                </ul>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
</div>

<script>
const CID  = <?= $test_customer ? $test_customer['customer_id'] : 0 ?>;
const CSRF = typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '';

let wPass = 0, wFail = 0, vPass = 0, vFail = 0;
let testLpoId = null;

function post(url, data) {
    data._csrf = CSRF;
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k,v);
    return fetch(url, {method:'POST', body:fd}).then(r=>r.json()).catch(e=>({success:false,message:'Fetch: '+e.message}));
}
function get(url, params) {
    return fetch(url+'?'+new URLSearchParams({...params,_t:Date.now()})).then(r=>r.json()).catch(e=>({success:false,message:'Fetch: '+e.message}));
}
function lg(el, msg, type) {
    const c={pass:'#6ee86e',fail:'#ff6b6b',warn:'#ffd93d',info:'#a0cfff',head:'#ffffff'};
    const p={pass:'  ✓ ',fail:'  ✗ ',warn:'  ⚠ ',info:'    ',head:'\n▶ '};
    el.innerHTML += `<span style="color:${c[type]||'#ccc'}">${p[type]||'  '}${msg}</span>\n`;
    el.scrollTop = el.scrollHeight;
}
function wc(el, ok, label, note) {
    ok ? wPass++ : wFail++;
    lg(el, label + (note?' — '+note:''), ok?'pass':'fail');
}
function vc(el, ok, label, note) {
    ok ? vPass++ : vFail++;
    lg(el, label + (note?' — '+note:''), ok?'pass':'fail');
}

// ── Phase 2: Workflow ─────────────────────────────────────────────────────
document.getElementById('runWorkflowTests')?.addEventListener('click', async function() {
    wPass=0; wFail=0; testLpoId=null;
    const el = document.getElementById('workflowLog');
    el.style.display='block'; el.innerHTML='';
    this.disabled=true; this.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Running...';

    // A. Create LPO — verify auto-number and pending status
    lg(el,'Test A: add_lpo — auto-generated number, status=pending','head');
    const add = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id: CID, issue_date:'<?= date('Y-m-d') ?>',
        expiry_date:'<?= date('Y-m-d', strtotime('+30 days')) ?>',
        amount:'99000', currency:'TZS',
        description:'Workflow test LPO', notes:'Auto test'
    });
    wc(el, add.success===true,   'add_lpo: success=true', add.message);
    wc(el, !!add.lpo_id,         'add_lpo: lpo_id returned = '+add.lpo_id);
    testLpoId = add.lpo_id || null;

    // B. get_lpo — check auto-number format, status=pending, customer_display_name, document_url
    lg(el,'Test B: get_lpo — check auto-number, status, customer_display_name, document_url','head');
    if (testLpoId) {
        const g = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, g.success===true,                    'get_lpo: success=true');
        wc(el, /^LPO-\d{4}-\d{5}$/.test(g.data?.lpo_number||''), 'get_lpo: lpo_number format LPO-YYYY-NNNNN', g.data?.lpo_number);
        wc(el, g.data?.status === 'pending',        'get_lpo: status is pending');
        wc(el, !!g.data?.customer_display_name,     'get_lpo: customer_display_name returned', g.data?.customer_display_name);
        wc(el, 'document_url' in (g.data||{}),      'get_lpo: document_url key present (null OK for no doc)');
        wc(el, g.data?.customer_id == CID,          'get_lpo: customer_id matches');
    } else {
        lg(el,'Skipped (no lpo_id from step A)','warn');
    }

    // C. change_lpo_status: pending → reviewed
    lg(el,'Test C: change_lpo_status — pending → reviewed','head');
    if (testLpoId) {
        const rv = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:testLpoId, new_status:'reviewed'});
        wc(el, rv.success===true, 'change_status pending→reviewed: success=true', rv.message);

        const g2 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, g2.data?.status==='reviewed', 'get_lpo after review: status persisted as reviewed');
    } else { lg(el,'Skipped','warn'); }

    // D. Invalid transition: try pending→approved (skip reviewed) — should fail
    lg(el,'Test D: change_lpo_status — invalid transition reviewed→pending (wrong direction)','head');
    if (testLpoId) {
        const bad = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:testLpoId, new_status:'pending'});
        wc(el, bad.success===false, 'Invalid transition reviewed→pending correctly rejected', bad.message);
    } else { lg(el,'Skipped','warn'); }

    // E. change_lpo_status: reviewed → approved
    lg(el,'Test E: change_lpo_status — reviewed → approved','head');
    if (testLpoId) {
        const ap = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:testLpoId, new_status:'approved'});
        wc(el, ap.success===true, 'change_status reviewed→approved: success=true', ap.message);

        const g3 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, g3.data?.status==='approved', 'get_lpo after approve: status persisted as approved');
    } else { lg(el,'Skipped','warn'); }

    // F. Terminal state: try approved→reviewed — should fail
    lg(el,'Test F: change_lpo_status — terminal state (approved→reviewed must fail)','head');
    if (testLpoId) {
        const term = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:testLpoId, new_status:'reviewed'});
        wc(el, term.success===false, 'approved→reviewed correctly rejected', term.message);
    } else { lg(el,'Skipped','warn'); }

    // G. update_lpo — edit amount and description
    lg(el,'Test G: update_lpo — edit approved LPO','head');
    if (testLpoId) {
        const upd = await post('<?= buildUrl('api/customer/update_lpo.php') ?>', {
            lpo_id:testLpoId, lpo_number:'LPO-TEST-UPD', issue_date:'<?= date('Y-m-d') ?>',
            amount:'150000', currency:'USD', status:'approved', description:'Updated desc'
        });
        wc(el, upd.success===true, 'update_lpo: success=true', upd.message);
        const g4 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, parseFloat(g4.data?.amount)===150000, 'update_lpo: amount updated to 150000');
        wc(el, g4.data?.currency==='USD', 'update_lpo: currency updated to USD');
    } else { lg(el,'Skipped','warn'); }

    // H. get_lpos_list — verify LPO appears
    lg(el,'Test H: get_lpos_list — LPO visible in list','head');
    if (testLpoId) {
        const list = await get('<?= buildUrl('api/customer/get_lpos_list.php') ?>', {customer_id:CID});
        wc(el, list.success===true, 'get_lpos_list: success=true');
        wc(el, list.data?.some(l=>l.lpo_id==testLpoId), 'get_lpos_list: test LPO found in list');
    } else { lg(el,'Skipped','warn'); }

    // I. delete_lpo — soft delete
    lg(el,'Test I: delete_lpo — soft-delete','head');
    if (testLpoId) {
        const del = await post('<?= buildUrl('api/customer/delete_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, del.success===true, 'delete_lpo: success=true', del.message);

        const g5 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:testLpoId});
        wc(el, g5.success===false, 'get_lpo after delete: returns false (soft-deleted)');

        const list2 = await get('<?= buildUrl('api/customer/get_lpos_list.php') ?>', {customer_id:CID});
        wc(el, !list2.data?.some(l=>l.lpo_id==testLpoId), 'get_lpos_list after delete: LPO not visible');
    } else { lg(el,'Skipped','warn'); }

    const sum = document.getElementById('workflowSummary');
    sum.classList.remove('d-none');
    sum.innerHTML=`<div class="alert alert-${wFail>0?'danger':'success'}"><strong>Phase 2: ${wPass} passed, ${wFail} failed</strong>${wFail>0?'<br>Fix before deploying.':''}</div>`;
    this.disabled=false; this.innerHTML='<i class="bi bi-arrow-clockwise me-1"></i> Re-run';
});

// ── Phase 3: Validation ───────────────────────────────────────────────────
document.getElementById('runValidation')?.addEventListener('click', async function() {
    vPass=0; vFail=0;
    const el = document.getElementById('valLog');
    el.style.display='block'; el.innerHTML='';
    this.disabled=true; this.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Running...';

    // add_lpo validations
    lg(el,'Validation A: add_lpo — required fields','head');
    const a1 = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {issue_date:'<?= date('Y-m-d') ?>', amount:'100'});
    vc(el, a1.success===false, 'add_lpo: missing customer_id rejected', a1.message);

    const a2 = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {customer_id:CID, amount:'100'});
    vc(el, a2.success===false, 'add_lpo: missing issue_date rejected', a2.message);

    const a3 = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {customer_id:CID, issue_date:'<?= date('Y-m-d') ?>', amount:'0'});
    vc(el, a3.success===false, 'add_lpo: amount=0 rejected', a3.message);

    const a4 = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {customer_id:CID, issue_date:'<?= date('Y-m-d') ?>', amount:'-100'});
    vc(el, a4.success===false, 'add_lpo: negative amount rejected', a4.message);

    // add_lpo: lpo_number NOT in POST (should still succeed — auto-generated)
    lg(el,'Validation B: add_lpo — lpo_number in POST is ignored (auto-generated)','head');
    const b1 = await post('<?= buildUrl('api/customer/add_lpo.php') ?>', {
        customer_id:CID, issue_date:'<?= date('Y-m-d') ?>', amount:'500',
        lpo_number:'MANUAL-NUMBER-SHOULD-BE-IGNORED'
    });
    vc(el, b1.success===true, 'add_lpo with manual lpo_number in POST: succeeds', b1.message);
    if (b1.lpo_id) {
        const gv = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:b1.lpo_id});
        vc(el, gv.data?.lpo_number !== 'MANUAL-NUMBER-SHOULD-BE-IGNORED', 'lpo_number auto-generated, not from POST', gv.data?.lpo_number);
        vc(el, gv.data?.status==='pending', 'status is pending regardless of POST data', gv.data?.status);
        // cleanup
        await post('<?= buildUrl('api/customer/delete_lpo.php') ?>', {lpo_id:b1.lpo_id});
    }

    // change_lpo_status: missing lpo_id
    lg(el,'Validation C: change_lpo_status — missing/invalid inputs','head');
    const c1 = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {new_status:'reviewed'});
    vc(el, c1.success===false, 'change_status: missing lpo_id rejected', c1.message);

    const c2 = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:999999, new_status:'reviewed'});
    vc(el, c2.success===false, 'change_status: non-existent lpo_id rejected', c2.message);

    const c3 = await post('<?= buildUrl('api/customer/change_lpo_status.php') ?>', {lpo_id:1, new_status:'cancelled'});
    vc(el, c3.success===false, 'change_status: invalid target status "cancelled" rejected', c3.message);

    // update_lpo validations
    lg(el,'Validation D: update_lpo — missing fields','head');
    const d1 = await post('<?= buildUrl('api/customer/update_lpo.php') ?>', {lpo_number:'X', issue_date:'<?= date('Y-m-d') ?>', amount:'100'});
    vc(el, d1.success===false, 'update_lpo: missing lpo_id rejected', d1.message);

    const d2 = await post('<?= buildUrl('api/customer/update_lpo.php') ?>', {lpo_id:999999, lpo_number:'X', issue_date:'<?= date('Y-m-d') ?>', amount:'100'});
    vc(el, d2.success===false, 'update_lpo: non-existent lpo_id rejected', d2.message);

    const d3 = await post('<?= buildUrl('api/customer/update_lpo.php') ?>', {lpo_id:1, issue_date:'<?= date('Y-m-d') ?>', amount:'100'});
    vc(el, d3.success===false, 'update_lpo: missing lpo_number rejected', d3.message);

    // Method enforcement
    lg(el,'Validation E: POST-only endpoints reject GET','head');
    for (const ep of ['add_lpo','update_lpo','delete_lpo','change_lpo_status']) {
        try {
            const r = await fetch('<?= buildUrl('api/customer/') ?>' + ep + '.php');
            const j = await r.json();
            vc(el, j.success===false || r.status===405, ep+'.php: GET rejected');
        } catch(e) { vc(el, false, ep+'.php: method check error: '+e.message); }
    }

    // get_lpo without id
    lg(el,'Validation F: get endpoints — missing required params','head');
    const f1 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {});
    vc(el, f1.success===false, 'get_lpo: missing lpo_id rejected', f1.message);

    const f2 = await get('<?= buildUrl('api/customer/get_lpos_list.php') ?>', {});
    vc(el, f2.success===false, 'get_lpos_list: missing customer_id rejected', f2.message);

    const f3 = await get('<?= buildUrl('api/customer/get_lpo.php') ?>', {lpo_id:999999});
    vc(el, f3.success===false, 'get_lpo(999999): not-found returns false', f3.message);

    const sum = document.getElementById('valSummary');
    sum.classList.remove('d-none');
    sum.innerHTML=`<div class="alert alert-${vFail>0?'danger':'success'}"><strong>Phase 3: ${vPass} passed, ${vFail} failed</strong></div>`;
    this.disabled=false; this.innerHTML='<i class="bi bi-arrow-clockwise me-1"></i> Re-run';
});
</script>

<?php includeFooter(); ?>
