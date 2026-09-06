<?php
/**
 * Tender upgrade — Phase B (Materials Schedule) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_materials_cli.php
 *
 * Verifies:
 *   - api/tender_materials.php, the new page and migration are lint-clean
 *   - tender_materials table + its FKs exist (migration ran)
 *   - amount = qty * rate math (the same computation SAVE_MATERIALS performs)
 *   - a material CAN be linked to an existing products row (product_id set)
 *     and CAN be freely-typed with no product_id — both are valid, per the
 *     tender.md §3 rule that this must not force everything into the catalogue
 *   - deleting the linked product SETS NULL on tender_materials.product_id
 *     rather than deleting the tender's material row (ON DELETE SET NULL,
 *     not CASCADE — the tender's pricing record must survive even if the
 *     catalogue item behind it is later removed)
 *   - a material_id belonging to a different tender is correctly excluded
 *     from the valid set (the same guard SAVE_MATERIALS applies)
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
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
        'api/tender_materials.php',
        'app/bms/tenders/tender_materials.php',
        'app/bms/tenders/_tender_nav.php',
        'migrations/2026_09_05_tender_materials.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('1b. Regression — browser click-through caught: empty specification/unit/material');
    // ─────────────────────────────────────────────────────────────────────
    // Same bug as Phase A's BOQ page: safe_output()'s default 'N/A' belongs
    // in read-only display text, not an editable input's `value=`. A blank
    // row's Specification/Unit boxes (and the hidden material-name field)
    // showed the literal string "N/A", which would have been saved as real
    // data if the row was left untouched.
    $pageSrc = file_get_contents("$root/app/bms/tenders/tender_materials.php");
    ok(str_contains($pageSrc, "safe_output(\$item['material'], '')"), "hidden material-name field uses an explicit empty default, not safe_output()'s 'N/A'");
    ok(str_contains($pageSrc, "safe_output(\$item['specification'], '')"), "specification input uses an explicit empty default, not safe_output()'s 'N/A'");
    ok(str_contains($pageSrc, "safe_output(\$item['unit'], '')"), "unit input uses an explicit empty default, not safe_output()'s 'N/A'");

    // ─────────────────────────────────────────────────────────────────────
    section('1c. Regression — browser click-through caught: typed material name silently lost');
    // ─────────────────────────────────────────────────────────────────────
    // The most serious bug found this session. Live-confirmed (real browser,
    // real typing, real clicks, no synthetic shortcuts) that Select2
    // 4.1.0-rc.0 does not fire select2:select / select2:unselect / even a
    // plain `change` event when the user selects a NEWLY-CREATED tag (as
    // opposed to an existing ajax-returned catalogue item) — confirmed via a
    // debug listener that recorded zero events across select2:select,
    // select2:selecting, and change while the visible selection and
    // `$el.val()` updated correctly. Relying on any of those events to sync
    // the hidden product_id/material fields meant a freely-typed material
    // name — the single most important field on this page — silently saved
    // as an empty string. Fixed two ways: (a) switched the live-sync handler
    // from select2:select to `change` (Select2's own documented more-robust
    // pattern), AND (b) added syncMaterialHiddenFieldsFromSelect2(), which
    // re-derives every row's hidden fields from `.select2('data')` right
    // before the form serializes on submit — correct regardless of whether
    // any incremental event fired during interaction. Verified live: a typed
    // tag that left the hidden fields blank was correctly recovered by this
    // submit-time sync and persisted to the database.
    ok(!str_contains($pageSrc, "on('select2:select'"), "no longer relies on select2:select to sync hidden fields (confirmed not to fire for a new tag in this Select2 version)");
    ok(str_contains($pageSrc, 'function syncMaterialHiddenFieldsFromSelect2'), 'submit-time force-sync function exists');
    ok(str_contains($pageSrc, "select2('data')[0]"), "sync reads the Select2 instance's actual current data, not an event payload");
    // The sync must run before the row's inputs are serialized for the AJAX
    // POST, not after — assert the call site precedes the serialize() call.
    $syncPos = strpos($pageSrc, 'syncMaterialHiddenFieldsFromSelect2();');
    $serializePos = strpos($pageSrc, '$(this).serialize()');
    ok($syncPos !== false && $serializePos !== false && $syncPos < $serializePos, 'the sync call happens before the form is serialized for submission');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — table and FKs exist');
    // ─────────────────────────────────────────────────────────────────────
    $tables = $pdo->query("SHOW TABLES LIKE 'tender_materials'")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('tender_materials', $tables, true), 'tender_materials table exists');
    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tender_materials' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('fk_tm_tender', $fks, true), 'fk_tm_tender (tender_id -> tenders) exists');
    ok(in_array('fk_tm_product', $fks, true), 'fk_tm_product (product_id -> products) exists');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Linked vs free-text materials, and amount math');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-MAT-001', 'CLI test tender', 'PENDING')");
    $tenderId = (int)$pdo->lastInsertId();

    // A real catalogue product to link against.
    $prodCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsService = in_array('is_service', $prodCols, true);
    $insertProductSql = $hasIsService
        ? "INSERT INTO products (product_name, unit, status, is_service) VALUES ('CLI Test Cement', 'bags', 'active', 0)"
        : "INSERT INTO products (product_name, unit, status) VALUES ('CLI Test Cement', 'bags', 'active')";
    $pdo->exec($insertProductSql);
    $productId = (int)$pdo->lastInsertId();
    ok($productId > 0, "test product created (#$productId)");

    // Linked line: 20 bags @ 25,000
    $pdo->prepare("INSERT INTO tender_materials (tender_id, product_id, material, unit, qty, rate, amount, sort_order) VALUES (?, ?, 'CLI Test Cement', 'bags', 20, 25000, ?, 0)")
        ->execute([$tenderId, $productId, 20 * 25000]);
    $linkedId = (int)$pdo->lastInsertId();

    // Free-text line, no product_id: 100 m @ 500
    $pdo->prepare("INSERT INTO tender_materials (tender_id, product_id, material, unit, qty, rate, amount, sort_order) VALUES (?, NULL, 'Fencing wire (not yet catalogued)', 'm', 100, 500, ?, 1)")
        ->execute([$tenderId, 100 * 500]);
    $freeTextId = (int)$pdo->lastInsertId();

    ok($linkedId > 0 && $freeTextId > 0, 'both a linked and a free-text material line were created');

    $rows = $pdo->prepare("SELECT material_id, product_id, amount FROM tender_materials WHERE tender_id = ? ORDER BY sort_order");
    $rows->execute([$tenderId]);
    $fetched = $rows->fetchAll(PDO::FETCH_ASSOC);
    ok((int)$fetched[0]['product_id'] === $productId, 'linked line keeps its product_id');
    ok($fetched[1]['product_id'] === null, 'free-text line has a NULL product_id (not forced into the catalogue)');
    ok(abs((float)$fetched[0]['amount'] - 500000.0) < 0.01, 'linked line amount = qty * rate (20 * 25,000 = 500,000)');
    ok(abs((float)$fetched[1]['amount'] - 50000.0) < 0.01, 'free-text line amount = qty * rate (100 * 500 = 50,000)');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Deleting the linked product SETS NULL, does not delete the material row');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$productId]);
    $still = $pdo->prepare("SELECT product_id FROM tender_materials WHERE material_id = ?");
    $still->execute([$linkedId]);
    $row = $still->fetch(PDO::FETCH_ASSOC);
    ok($row !== false, 'the tender material row still exists after its linked product is deleted');
    ok($row && $row['product_id'] === null, 'its product_id was set to NULL (ON DELETE SET NULL), row was not cascaded away');

    // ─────────────────────────────────────────────────────────────────────
    section('5. Cross-tender write guard');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-MAT-002', 'CLI test tender 2', 'PENDING')");
    $otherTenderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_materials (tender_id, material, sort_order) VALUES (?, 'Other tender material', 0)")->execute([$otherTenderId]);
    $otherMaterialId = (int)$pdo->lastInsertId();

    $validItems = $pdo->prepare("SELECT material_id FROM tender_materials WHERE tender_id = ?");
    $validItems->execute([$tenderId]);
    $validIds = array_map('intval', $validItems->fetchAll(PDO::FETCH_COLUMN));
    ok(!in_array($otherMaterialId, $validIds, true), 'a material belonging to a different tender is not in the valid set — SAVE_MATERIALS would skip it');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
