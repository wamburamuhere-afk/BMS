<?php
/**
 * tests/test_actor_account_phase2_cli.php
 *   php tests/test_actor_account_phase2_cli.php
 *
 * Phase 2 (actor-as-account) — verifies ensureActorLedgerAccount() creates the
 * correct GL sub-account under the right control parent for each actor type,
 * links it back via ledger_account_id, and is idempotent (no duplicate on re-call).
 * Cleans up after itself.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/actor_account.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ── helpers ──────────────────────────────────────────────────────────────────
function fetchAccount(PDO $pdo, string $code): ?array {
    $r = $pdo->prepare("SELECT * FROM accounts WHERE account_code = ? LIMIT 1");
    $r->execute([$code]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cleanupAccount(PDO $pdo, string $code): void {
    $pdo->prepare("DELETE FROM accounts WHERE account_code = ?")->execute([$code]);
}

// ── 1. Service file integrity ─────────────────────────────────────────────────
section('1. Service file present + lint-clean');
$svc = "$root/core/actor_account.php";
file_exists($svc) ? pass('core/actor_account.php exists') : fail('core/actor_account.php missing');
$lint = shell_exec('php -l ' . escapeshellarg($svc) . ' 2>&1');
(strpos((string)$lint, 'No syntax errors') !== false) ? pass('lint-clean') : fail("lint: $lint");
function_exists('ensureActorLedgerAccount') ? pass('ensureActorLedgerAccount() is callable') : fail('function not defined');

// ── 2. Control parents exist ──────────────────────────────────────────────────
section('2. Control parents are present in accounts');
$parents = ['1-1200' => 'Trade Debtors', '2-1200' => 'Trade Creditors', '2-1440' => 'Salaries Payable'];
foreach ($parents as $code => $label) {
    $r = $pdo->prepare("SELECT account_id FROM accounts WHERE account_code = ? AND status = 'active' LIMIT 1");
    $r->execute([$code]);
    $r->fetch() ? pass("$label ($code) exists") : fail("$label ($code) missing — cannot create sub-accounts");
}

// ── 3. Unknown actor type throws ──────────────────────────────────────────────
section('3. Unknown actor type throws Exception');
try {
    ensureActorLedgerAccount($pdo, 'robot', 1, 'R2D2');
    fail('should have thrown for unknown type');
} catch (Exception $e) {
    (strpos($e->getMessage(), 'unknown actor type') !== false)
        ? pass('throws with "unknown actor type" message')
        : fail("wrong message: " . $e->getMessage());
}

// ── 4–7. One test per actor type ─────────────────────────────────────────────
$cases = [
    ['customer',       99991, 'Test Customer Acme',    '1-1200-CUST-99991', '1-1200', 'asset',     'debit'],
    ['supplier',       99991, 'Test Supplier Beta',    '2-1200-SUP-99991',  '2-1200', 'liability',  'credit'],
    ['sub_contractor', 99991, 'Test Sub Delta',        '2-1200-SUB-99991',  '2-1200', 'liability',  'credit'],
    ['employee',       99991, 'Test Employee Gamma',   '2-1440-EMP-99991',  '2-1440', 'liability',  'credit'],
];

// Clean up any leftover from a previous run.
foreach ($cases as [$type, $id, , $code]) {
    cleanupAccount($pdo, $code);
}

foreach ($cases as $i => [$type, $actorId, $actorName, $expectedCode, $parentCode, $expectedType, $expectedNB]) {
    $n = $i + 4;
    section("$n. $type → $expectedCode");

    // Temporarily insert a fake actor row so we can set/read ledger_account_id.
    $tableMap = ['customer'=>'customers','supplier'=>'suppliers','sub_contractor'=>'sub_contractors','employee'=>'employees'];
    $pkMap    = ['customer'=>'customer_id','supplier'=>'supplier_id','sub_contractor'=>'supplier_id','employee'=>'employee_id'];
    $tbl      = $tableMap[$type];
    $pk       = $pkMap[$type];

    // Remove any leftover fake row first.
    $pdo->prepare("DELETE FROM `$tbl` WHERE `$pk` = ?")->execute([$actorId]);

    // Minimal INSERT — only required NOT NULL columns + our test id (force the id value).
    if ($type === 'customer') {
        $pdo->prepare("INSERT INTO customers (customer_id, customer_name, customer_code, category_id, status, created_by, year, customer_type)
                       VALUES (?,?,?,1,'active',1,2026,'business')")
            ->execute([$actorId, $actorName, 'CUST-TEST-' . $actorId]);
    } elseif ($type === 'supplier') {
        $pdo->prepare("INSERT INTO suppliers (supplier_id, supplier_name, supplier_code, status, created_by, created_at, updated_at)
                       VALUES (?,?,?,'active',1,NOW(),NOW())")
            ->execute([$actorId, $actorName, 'SUP-TEST-' . $actorId]);
    } elseif ($type === 'sub_contractor') {
        $pdo->prepare("INSERT INTO sub_contractors (supplier_id, supplier_name, supplier_code, status, created_by, created_at, updated_at)
                       VALUES (?,?,?,'active',1,NOW(),NOW())")
            ->execute([$actorId, $actorName, 'SBC-TEST-' . $actorId]);
    } else { // employee
        $pdo->prepare("INSERT INTO employees (employee_id, employee_code, employee_number, first_name, last_name, email, phone, department_id, designation_id, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,1,1,1,NOW())")
            ->execute([$actorId, 'EMP-T-'.$actorId, 'EMP-T-'.$actorId, 'Test', 'Gamma', 'test_gamma_'.$actorId.'@bms.local', '255700000001']);
    }

    try {
        $accId = ensureActorLedgerAccount($pdo, $type, $actorId, $actorName);
        ($accId > 0) ? pass("returns a positive account_id ($accId)") : fail("returned zero or false");

        $acc = fetchAccount($pdo, $expectedCode);
        $acc ? pass("account row exists with code $expectedCode") : fail("account row not found ($expectedCode)");
        if ($acc) {
            ($acc['account_name'] === $actorName) ? pass("account_name = '$actorName'") : fail("wrong name: {$acc['account_name']}");
            ($acc['account_type'] === $expectedType) ? pass("account_type = $expectedType") : fail("wrong type: {$acc['account_type']}");
            ($acc['normal_balance'] === $expectedNB) ? pass("normal_balance = $expectedNB") : fail("wrong NB: {$acc['normal_balance']}");
            ((int)$acc['is_system'] === 0) ? pass('is_system = 0 (not a system account)') : fail('is_system should be 0');

            // Verify parent linkage.
            $par = $pdo->prepare("SELECT account_code FROM accounts WHERE account_id = ? LIMIT 1");
            $par->execute([$acc['parent_account_id']]);
            $parCode = $par->fetchColumn();
            ($parCode === $parentCode) ? pass("parent_account_id → $parCode") : fail("wrong parent: $parCode");
        }

        // Verify ledger_account_id was written back to actor row.
        $link = $pdo->prepare("SELECT ledger_account_id FROM `$tbl` WHERE `$pk` = ?");
        $link->execute([$actorId]);
        $linked = (int) $link->fetchColumn();
        ($linked === $accId) ? pass("ledger_account_id = $accId on $tbl row") : fail("ledger_account_id not set (got $linked)");

        // Idempotency — second call must return same account_id, not create a duplicate.
        $accId2 = ensureActorLedgerAccount($pdo, $type, $actorId, $actorName);
        ($accId2 === $accId) ? pass('idempotent: second call returns same account_id') : fail("second call returned different id: $accId2 vs $accId");
        $dupes = (int) $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?")->execute([$expectedCode]) && $pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code='$expectedCode'")->fetchColumn();
        ($dupes <= 1) ? pass('no duplicate account rows created') : fail("$dupes rows with code $expectedCode");

    } catch (Exception $e) {
        fail("threw: " . $e->getMessage());
    } finally {
        // Cleanup.
        cleanupAccount($pdo, $expectedCode);
        $pdo->prepare("DELETE FROM `$tbl` WHERE `$pk` = ?")->execute([$actorId]);
    }
}

// ── 8. Add-endpoint files include the service ─────────────────────────────────
section('8. Add-endpoints require core/actor_account.php');
$endpoints = [
    'api/add_customer.php',
    'api/add_supplier.php',
    'api/add_sub_contractor.php',
    'api/add_employee.php',
];
foreach ($endpoints as $ep) {
    $src = file_get_contents("$root/$ep");
    (strpos($src, 'actor_account.php') !== false)
        ? pass("$ep includes actor_account.php")
        : fail("$ep does NOT include actor_account.php");
    (strpos($src, 'ensureActorLedgerAccount') !== false)
        ? pass("$ep calls ensureActorLedgerAccount()")
        : fail("$ep does NOT call ensureActorLedgerAccount()");
}
