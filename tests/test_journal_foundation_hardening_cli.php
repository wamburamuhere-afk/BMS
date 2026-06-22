<?php
/**
 * test_journal_foundation_hardening_cli.php
 * -----------------------------------------
 * Verifies migration 2026_06_22_journal_foundation_hardening.php:
 *   1. Header dr/cr/amount relaxed to NULL (items = single truth).
 *   2. Lifecycle/trace columns + indexes present.
 *   3. journal_entry_items FKs (CASCADE entry, RESTRICT account) present and CASCADE works.
 *   4. journal_source_types catalog seeded.
 *   5. The canonical engine still posts a balanced multi-leg entry.
 *   6. The whole ledger is still balanced.
 *
 * Live-DB safe: the functional post runs inside BEGIN/ROLLBACK — nothing persists.
 */

require_once __DIR__ . '/../includes/config.php';     // $pdo
require_once __DIR__ . '/../core/ledger_post.php';    // postLedgerEntry
global $pdo;

$schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
$pass = 0; $fail = 0;
function check($label, $ok) { global $pass, $fail; echo ($ok ? "  PASS " : "  FAIL ") . $label . "\n"; $ok ? $pass++ : $fail++; }

function colNullable(PDO $pdo, $schema, $table, $col): bool {
    $st = $pdo->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$schema, $table, $col]); return strtoupper((string)$st->fetchColumn()) === 'YES';
}
function colExists(PDO $pdo, $table, $col): bool {
    return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
}
function idxExists(PDO $pdo, $schema, $table, $idx): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$schema, $table, $idx]); return (int)$st->fetchColumn() > 0;
}
function fkExists(PDO $pdo, $schema, $name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $st->execute([$schema, $name]); return (int)$st->fetchColumn() > 0;
}

echo "== 1. Header relaxed to NULL (items = single truth) ==\n";
check("debit_account_id is nullable",  colNullable($pdo, $schema, 'journal_entries', 'debit_account_id'));
check("credit_account_id is nullable", colNullable($pdo, $schema, 'journal_entries', 'credit_account_id'));
check("amount is nullable",            colNullable($pdo, $schema, 'journal_entries', 'amount'));

echo "== 2. Lifecycle + trace columns / indexes ==\n";
check("reverses_entry_id column exists",   colExists($pdo, 'journal_entries', 'reverses_entry_id'));
check("parent_entity_type column exists",  colExists($pdo, 'journal_entries', 'parent_entity_type'));
check("parent_entity_id column exists",    colExists($pdo, 'journal_entries', 'parent_entity_id'));
check("ix_je_parent index exists",         idxExists($pdo, $schema, 'journal_entries', 'ix_je_parent'));
check("ix_je_reverses index exists",       idxExists($pdo, $schema, 'journal_entries', 'ix_je_reverses'));
check("fk_je_reverses self-FK exists",     fkExists($pdo, $schema, 'fk_je_reverses'));

echo "== 3. journal_entry_items integrity ==\n";
check("ix_jei_entry index exists",   idxExists($pdo, $schema, 'journal_entry_items', 'ix_jei_entry'));
check("ix_jei_account index exists", idxExists($pdo, $schema, 'journal_entry_items', 'ix_jei_account'));
check("fk_jei_entry FK exists",      fkExists($pdo, $schema, 'fk_jei_entry'));
check("fk_jei_account FK exists",    fkExists($pdo, $schema, 'fk_jei_account'));

echo "== 4. Source-type catalog ==\n";
$catExists = (bool)$pdo->query("SHOW TABLES LIKE 'journal_source_types'")->fetch();
check("journal_source_types table exists", $catExists);
if ($catExists) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_source_types")->fetchColumn();
    check("catalog seeded (>= 15 rows)", $cnt >= 15);
    foreach (['expense','sales_invoice','invoice_payment','credit_note','supplier_payment','bank_transfer','opening_balance'] as $slug) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM journal_source_types WHERE source_type=?"); $st->execute([$slug]);
        check("catalog has '$slug'", (int)$st->fetchColumn() === 1);
    }
}

echo "== 5. Engine still posts a balanced multi-leg entry (BEGIN/ROLLBACK) ==\n";
$accts = $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (count($accts) < 3) {
    check("need >=3 active accounts to test (skipped)", false);
} else {
    $uid = (int)($pdo->query("SELECT MIN(user_id) FROM users")->fetchColumn() ?: 1);
    $pdo->beginTransaction();
    try {
        $entry_id = postLedgerEntry(
            $pdo,
            'TEST foundation multi-leg entry',
            [
                ['account_id' => (int)$accts[0], 'type' => 'debit',  'amount' => 100.00],
                ['account_id' => (int)$accts[1], 'type' => 'credit', 'amount' => 60.00],
                ['account_id' => (int)$accts[2], 'type' => 'credit', 'amount' => 40.00],
            ],
            null, 999999, 'test_foundation', date('Y-m-d'), $uid
        );
        check("postLedgerEntry returned an entry_id", $entry_id > 0);
        $items = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id = " . (int)$entry_id)->fetchColumn();
        check("3 item legs written", $items === 3);

        // CASCADE: deleting the entry must remove its legs via fk_jei_entry.
        $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entry_id]);
        $after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id = " . (int)$entry_id)->fetchColumn();
        check("ON DELETE CASCADE removed the legs", $after === 0);
    } catch (Throwable $e) {
        check("functional post threw: " . $e->getMessage(), false);
    }
    $pdo->rollBack();   // nothing persists
    // prove isolation
    $left = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='test_foundation'")->fetchColumn();
    check("rolled back — no synthetic rows persisted", $left === 0);
}

echo "== 6. Whole ledger still balanced (posted) ==\n";
$r = $pdo->query("SELECT
    ROUND(SUM(CASE WHEN ji.type='debit' THEN ji.amount ELSE 0 END),2) dr,
    ROUND(SUM(CASE WHEN ji.type='credit' THEN ji.amount ELSE 0 END),2) cr
    FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id
    WHERE je.status='posted'")->fetch(PDO::FETCH_ASSOC);
check("posted ledger balances (Dr == Cr)", abs((float)$r['dr'] - (float)$r['cr']) < 0.01);

echo "\nPasses:   $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
