<?php
/**
 * Phase 4.1 — journal_mappings schema + seed CLI test
 * ----------------------------------------------------
 *   php tests/test_phase4_journal_mappings_schema_cli.php
 *
 * Verifies:
 *   1. Migration file exists + lint-clean.
 *   2. Migration source contains the agreed structural patterns:
 *      - Idempotent table creation (SHOW TABLES LIKE guard)
 *      - Idempotent FK creation (information_schema check)
 *      - ON DUPLICATE KEY UPDATE seed that ONLY refreshes description
 *        (admin-set fields preserved on re-run)
 *      - All 8 canonical event_type slugs declared in seed
 *      - is_active DEFAULT 0 (kill switch off until admin opt-in)
 *   3. Live-DB schema introspection:
 *      - table exists
 *      - all 8 columns present with correct nullability and defaults
 *      - UNIQUE KEY on event_type
 *      - Both FKs to accounts.account_id present (RESTRICT/CASCADE)
 *   4. Live-DB seed introspection:
 *      - all 8 canonical event_type rows present
 *      - all default debit_account_id IS NULL
 *      - all default credit_account_id IS NULL
 *      - all default is_active = 0
 *   5. Re-running the migration is a no-op (idempotent guarantee):
 *      - row count unchanged before/after
 *      - any admin-set values would survive (proved by checking the
 *        ON DUPLICATE KEY UPDATE clause only mentions `description`)
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$migration = "$root/migrations/2026_05_28_journal_mappings_schema.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Migration file exists + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($migration) ? pass('migration file exists') : fail('migration file missing');
$rc = 0; exec("php -l " . escapeshellarg($migration) . " 2>&1", $o, $rc);
$rc === 0 ? pass('migration lint-clean') : fail('migration lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration source contains agreed structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($migration);
$src_checks = [
    "SHOW TABLES LIKE 'journal_mappings'"                      => 'CREATE guarded by SHOW TABLES LIKE',
    "information_schema.TABLE_CONSTRAINTS"                     => 'FK creation guarded by information_schema check',
    "ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)" => 'seed only refreshes description (admin fields preserved)',
    "is_active"                                                => 'is_active column declared',
    "DEFAULT 0"                                                => 'is_active defaults to 0 (kill switch off)',
    "uq_jm_event_type"                                         => 'UNIQUE KEY on event_type declared',
    "REFERENCES `accounts`(`account_id`)"                      => 'FKs reference accounts.account_id',
    "ON DELETE RESTRICT"                                       => 'FK ON DELETE RESTRICT (cannot delete mapped account)',
    "'invoice_approved'"                                       => 'seed includes invoice_approved',
    "'payment_received'"                                       => 'seed includes payment_received',
    "'expense_paid'"                                           => 'seed includes expense_paid',
    "'payroll_paid'"                                           => 'seed includes payroll_paid',
    "'grn_approved'"                                           => 'seed includes grn_approved',
    "'supplier_payment'"                                       => 'seed includes supplier_payment',
    "'asset_purchased'"                                        => 'seed includes asset_purchased',
    "'depreciation_run'"                                       => 'seed includes depreciation_run',
];
foreach ($src_checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB schema introspection');
// ─────────────────────────────────────────────────────────────────────────
$exists = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
$exists ? pass('table journal_mappings exists') : fail('table journal_mappings missing');

if ($exists) {
    $colStmt = $pdo->query("SHOW COLUMNS FROM journal_mappings");
    $cols = [];
    while ($r = $colStmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = $r;

    $expected_cols = [
        'id'                => ['Null' => 'NO',  'Key' => 'PRI'],
        'event_type'        => ['Null' => 'NO',  'Key' => 'UNI'],
        'description'       => ['Null' => 'NO'],
        'debit_account_id'  => ['Null' => 'YES'],
        'credit_account_id' => ['Null' => 'YES'],
        'is_active'         => ['Null' => 'NO',  'Default' => '0'],
        'notes'             => ['Null' => 'YES'],
        'created_at'        => ['Null' => 'NO'],
        'updated_at'        => ['Null' => 'NO'],
    ];
    foreach ($expected_cols as $col => $expected) {
        if (!isset($cols[$col])) { fail("column $col missing"); continue; }
        $ok = true; $msg = "column $col present";
        foreach ($expected as $attr => $exp_val) {
            if ($cols[$col][$attr] !== $exp_val) {
                $ok = false; $msg .= " — $attr expected '$exp_val', got '" . $cols[$col][$attr] . "'";
                break;
            }
        }
        $ok ? pass($msg) : fail($msg);
    }

    // UNIQUE KEY check
    $idx = $pdo->query("SHOW INDEX FROM journal_mappings WHERE Key_name = 'uq_jm_event_type'")->fetch();
    $idx ? pass('UNIQUE KEY uq_jm_event_type present') : fail('UNIQUE KEY uq_jm_event_type missing');

    // FK checks
    foreach ([
        'fk_jm_debit_acct'  => 'debit_account_id',
        'fk_jm_credit_acct' => 'credit_account_id',
    ] as $fk => $col) {
        $fkRow = $pdo->prepare("
            SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
              FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?
        ");
        $fkRow->execute([$fk]);
        $r = $fkRow->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            fail("FK $fk missing");
        } else {
            $r['DELETE_RULE'] === 'RESTRICT' ? pass("FK $fk ON DELETE RESTRICT") : fail("FK $fk delete rule = {$r['DELETE_RULE']} (expected RESTRICT)");
            $r['UPDATE_RULE'] === 'CASCADE'  ? pass("FK $fk ON UPDATE CASCADE")  : fail("FK $fk update rule = {$r['UPDATE_RULE']} (expected CASCADE)");
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Live-DB seed rows');
// ─────────────────────────────────────────────────────────────────────────
$expected_events = [
    'invoice_approved', 'payment_received', 'expense_paid', 'payroll_paid',
    'grn_approved', 'supplier_payment', 'asset_purchased', 'depreciation_run',
];
$rows = $pdo->query("SELECT event_type, debit_account_id, credit_account_id, is_active FROM journal_mappings")->fetchAll(PDO::FETCH_ASSOC);
$by_event = [];
foreach ($rows as $r) $by_event[$r['event_type']] = $r;

foreach ($expected_events as $event) {
    if (!isset($by_event[$event])) { fail("seed row missing: $event"); continue; }
    $r = $by_event[$event];
    $ok = $r['debit_account_id']  === null
       && $r['credit_account_id'] === null
       && (int)$r['is_active']    === 0;
    $ok ? pass("seed row $event: NULL/NULL/inactive (safe defaults)")
        : fail("seed row $event has unsafe defaults: dr=" . var_export($r['debit_account_id'], true) .
               " cr=" . var_export($r['credit_account_id'], true) . " active=" . $r['is_active']);
}

count($rows) === count($expected_events)
    ? pass("exactly " . count($expected_events) . " seed rows present, no extras")
    : fail("seed row count = " . count($rows) . ", expected " . count($expected_events));

// ─────────────────────────────────────────────────────────────────────────
section('5. Re-running migration is a no-op (idempotent)');
// ─────────────────────────────────────────────────────────────────────────
$count_before = (int)$pdo->query("SELECT COUNT(*) FROM journal_mappings")->fetchColumn();
exec("php " . escapeshellarg($migration) . " 2>&1", $out2, $rc2);
$rc2 === 0 ? pass('re-run exit code 0') : fail("re-run exit code = $rc2");
$count_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_mappings")->fetchColumn();
$count_before === $count_after
    ? pass("row count unchanged on re-run ($count_after)")
    : fail("row count changed on re-run: before=$count_before after=$count_after");

// Confirm the re-run output reports the seed as "unchanged"
$out_str = implode("\n", $out2);
strpos($out_str, '0 inserted') !== false
    ? pass('re-run reports 0 inserted')
    : fail('re-run did not report 0 inserted: ' . substr($out_str, 0, 200));

exit($failures === 0 ? 0 : 1);
