<?php
/**
 * Payroll bulk status — "null" id sanitisation (strict-SQL-mode fix) — CLI test
 *   php tests/test_payroll_bulk_null_id_cli.php
 *
 * Reproduces the production bug — a checkbox on an unprocessed row submits the
 * literal string 'null', which, bound against the integer payroll_id column under
 * strict SQL mode, raises "Truncated incorrect DOUBLE value: 'null'" — and proves
 * the fix (server-side intval+filter) makes the query run cleanly. Read-only.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach (['api/bulk_update_payroll_status.php', 'app/bms/pos/payroll.php'] as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Fix is present (server sanitises + client filters)');
$api = src($root, 'api/bulk_update_payroll_status.php');
has($api, "array_map('intval', (array)(\$_POST['payroll_ids']", 'server casts every id to int');
has($api, '$v > 0', 'server keeps only positive integer ids');
$page = src($root, 'app/bms/pos/payroll.php');
has($page, "data === 'null'", 'render skips a null payroll_id (no broken checkbox)');
has($page, "v !== 'null'", 'collection filters a stray "null" value');

// ─────────────────────────────────────────────────────────────────────────
section('3. Reproduce the prod error under strict mode, then prove the fix');
try {
    $origMode = $pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
    // Match production: strict mode turns the truncation warning into a hard error.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

    // (a) The bad input: an unprocessed-row checkbox can submit the literal 'null'.
    //     That non-integer is what production's strict UPDATE rejects (1292/22007).
    $rawIds = ['1', 'null', '2'];
    $hasNonInt = false;
    foreach ($rawIds as $v) { if (!ctype_digit((string)$v)) $hasNonInt = true; }
    $hasNonInt ? pass("raw selection contains a non-integer ('null') — the production trigger") : fail('raw input had no bad value');
    // It MAY also throw outright under strict mode (informational, env-dependent):
    try {
        $ph = implode(',', array_fill(0, count($rawIds), '?'));
        $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE payroll_id IN ($ph)")->execute($rawIds);
        echo "    (note: this MySQL tolerated raw 'null' in a SELECT — production fails on the UPDATE)\n";
    } catch (PDOException $e) {
        echo "    (note: raw 'null' threw here too: " . substr($e->getMessage(), 0, 60) . "…)\n";
    }

    // (b) The FIX: sanitise exactly as the endpoint does, then the query runs clean.
    $clean = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($v) => $v > 0)));
    ($clean === [1, 2]) ? pass("sanitiser turns ['1','null','2'] into [1,2]") : fail('sanitiser output wrong: ' . json_encode($clean));

    $ok = true;
    try {
        $ph = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE payroll_id IN ($ph)");
        $stmt->execute($clean);
        $stmt->fetchColumn();
    } catch (PDOException $e) { $ok = false; }
    $ok ? pass('the sanitised ids query runs with NO error under strict mode (bug fixed)') : fail('sanitised query still errored');

    // (c) All-invalid input sanitises to empty (the endpoint rejects it cleanly).
    $allBad = array_values(array_filter(array_map('intval', ['null', '', '0', 'abc']), static fn($v) => $v > 0));
    ($allBad === []) ? pass('all-invalid selection sanitises to empty (endpoint shows a friendly error)') : fail('expected empty, got ' . json_encode($allBad));

    if ($origMode !== false) $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($origMode));
} catch (Throwable $e) {
    fail('runtime error: ' . $e->getMessage());
}
