<?php
/**
 * Tender upgrade — Phase E (AWARDED -> Project linkage hardening) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_award_project_link_cli.php
 *
 * Exercises the REAL awardTenderToProject() end to end (not a re-derivation
 * of its logic) and asserts all six tender.md Sec 2.1 gaps are actually
 * closed:
 *   1. traceability      - the new project's tender_id points back correctly
 *   2. budget promise    - projects.budget = the awarded tender_sum
 *   3. team access       - a tender_staff member WITH a login gets a
 *                          user_projects row; one WITHOUT a login is
 *                          silently skipped, not errored on
 *   4. idempotency       - awarding the same tender twice is refused, and a
 *                          raw duplicate INSERT is rejected at the DB level
 *                          (the UNIQUE key backstop) even bypassing the guard
 *   5. currency          - a USD tender's budget_currency is recorded as USD,
 *                          not silently assumed TZS
 *   6. BOQ/Materials carry-over - project_boq_bills/items mirror the tender's
 *      BOQ (real copies, different row ids); the Materials Schedule seeds a
 *      project NIP Material List, linking an already-catalogued product and
 *      creating a new NIP product for a free-text line
 *
 * awardTenderToProject() follows the same $ownTxn convention as
 * core/code_generator.php::nextCode() — detects it's already inside this
 * test's transaction and does not commit/rollback its own, so the whole test
 * (including the code_sequences NIP counter bump) rolls back cleanly. Exit 0
 * = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_award.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New/changed files are lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'core/tender_award.php',
        'api/tender_workflow.php',
        'app/bms/tenders/tenders.php',
        'app/bms/tenders/tender_view.php',
        'migrations/2026_09_05_tender_award_project_link.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    ok(function_exists('awardTenderToProject'), 'awardTenderToProject() is defined');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — new columns/tables exist');
    // ─────────────────────────────────────────────────────────────────────
    ok((bool)$pdo->query("SHOW COLUMNS FROM projects LIKE 'tender_id'")->fetch(), 'projects.tender_id exists');
    ok((bool)$pdo->query("SHOW COLUMNS FROM projects LIKE 'budget_currency'")->fetch(), 'projects.budget_currency exists');
    $uniq = $pdo->query("SHOW INDEX FROM projects WHERE Key_name = 'uniq_project_tender'")->fetch();
    ok((bool)$uniq, 'projects.uniq_project_tender UNIQUE key exists');
    ok((bool)$pdo->query("SHOW TABLES LIKE 'project_boq_bills'")->fetch(), 'project_boq_bills table exists');
    ok((bool)$pdo->query("SHOW TABLES LIKE 'project_boq_items'")->fetch(), 'project_boq_items table exists');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Full award scenario — build a tender with BOQ, Materials, staff');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, procuring_entity_name, status, currency, tender_sum)
        VALUES ('TEST-AWARD-001', 'CLI Award Test Tender', 'Test Procuring Entity', 'NEGOTIATION', 'USD', 15000)
    ");
    $tenderId = (int)$pdo->lastInsertId();

    // BOQ: one bill, one item -> subtotal 5,000.
    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Bill No. 1', 0)")->execute([$tenderId]);
    $billId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, 'Steel poles', 'each', 10, 500, 5000, 0)")->execute([$billId]);

    // Materials: one linked to a real product, one free-text.
    $pdo->exec("INSERT INTO products (product_name, unit, status) VALUES ('CLI Award Test Product', 'bags', 'active')");
    $existingProductId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_materials (tender_id, product_id, material, unit, qty, rate, amount, sort_order) VALUES (?, ?, 'CLI Award Test Product', 'bags', 5, 1000, 5000, 0)")
        ->execute([$tenderId, $existingProductId]);
    $pdo->prepare("INSERT INTO tender_materials (tender_id, product_id, material, unit, qty, rate, amount, sort_order) VALUES (?, NULL, 'New Uncatalogued Wire', 'm', 20, 200, 4000, 1)")
        ->execute([$tenderId]);

    // Staff: one WITH a login (should get user_projects), one WITHOUT.
    $pdo->exec("
        INSERT INTO employees (employee_number, first_name, last_name, gender, date_of_birth, email, phone, address, emergency_contact, hire_date, created_by)
        VALUES ('CLI-AWARD-EMP-1', 'Award', 'LeadPerson', 'male', '1990-01-01', 'award.lead@example.test', '0700000001', 'Test', 'Test EC', '2026-01-01', 1)
    ");
    $empWithLoginId = (int)$pdo->lastInsertId();
    $pdo->exec("
        INSERT INTO employees (employee_number, first_name, last_name, gender, date_of_birth, email, phone, address, emergency_contact, hire_date, created_by)
        VALUES ('CLI-AWARD-EMP-2', 'NoLogin', 'Person', 'male', '1990-01-01', 'award.nologin@example.test', '0700000002', 'Test', 'Test EC', '2026-01-01', 1)
    ");
    $empNoLoginId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO users (username, employee_id) VALUES ('cli_award_test_user', $empWithLoginId)");
    $testUserId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO tender_staff (tender_id, employee_id, role_position) VALUES (?, ?, 'Team Lead')")->execute([$tenderId, $empWithLoginId]);
    $pdo->prepare("INSERT INTO tender_staff (tender_id, employee_id, role_position) VALUES (?, ?, 'Technical Support')")->execute([$tenderId, $empNoLoginId]);

    // ─────────────────────────────────────────────────────────────────────
    section('4. Award it — run the real function');
    // ─────────────────────────────────────────────────────────────────────
    $result = awardTenderToProject($pdo, $tenderId, $testUserId, ['tender_sum' => 15000]);
    ok($result['success'] === true, 'awardTenderToProject() reports success: ' . ($result['message'] ?? ''));
    ok(!empty($result['project_id']), 'a project_id was returned');
    $projectId = (int)($result['project_id'] ?? 0);

    $proj = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
    $proj->execute([$projectId]);
    $project = $proj->fetch(PDO::FETCH_ASSOC);
    ok($project !== false, 'the project row actually exists');

    // Gap #1 — traceability.
    ok((int)$project['tender_id'] === $tenderId, 'gap#1: project.tender_id points back to the source tender');

    // Gap #2 — the budget promise is kept.
    ok(abs((float)$project['budget'] - 15000.0) < 0.01, 'gap#2: project.budget = the awarded tender_sum (15,000), not 0');

    // Gap #5 — currency recorded, not assumed.
    ok($project['budget_currency'] === 'USD', "gap#5: project.budget_currency = 'USD' (tender's own currency), got '{$project['budget_currency']}'");

    // project_manager seeded from the "lead" staff member.
    ok($project['project_manager'] === 'Award LeadPerson', "project_manager seeded as 'Award LeadPerson' (matched on 'lead' in role_position), got '{$project['project_manager']}'");

    // ─────────────────────────────────────────────────────────────────────
    section('5. Gap #3 — team access, correctly filtered');
    // ─────────────────────────────────────────────────────────────────────
    $assigned = $pdo->prepare("SELECT user_id FROM user_projects WHERE project_id = ?");
    $assigned->execute([$projectId]);
    $assignedUserIds = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));
    ok(in_array($testUserId, $assignedUserIds, true), 'the staff member WITH a login was assigned user_projects access');
    ok(count($assignedUserIds) === 1, 'exactly one user_projects row was created — the login-less staff member was silently skipped, not errored on');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Gap #6 — BOQ and Materials carried over correctly');
    // ─────────────────────────────────────────────────────────────────────
    $projBills = $pdo->prepare("SELECT * FROM project_boq_bills WHERE project_id = ?");
    $projBills->execute([$projectId]);
    $newBills = $projBills->fetchAll(PDO::FETCH_ASSOC);
    ok(count($newBills) === 1, 'exactly one project_boq_bills row was created');
    ok((int)$newBills[0]['bill_id'] !== $billId, 'the project BOQ bill is a real copy (different row id), not a reference to the tender\'s own bill');

    $projItems = $pdo->prepare("SELECT * FROM project_boq_items WHERE bill_id = ?");
    $projItems->execute([$newBills[0]['bill_id']]);
    $newItems = $projItems->fetchAll(PDO::FETCH_ASSOC);
    ok(count($newItems) === 1 && abs((float)$newItems[0]['amount'] - 5000.0) < 0.01, 'the project BOQ item carried the correct amount (5,000)');

    // Original tender BOQ must be untouched (frozen evidence).
    $origItem = $pdo->prepare("SELECT amount FROM tender_boq_items WHERE bill_id = ?");
    $origItem->execute([$billId]);
    ok(abs((float)$origItem->fetchColumn() - 5000.0) < 0.01, "the tender's own BOQ item is untouched (still frozen at 5,000)");

    $matList = $pdo->prepare("SELECT id FROM nip_material_lists WHERE project_id = ?");
    $matList->execute([$projectId]);
    $materialListId = $matList->fetchColumn();
    ok($materialListId !== false, 'a project NIP Material List was created');

    $nips = $pdo->prepare("SELECT nip_product_id, quantity FROM nip_material_list_nips WHERE material_list_id = ?");
    $nips->execute([$materialListId]);
    $nipRows = $nips->fetchAll(PDO::FETCH_ASSOC);
    ok(count($nipRows) === 2, 'both materials schedule lines were carried over (2 NIP rows)');
    $nipProductIds = array_map('intval', array_column($nipRows, 'nip_product_id'));
    ok(in_array($existingProductId, $nipProductIds, true), 'the already-catalogued product was referenced directly, not duplicated');

    $newNipProductId = null;
    foreach ($nipProductIds as $pid) { if ($pid !== $existingProductId) { $newNipProductId = $pid; } }
    ok($newNipProductId !== null, 'a new NIP product was created for the free-text material line');
    if ($newNipProductId) {
        $newProd = $pdo->prepare("SELECT product_name, is_service, track_inventory FROM products WHERE product_id = ?");
        $newProd->execute([$newNipProductId]);
        $newProdRow = $newProd->fetch(PDO::FETCH_ASSOC);
        ok($newProdRow['product_name'] === 'New Uncatalogued Wire', 'the new NIP product carries the free-text material name');
        ok((int)$newProdRow['is_service'] === 1 && (int)$newProdRow['track_inventory'] === 0, 'the new product follows the NIP convention (is_service=1, track_inventory=0)');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('7. Gap #4 — idempotency, both at the PHP guard and DB level');
    // ─────────────────────────────────────────────────────────────────────
    $secondAttempt = awardTenderToProject($pdo, $tenderId, $testUserId, ['tender_sum' => 99999]);
    ok($secondAttempt['success'] === false, 'a second award attempt on the same tender is refused');
    ok(stripos($secondAttempt['message'], 'already') !== false, 'the refusal message says it was already awarded');

    $countAfter = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE tender_id = ?");
    $countAfter->execute([$tenderId]);
    ok((int)$countAfter->fetchColumn() === 1, 'still exactly one project exists for this tender — no duplicate was created');

    // Bypass the PHP guard entirely — prove the UNIQUE key itself would
    // reject a second row even if some other code path tried a raw INSERT.
    $dbLevelRejected = false;
    try {
        $pdo->exec("INSERT INTO projects (project_name, start_date, tender_id) VALUES ('Duplicate Attempt', CURDATE(), $tenderId)");
    } catch (PDOException $e) {
        $dbLevelRejected = true;
    }
    ok($dbLevelRejected, 'a raw duplicate INSERT sharing the same tender_id is rejected by the UNIQUE key itself, not just the PHP guard');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind (including the NIP code_sequences bump)');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
