<?php
/**
 * Phase 0.1 — journal_entries project + source-link schema CLI test
 * -----------------------------------------------------------------
 *   php tests/test_phase0_journal_schema_cli.php
 *
 * Verifies:
 *   1. Migration file exists and is lint-clean.
 *   2. journal_entries has the 3 new columns with correct types + NULL-able.
 *   3. ix_je_project + ix_je_entity indexes exist.
 *   4. FK fk_je_project_id to projects(project_id) exists with ON DELETE SET NULL.
 *   5. Existing rows are intact (count unchanged from pre-migration: 2).
 *   6. The 3 new columns can be SET via UPDATE without violating constraints.
 *   7. NULL project_id is accepted (company-wide entry).
 *   8. Setting project_id to a non-existent project fails (FK works).
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

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
section('1. Migration file exists and is lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$migrationFile = "$root/migrations/2026_05_28_journal_entries_project_and_source_link.php";
if (!file_exists($migrationFile)) {
    fail('migration file missing');
} else {
    pass('migration file exists');
    $rc = 0;
    exec("php -l " . escapeshellarg($migrationFile) . " 2>&1", $out, $rc);
    $rc === 0 ? pass('migration is lint-clean') : fail('migration php -l failed: ' . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. journal_entries has the 3 new columns (live DB)');
// ─────────────────────────────────────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM journal_entries")->fetchAll(PDO::FETCH_ASSOC);
$colMap = [];
foreach ($cols as $c) $colMap[$c['Field']] = $c;

$expected = [
    'project_id'  => ['type_hint' => 'int',         'null' => 'YES'],
    'entity_id'   => ['type_hint' => 'unsigned',    'null' => 'YES'],
    'entity_type' => ['type_hint' => 'varchar(50)', 'null' => 'YES'],
];
foreach ($expected as $col => $expect) {
    if (!isset($colMap[$col])) { fail("$col column missing"); continue; }
    $type_ok = stripos($colMap[$col]['Type'], $expect['type_hint']) !== false;
    $null_ok = $colMap[$col]['Null'] === $expect['null'];
    if ($type_ok && $null_ok) {
        pass("$col present (type matches `{$expect['type_hint']}`, NULL=YES)");
    } else {
        fail("$col mismatch: got type={$colMap[$col]['Type']} null={$colMap[$col]['Null']}, expected ~$type_hint NULL=YES");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Required indexes present');
// ─────────────────────────────────────────────────────────────────────────
$ixProj = $pdo->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'ix_je_project'")->fetchAll();
count($ixProj) === 1 && $ixProj[0]['Column_name'] === 'project_id'
    ? pass('ix_je_project on (project_id) exists')
    : fail('ix_je_project missing or wrong columns');

$ixEnt = $pdo->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'ix_je_entity'")->fetchAll();
if (count($ixEnt) === 2 && $ixEnt[0]['Column_name'] === 'entity_type' && $ixEnt[1]['Column_name'] === 'entity_id') {
    pass('ix_je_entity (entity_type, entity_id) composite index exists');
} else {
    fail('ix_je_entity missing or wrong column order');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. FK fk_je_project_id to projects(project_id)');
// ─────────────────────────────────────────────────────────────────────────
$fk = $pdo->query("
    SELECT kcu.CONSTRAINT_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
      FROM information_schema.KEY_COLUMN_USAGE kcu
      JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
       AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
     WHERE kcu.TABLE_SCHEMA = DATABASE()
       AND kcu.TABLE_NAME = 'journal_entries'
       AND kcu.CONSTRAINT_NAME = 'fk_je_project_id'
")->fetch(PDO::FETCH_ASSOC);
if ($fk && $fk['REFERENCED_TABLE_NAME'] === 'projects' && $fk['REFERENCED_COLUMN_NAME'] === 'project_id') {
    pass('FK fk_je_project_id → projects(project_id) exists');
    if ($fk['DELETE_RULE'] === 'SET NULL') pass("FK ON DELETE = 'SET NULL' (correct)");
    else fail("FK ON DELETE rule is `{$fk['DELETE_RULE']}`, expected SET NULL");
} else {
    fail('FK fk_je_project_id missing or wrong target');
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Existing rows preserved');
// ─────────────────────────────────────────────────────────────────────────
$n = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$n >= 2 ? pass("journal_entries has $n row(s) — existing data intact") : fail("journal_entries row count dropped to $n");

// ─────────────────────────────────────────────────────────────────────────
section('6. UPDATE round-trip: set + clear the new columns');
// ─────────────────────────────────────────────────────────────────────────
// Take a real existing row, set + read + clear. Wrapped in a savepoint so
// the test never mutates real data.
$pdo->beginTransaction();
try {
    $row = $pdo->query("SELECT entry_id FROM journal_entries LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) { fail('no journal_entries row to test against'); $pdo->rollBack(); }
    else {
        $eid = (int)$row['entry_id'];
        $stmt = $pdo->prepare("UPDATE journal_entries SET project_id = NULL, entity_id = 42, entity_type = 'invoice' WHERE entry_id = ?");
        $stmt->execute([$eid]);

        $reread = $pdo->prepare("SELECT project_id, entity_id, entity_type FROM journal_entries WHERE entry_id = ?");
        $reread->execute([$eid]);
        $got = $reread->fetch(PDO::FETCH_ASSOC);
        if ($got['project_id'] === null && (int)$got['entity_id'] === 42 && $got['entity_type'] === 'invoice') {
            pass('UPDATE accepts the 3 new columns + readback matches');
        } else {
            fail('UPDATE roundtrip mismatch: ' . json_encode($got));
        }
        $pdo->rollBack();
        pass('test mutations rolled back (no production data changed)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('UPDATE roundtrip exception: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('7. FK enforced — setting project_id to non-existent project fails');
// ─────────────────────────────────────────────────────────────────────────
$badPid = 999999;
$exists = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE project_id = $badPid")->fetchColumn();
if ($exists > 0) {
    pass("skipped: project_id=$badPid happens to exist (very unlikely)");
} else {
    $pdo->beginTransaction();
    try {
        $row = $pdo->query("SELECT entry_id FROM journal_entries LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("UPDATE journal_entries SET project_id = ? WHERE entry_id = ?");
        $stmt->execute([$badPid, (int)$row['entry_id']]);
        // If we got here without exception, FK didn't enforce.
        fail('FK did NOT enforce: setting project_id=999999 was accepted');
        $pdo->rollBack();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            pass('FK enforced: bad project_id rejected with SQLSTATE 23000');
        } else {
            fail('Got unexpected SQLSTATE: ' . $e->getCode() . ' — ' . $e->getMessage());
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

exit($failures === 0 ? 0 : 1);
