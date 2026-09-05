<?php
/**
 * Tender upgrade — Phase C (PPRA Compliance Checklist) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_checklist_cli.php
 *
 * Verifies:
 *   - core/tender_checklist.php, api/tender_checklist.php, the new page and
 *     migration are lint-clean
 *   - tender_checklist_items table exists
 *   - seedTenderChecklist() creates exactly the 19 standard items, all
 *     is_custom = 0 and is_ready = 0
 *   - the "X / N ready" count is computed correctly and N grows when a
 *     custom item is added (not hard-coded to 19)
 *   - a standard item (is_custom = 0) is NOT deletable — the API's own guard
 *     query is replicated here and asserted to exclude it
 *   - a custom item (is_custom = 1) IS deletable
 *   - deleting a tender cascades to its checklist items
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_checklist.php";
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
        'core/tender_checklist.php',
        'api/tender_checklist.php',
        'app/bms/tenders/tender_checklist.php',
        'app/bms/tenders/tender_create.php',
        'app/bms/tenders/_tender_nav.php',
        'migrations/2026_09_05_tender_checklist.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    ok(function_exists('seedTenderChecklist'), 'seedTenderChecklist() is defined');
    ok(function_exists('tenderChecklistStandardItems'), 'tenderChecklistStandardItems() is defined');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — table exists');
    // ─────────────────────────────────────────────────────────────────────
    $tables = $pdo->query("SHOW TABLES LIKE 'tender_checklist_items'")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('tender_checklist_items', $tables, true), 'tender_checklist_items table exists');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Seeding — exactly the 19 standard items, all unticked');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-CHK-001', 'CLI test tender', 'PENDING')");
    $tenderId = (int)$pdo->lastInsertId();

    seedTenderChecklist($pdo, $tenderId);

    $standardCount = count(tenderChecklistStandardItems());
    ok($standardCount === 19, "tenderChecklistStandardItems() returns exactly 19 items (got $standardCount)");

    $rows = $pdo->prepare("SELECT * FROM tender_checklist_items WHERE tender_id = ? ORDER BY sort_order");
    $rows->execute([$tenderId]);
    $seeded = $rows->fetchAll(PDO::FETCH_ASSOC);
    ok(count($seeded) === $standardCount, 'seedTenderChecklist() inserted exactly one row per standard item');
    ok(!array_filter($seeded, fn($r) => (int)$r['is_custom'] !== 0), 'every seeded row has is_custom = 0');
    ok(!array_filter($seeded, fn($r) => (int)$r['is_ready'] !== 0), 'every seeded row starts is_ready = 0');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Ready counter — correct math, grows with custom items (not hard-coded)');
    // ─────────────────────────────────────────────────────────────────────
    $firstThree = array_slice(array_column($seeded, 'item_id'), 0, 3);
    $tickStmt = $pdo->prepare("UPDATE tender_checklist_items SET is_ready = 1 WHERE item_id = ?");
    foreach ($firstThree as $itemId) { $tickStmt->execute([$itemId]); }

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(is_ready) AS ready FROM tender_checklist_items WHERE tender_id = ?");
    $countStmt->execute([$tenderId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    ok((int)$counts['total'] === 19 && (int)$counts['ready'] === 3, "counter reads 3 / 19 ready (got {$counts['ready']} / {$counts['total']})");

    // Add a custom item — N must grow to 20, ready stays 3.
    $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tender_checklist_items WHERE tender_id = ?");
    $orderStmt->execute([$tenderId]);
    $nextOrder = (int)$orderStmt->fetchColumn();
    $pdo->prepare("INSERT INTO tender_checklist_items (tender_id, item_text, is_ready, is_custom, sort_order) VALUES (?, 'Client-specific site permit', 0, 1, ?)")
        ->execute([$tenderId, $nextOrder]);
    $customId = (int)$pdo->lastInsertId();

    $countStmt->execute([$tenderId]);
    $counts2 = $countStmt->fetch(PDO::FETCH_ASSOC);
    ok((int)$counts2['total'] === 20, 'adding a custom item grows the denominator to 20 (not hard-coded at 19)');
    ok((int)$counts2['ready'] === 3, 'ready count is unaffected by adding an unticked custom item');

    // ─────────────────────────────────────────────────────────────────────
    section('5. Delete guard — standard items are protected, custom items are not');
    // ─────────────────────────────────────────────────────────────────────
    $standardItemId = (int)$seeded[0]['item_id'];

    // Replicates api/tender_checklist.php's exact DELETE_ITEM ownership guard.
    $guard = $pdo->prepare("SELECT item_id FROM tender_checklist_items WHERE item_id = ? AND tender_id = ? AND is_custom = 1");
    $guard->execute([$standardItemId, $tenderId]);
    ok($guard->fetch() === false, 'a standard item does NOT pass the is_custom=1 delete guard — API would refuse to delete it');

    $guard->execute([$customId, $tenderId]);
    ok($guard->fetch() !== false, 'the custom item DOES pass the delete guard — API would allow deleting it');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Cascade delete — removing the tender removes its checklist');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM tenders WHERE tender_id = ?")->execute([$tenderId]);
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM tender_checklist_items WHERE tender_id = ?");
    $remaining->execute([$tenderId]);
    ok((int)$remaining->fetchColumn() === 0, 'deleting the tender cascades to its checklist items');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
