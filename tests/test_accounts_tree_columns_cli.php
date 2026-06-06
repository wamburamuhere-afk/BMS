<?php
/**
 * Chart of Accounts upgrade — Phase 1 (tree columns) CLI test
 * -----------------------------------------------------------
 *   php tests/test_accounts_tree_columns_cli.php
 *
 * Verifies the additive migration 2026_06_06_accounts_tree_columns.php:
 *   1. the three new columns exist with the right type/default
 *   2. level is backfilled (top accounts = 1, children = parent+1)
 *   3. normal_balance is backfilled from the account's type
 *   4. is_system is flagged for accounts referenced by system_settings
 *      and journal_mappings
 *   5. nothing pre-existing was dropped/renamed (regression: key columns
 *      still present)
 *
 * Read-only: every probe is a SELECT. No data is written. Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// Helper: fetch a column's metadata from accounts, or null.
function col(PDO $pdo, string $name): ?array {
    $r = $pdo->query("SHOW COLUMNS FROM accounts LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New columns exist with correct shape');
    // ─────────────────────────────────────────────────────────────────────
    $level = col($pdo, 'level');
    ok($level !== null, 'accounts.level exists');
    if ($level) ok(stripos($level['Type'], 'int') !== false, "accounts.level is INT (got {$level['Type']})");

    $sys = col($pdo, 'is_system');
    ok($sys !== null, 'accounts.is_system exists');
    if ($sys) {
        ok(stripos($sys['Type'], 'tinyint') !== false, "accounts.is_system is TINYINT (got {$sys['Type']})");
        ok($sys['Null'] === 'NO', 'accounts.is_system is NOT NULL');
        ok((string)$sys['Default'] === '0', 'accounts.is_system defaults to 0');
    }

    $nb = col($pdo, 'normal_balance');
    ok($nb !== null, 'accounts.normal_balance exists');
    if ($nb) ok(stripos($nb['Type'], "enum('debit','credit')") !== false, "accounts.normal_balance is ENUM(debit,credit) (got {$nb['Type']})");

    // ─────────────────────────────────────────────────────────────────────
    section('2. Pre-existing columns untouched (regression)');
    // ─────────────────────────────────────────────────────────────────────
    foreach (['account_id', 'account_code', 'account_name', 'account_type_id', 'account_type',
              'parent_account_id', 'current_balance', 'opening_balance', 'status'] as $c) {
        ok(col($pdo, $c) !== null, "accounts.$c still present");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. level backfill is sane');
    // ─────────────────────────────────────────────────────────────────────
    $nullLevels = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE level IS NULL")->fetchColumn();
    ok($nullLevels === 0, "no account left with NULL level ($nullLevels found)");

    $topMisset = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE parent_account_id IS NULL AND level <> 1")->fetchColumn();
    ok($topMisset === 0, "every top-level (no-parent) account is level 1 ($topMisset wrong)");

    // Valid children must be exactly parent.level + 1. Self-referencing rows
    // (parent = self) are NOT a real parent/child link — they are corrupt data
    // handled as roots by the migration, and reported separately below.
    $childMisset = (int)$pdo->query("
        SELECT COUNT(*)
          FROM accounts a
          JOIN accounts p ON a.parent_account_id = p.account_id
         WHERE a.parent_account_id <> a.account_id
           AND a.level <> p.level + 1
    ")->fetchColumn();
    ok($childMisset === 0, "every valid child account = parent.level + 1 ($childMisset wrong)");

    // The migration repairs self-referencing accounts (a row that is its own
    // parent) by detaching them to top-level. After it runs there must be NONE
    // left — a remaining self-loop would break the COA tree + the delete
    // "has sub-accounts" guard, so this is a hard gate.
    $selfRefs = $pdo->query("
        SELECT account_id, account_code, account_name
          FROM accounts WHERE parent_account_id = account_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (count($selfRefs) === 0) {
        ok(true, 'no self-referencing accounts remain (migration repaired any self-parent)');
    } else {
        foreach ($selfRefs as $r) {
            echo "  \033[31m⚠ self-referencing account #{$r['account_id']} "
               . "({$r['account_code']} / {$r['account_name']}) — parent still points at itself\033[0m\n";
        }
        ok(false, count($selfRefs) . ' self-referencing account(s) NOT repaired — migration failed');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. normal_balance backfilled from account type');
    // ─────────────────────────────────────────────────────────────────────
    // Any account whose type has a normal_side should now carry a matching
    // normal_balance (migration only filled NULLs, so check for mismatches
    // where it was filled — i.e. it must equal the type's side for rows the
    // migration touched). We assert: no account that has a classified type is
    // left with a normal_balance that contradicts the type.
    $contradict = (int)$pdo->query("
        SELECT COUNT(*)
          FROM accounts a
          JOIN account_types t ON a.account_type_id = t.type_id
         WHERE t.normal_side IS NOT NULL
           AND a.normal_balance IS NOT NULL
           AND a.normal_balance <> t.normal_side
    ")->fetchColumn();
    ok($contradict === 0, "no account contradicts its type's normal_side ($contradict found)");

    $filledForClassified = (int)$pdo->query("
        SELECT COUNT(*)
          FROM accounts a
          JOIN account_types t ON a.account_type_id = t.type_id
         WHERE t.normal_side IS NOT NULL
           AND a.normal_balance IS NULL
    ")->fetchColumn();
    ok($filledForClassified === 0, "every account with a classified type has normal_balance set ($filledForClassified missing)");

    // ─────────────────────────────────────────────────────────────────────
    section('5. is_system flagged for settings + mapping accounts');
    // ─────────────────────────────────────────────────────────────────────
    // 5a. Each numeric *_account_id setting points at an account flagged is_system.
    if ($pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch()) {
        $unflaggedSettings = (int)$pdo->query("
            SELECT COUNT(*)
              FROM system_settings s
              JOIN accounts a ON a.account_id = CAST(s.setting_value AS UNSIGNED)
             WHERE s.setting_key REGEXP '_account_id$'
               AND s.setting_value REGEXP '^[0-9]+$'
               AND a.is_system <> 1
        ")->fetchColumn();
        ok($unflaggedSettings === 0, "all settings-referenced accounts are is_system=1 ($unflaggedSettings unflagged)");
    } else {
        ok(true, 'system_settings absent — flagging step skipped (n/a)');
    }

    // 5b. Each journal_mappings account is flagged.
    if ($pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch()) {
        $unflaggedMap = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT debit_account_id  AS aid FROM journal_mappings WHERE debit_account_id  IS NOT NULL
                UNION
                SELECT credit_account_id AS aid FROM journal_mappings WHERE credit_account_id IS NOT NULL
            ) m
            JOIN accounts a ON a.account_id = m.aid
            WHERE a.is_system <> 1
        ")->fetchColumn();
        ok($unflaggedMap === 0, "all journal_mappings accounts are is_system=1 ($unflaggedMap unflagged)");
    } else {
        ok(true, 'journal_mappings absent — flagging step skipped (n/a)');
    }

    // 5c. Sanity: is_system is a clean 0/1 set (no stray values).
    $bad = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_system NOT IN (0,1)")->fetchColumn();
    ok($bad === 0, "is_system holds only 0/1 ($bad stray)");

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
