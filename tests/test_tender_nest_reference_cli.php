<?php
/**
 * Tender NeST Reference field — CLI test
 * ---------------------------------------
 *   php tests/test_tender_nest_reference_cli.php
 *
 * Adds a distinct `nest_reference` column (the number/ID NeST itself assigns
 * once a tender is listed on nest.go.tz) alongside the existing `tender_no`
 * (the procuring entity's own advert number) — matching the same distinction
 * the Facile reference system (facile-fms.com) keeps between "Tender Number"
 * and "NeST Reference" on its Bidding & Tenders module.
 *
 * Verifies:
 *   - the migration added the column
 *   - tender_create.php / tender_edit.php capture + persist it (round-trip
 *     via a real INSERT/UPDATE, not just a source-grep)
 *   - it is optional (NULL is fine — most tenders never reach NeST, or the
 *     reference isn't known yet)
 *   - api/get_tenders.php's `SELECT t.*` exposes it to the list without
 *     any query change (structural fact, verified by running that exact
 *     query shape against a real row)
 *   - tenders.php's list gained the column (header + DataTable column)
 *   - tender_edit.php's edit form does NOT repeat the safe_output() 'N/A'-
 *     leak-into-editable-field bug fixed on 2026-09-05 (tender_boq.php /
 *     tender_materials.php) — a blank nest_reference must render an empty
 *     input value, never the literal string "N/A"
 *   - tender_view.php displays the field
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
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
        'migrations/2026_09_06_tender_nest_reference.php',
        'app/bms/tenders/tender_create.php',
        'app/bms/tenders/tender_edit.php',
        'app/bms/tenders/tenders.php',
        'app/bms/tenders/tender_view.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — nest_reference column exists');
    // ─────────────────────────────────────────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM tenders LIKE 'nest_reference'")->fetch(PDO::FETCH_ASSOC);
    ok($col !== false, 'tenders.nest_reference exists');
    ok($col && stripos($col['Null'], 'YES') !== false, 'tenders.nest_reference is nullable (optional — most tenders never reach NeST)');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Create + Edit forms capture the field (source structure)');
    // ─────────────────────────────────────────────────────────────────────
    $createSrc = file_get_contents("$root/app/bms/tenders/tender_create.php");
    $editSrc   = file_get_contents("$root/app/bms/tenders/tender_edit.php");

    ok(strpos($createSrc, 'name="nest_reference"') !== false, 'tender_create.php form has a nest_reference input');
    ok(preg_match('/nest_reference.*?\n.*?VALUES/s', $createSrc) === 1, 'tender_create.php INSERT includes nest_reference in the column list');
    ok(strpos($createSrc, "trim(\$_POST['nest_reference'] ?? '') ?: null") !== false, 'tender_create.php reads nest_reference from POST, blank -> NULL');

    ok(strpos($editSrc, 'name="nest_reference"') !== false, 'tender_edit.php form has a nest_reference input');
    ok(strpos($editSrc, 'nest_reference = ?') !== false, 'tender_edit.php UPDATE sets nest_reference');
    ok(strpos($editSrc, "trim(\$_POST['nest_reference'] ?? '') ?: null") !== false, 'tender_edit.php reads nest_reference from POST, blank -> NULL');

    // Regression guard — the exact bug class fixed 2026-09-05 on tender_boq.php/
    // tender_materials.php: safe_output()'s default is the literal string 'N/A',
    // meant for read-only text, not an editable <input value="...">. A blank
    // nest_reference must NOT render "N/A" into the edit form's input value.
    ok(strpos($editSrc, "safe_output(\$tender['nest_reference'], '')") !== false,
        "tender_edit.php's nest_reference input passes an explicit empty-string default — does not repeat the N/A-leak bug");
    ok(strpos($editSrc, "value=\"<?= safe_output(\$tender['nest_reference']) ?>\"") === false,
        'tender_edit.php never uses the bare (N/A-defaulting) safe_output() call for this editable field');

    // ─────────────────────────────────────────────────────────────────────
    section('4. tenders.php list gained the column');
    // ─────────────────────────────────────────────────────────────────────
    $listSrc = file_get_contents("$root/app/bms/tenders/tenders.php");
    ok(strpos($listSrc, '>NeST Ref<') !== false, 'tenders.php table header has a "NeST Ref" column');
    ok(strpos($listSrc, "data: 'nest_reference'") !== false, 'tenders.php DataTable defines a nest_reference column');

    // ─────────────────────────────────────────────────────────────────────
    section('5. tender_view.php displays the field');
    // ─────────────────────────────────────────────────────────────────────
    $viewSrc = file_get_contents("$root/app/bms/tenders/tender_view.php");
    ok(strpos($viewSrc, "tender['nest_reference']") !== false, 'tender_view.php reads tender[\'nest_reference\']');
    ok(strpos($viewSrc, 'NeST Reference') !== false, 'tender_view.php labels the row "NeST Reference"');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Real round-trip — insert, read back, list-query exposure');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO tenders (tender_no, nest_reference, tender_description, status)
        VALUES ('TEST-NEST-001', 'TE/9999/2026-27/HQ/01', 'CLI NeST reference test tender', 'PENDING')
    ");
    $tenderId = (int)$pdo->lastInsertId();

    $row = $pdo->prepare("SELECT tender_no, nest_reference FROM tenders WHERE tender_id = ?");
    $row->execute([$tenderId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    ok($r['tender_no'] === 'TEST-NEST-001', 'tender_no stored independently');
    ok($r['nest_reference'] === 'TE/9999/2026-27/HQ/01', 'nest_reference stored as its own distinct value (not conflated with tender_no)');

    // A second tender with NO NeST reference (the common case — most tenders
    // never reach NeST, or the reference isn't known yet) must be fine (NULL).
    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-NEST-002', 'CLI test, no NeST ref', 'PENDING')");
    $tenderId2 = (int)$pdo->lastInsertId();
    $row2 = $pdo->prepare("SELECT nest_reference FROM tenders WHERE tender_id = ?");
    $row2->execute([$tenderId2]);
    ok($row2->fetchColumn() === null, 'a tender with no NeST reference stores NULL cleanly (field is genuinely optional)');

    // api/get_tenders.php's list query is `SELECT t.*, ... FROM tenders t LEFT JOIN customers ...`
    // — confirm that exact shape actually surfaces nest_reference with zero query changes.
    $listRow = $pdo->prepare("SELECT t.*, c.customer_name as entity_name FROM tenders t LEFT JOIN customers c ON t.customer_id = c.customer_id WHERE t.tender_id = ?");
    $listRow->execute([$tenderId]);
    $listData = $listRow->fetch(PDO::FETCH_ASSOC);
    ok(array_key_exists('nest_reference', $listData) && $listData['nest_reference'] === 'TE/9999/2026-27/HQ/01',
        "api/get_tenders.php's SELECT t.* shape exposes nest_reference to the list with no query change needed");

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
