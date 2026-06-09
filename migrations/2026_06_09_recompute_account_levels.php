<?php
/**
 * 2026_06_09_recompute_account_levels.php
 * ---------------------------------------
 * Repair stale accounts.level values where child.level != parent.level + 1.
 *
 * Cause: re-parenting an account that has children used to update only that
 * account's level, leaving its descendants with stale levels. save_account.php now
 * cascades the recompute, but this fixes any drift already in the data (and on live).
 *
 * Reset-and-recompute (matches the original tree migration), so it is fully
 * idempotent and cycle-safe: roots = level 1, then fill children depth-by-depth.
 * Criteria-based (no hard-coded ids) → correct on any database.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: recompute account levels...\n";

try {
    // Count drift before.
    $before = (int)$pdo->query("
        SELECT COUNT(*) FROM accounts c JOIN accounts p ON c.parent_account_id = p.account_id
         WHERE c.parent_account_id <> c.account_id AND c.level <> p.level + 1
    ")->fetchColumn();
    echo "  Accounts with a stale level before: $before\n";

    // Roots (no parent, self-loop, or dangling parent) → level 1.
    $pdo->exec("
        UPDATE accounts a
           SET a.level = 1
         WHERE a.parent_account_id IS NULL
            OR a.parent_account_id = a.account_id
            OR a.parent_account_id NOT IN (SELECT account_id FROM (SELECT account_id FROM accounts) x)
    ");

    // Fill children depth-by-depth: each pass sets level = parent.level + 1 for any
    // child whose parent already has a (correct) level and whose own level is wrong.
    $maxPasses = 50;
    for ($i = 0; $i < $maxPasses; $i++) {
        $n = $pdo->exec("
            UPDATE accounts c
              JOIN accounts p ON c.parent_account_id = p.account_id
               SET c.level = p.level + 1
             WHERE c.parent_account_id <> c.account_id
               AND c.level <> p.level + 1
        ");
        if ($n === 0) break;   // stable
    }

    $after = (int)$pdo->query("
        SELECT COUNT(*) FROM accounts c JOIN accounts p ON c.parent_account_id = p.account_id
         WHERE c.parent_account_id <> c.account_id AND c.level <> p.level + 1
    ")->fetchColumn();
    echo "  Accounts with a stale level after: $after\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
