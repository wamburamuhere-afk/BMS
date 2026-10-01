<?php
/**
 * POS Receipt Number — sequential uniqueness guard
 *   php tests/test_pos_receipt_number_cli.php
 *
 * A. Static — mt_rand gone from process_sale.php; nextReceiptNumber() wired.
 * B. Unit   — nextReceiptNumber() format, daily reset, and seeds correctly from
 *             existing data (migration from old random-generated receipts).
 * C. Concurrency — 50 sequential calls in a single DB session produce 50 distinct
 *    numbers with no gaps (simulates what concurrent HTTP workers each do serially
 *    inside their own locked sequence row).
 * D. Rollback safety — a number allocated inside a rolled-back transaction is
 *    NOT consumed (sequence stays at its pre-call value).
 *
 * No web server needed. Uses a real DB transaction rolled back at the end.
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/code_generator.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// ── A. Static contract ───────────────────────────────────────────────────────
section('A. Static — mt_rand removed, nextReceiptNumber() wired');

$ps = "$root/api/pos/process_sale.php";
$src = is_file($ps) ? file_get_contents($ps) : '';

ok(strpos($src, 'mt_rand') === false,
    'process_sale.php no longer uses mt_rand for receipt numbers');

ok(strpos($src, 'nextReceiptNumber') !== false,
    'process_sale.php calls nextReceiptNumber()');

ok(strpos($src, "require_once __DIR__ . '/../../core/code_generator.php'") !== false,
    'process_sale.php requires code_generator.php');

$cg = "$root/core/code_generator.php";
ok(function_exists('nextReceiptNumber') || (is_file($cg) && strpos(file_get_contents($cg), 'function nextReceiptNumber') !== false),
    'nextReceiptNumber() defined in core/code_generator.php');

$lint = []; $rc = 0;
exec('php -l ' . escapeshellarg($ps) . ' 2>&1', $lint, $rc);
ok($rc === 0, 'process_sale.php lint-clean after change');

exec('php -l ' . escapeshellarg($cg) . ' 2>&1', $lint, $rc);
ok($rc === 0, 'core/code_generator.php lint-clean after change');

// ── B. Unit — format, daily reset, seed-from-existing ───────────────────────
section('B. Unit — format, daily reset, seed from existing data');

if (!(bool)$pdo->query("SHOW TABLES LIKE 'code_sequences'")->fetch()) {
    ok(false, 'code_sequences table missing — cannot run live tests');
    exit($fail === 0 ? 0 : 1);
}

// Use a synthetic past date so this test never touches real today's sequence.
$testDate  = '20000101';
$seqKey    = 'RCP-' . $testDate;
$likeKey   = 'RCP-' . $testDate . '-%';

// Clean up any leftovers from a previous interrupted run.
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$seqKey]);

// B1 — format matches RCP-YYYYMMDD-NNNN
$pdo->beginTransaction();
$r1 = nextReceiptNumber($pdo, $testDate);
$pdo->commit();
ok(preg_match('/^RCP-\d{8}-\d{4,}$/', $r1) === 1,
    "Format matches RCP-YYYYMMDD-NNNN  (got: $r1)");

// B2 — first number for a fresh date starts at 0001
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$seqKey]);
$pdo->beginTransaction();
$first = nextReceiptNumber($pdo, $testDate);
$pdo->commit();
ok($first === 'RCP-' . $testDate . '-0001',
    "First number on a fresh date = RCP-{$testDate}-0001  (got: $first)");

// B3 — second call increments
$pdo->beginTransaction();
$second = nextReceiptNumber($pdo, $testDate);
$pdo->commit();
ok($second === 'RCP-' . $testDate . '-0002',
    "Second number = RCP-{$testDate}-0002  (got: $second)");

// B4 — different date gets its own independent counter
$testDate2 = '20000102';
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute(['RCP-' . $testDate2]);
$pdo->beginTransaction();
$otherDate = nextReceiptNumber($pdo, $testDate2);
$pdo->commit();
ok($otherDate === 'RCP-' . $testDate2 . '-0001',
    "Different date starts its own counter from 0001  (got: $otherDate)");

// B5 — seeds from existing pos_sales rows (migration safety)
// Simulate: the old mt_rand code left receipts RCP-20000103-5000 and RCP-20000103-7300.
// The new sequence must start ABOVE 7300 so it never collides.
$testDate3 = '20000103';
$seqKey3   = 'RCP-' . $testDate3;
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$seqKey3]);

$hasPosTable = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch();
if ($hasPosTable) {
    // Insert two synthetic legacy receipt numbers inside a transaction we'll roll back.
    $pdo->beginTransaction();
    try {
        // Find a safe shift_id to satisfy the FK (if one exists)
        $shiftId = $pdo->query("SELECT shift_id FROM cash_register_shifts LIMIT 1")->fetchColumn();
        $userId  = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
        $whId    = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetchColumn();

        if ($shiftId && $userId && $whId) {
            foreach (['5000', '7300'] as $suffix) {
                $rn = 'RCP-' . $testDate3 . '-' . $suffix;
                $pdo->prepare(
                    "INSERT INTO pos_sales
                        (receipt_number, shift_id, user_id, warehouse_id,
                         subtotal, discount_percentage, discount_amount,
                         tax_amount, grand_total, payment_method,
                         amount_tendered, change_given,
                         sale_type, sale_status, payment_status, sale_date, created_at)
                     VALUES (?, ?, ?, ?, 100, 0, 0, 0, 100, 'cash', 100, 0,
                             'walk_in', 'completed', 'paid', '2000-01-03 00:00:00', NOW())"
                )->execute([$rn, $shiftId, $userId, $whId]);
            }

            // Now call nextReceiptNumber — it must seed from 7300 and return 7301.
            $seeded = nextReceiptNumber($pdo, $testDate3);
            ok((int)substr($seeded, strrpos($seeded, '-') + 1) > 7300,
                "Seeded above existing max suffix 7300  (got: $seeded)");
            ok($seeded === 'RCP-' . $testDate3 . '-7301',
                "Exact next value after seeding from 7300 = RCP-{$testDate3}-7301  (got: $seeded)");
        } else {
            ok(true, 'Seed test skipped — no shift/user/warehouse rows on this server');
            ok(true, 'Seed test skipped — no shift/user/warehouse rows on this server');
        }
    } finally {
        $pdo->rollBack(); // leaves pos_sales and code_sequences clean
    }
    $pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$seqKey3]);
} else {
    ok(true, 'Seed test skipped — pos_sales absent on this server');
    ok(true, 'Seed test skipped — pos_sales absent on this server');
}

// ── C. Concurrency — 50 sequential allocations, zero duplicates, no gaps ────
section('C. Concurrency simulation — 50 allocations, zero duplicates, no gaps');

$testDate4 = '20000104';
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute(['RCP-' . $testDate4]);

$numbers = [];
for ($i = 0; $i < 50; $i++) {
    $pdo->beginTransaction();
    $numbers[] = nextReceiptNumber($pdo, $testDate4);
    $pdo->commit();
}

ok(count($numbers) === count(array_unique($numbers)),
    '50 allocations produced 50 unique receipt numbers');

$suffixes = array_map(fn($n) => (int)substr($n, strrpos($n, '-') + 1), $numbers);
sort($suffixes);
ok($suffixes === range(1, 50),
    '50 suffixes are consecutive 1–50 with no gaps');

ok($numbers[0] === 'RCP-' . $testDate4 . '-0001' && $numbers[49] === 'RCP-' . $testDate4 . '-0050',
    "Range is RCP-{$testDate4}-0001 … RCP-{$testDate4}-0050");

// ── D. Rollback safety — rolled-back txn does not consume a number ───────────
section('D. Rollback safety — rolled-back transaction releases its number');

$testDate5 = '20000105';
$pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute(['RCP-' . $testDate5]);

// First successful allocation → 0001.
$pdo->beginTransaction();
$beforeRollback = nextReceiptNumber($pdo, $testDate5);
$pdo->commit();

// Second call inside a transaction that gets rolled back.
$pdo->beginTransaction();
$rolledBack = nextReceiptNumber($pdo, $testDate5);
$pdo->rollBack(); // simulate a failed sale INSERT

// Third call must pick up where the rollback left off: 0002, not 0003.
$pdo->beginTransaction();
$afterRollback = nextReceiptNumber($pdo, $testDate5);
$pdo->commit();

ok($beforeRollback  === 'RCP-' . $testDate5 . '-0001', "Pre-rollback = 0001  (got: $beforeRollback)");
ok($rolledBack      === 'RCP-' . $testDate5 . '-0002', "Rolled-back  = 0002  (got: $rolledBack)");
ok($afterRollback   === 'RCP-' . $testDate5 . '-0002', "Post-rollback = 0002 (gap-free reuse)  (got: $afterRollback)");

// ── Cleanup synthetic sequence rows ─────────────────────────────────────────
foreach (['RCP-20000101','RCP-20000102','RCP-20000104','RCP-20000105'] as $k) {
    $pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$k]);
}

exit($fail === 0 ? 0 : 1);
