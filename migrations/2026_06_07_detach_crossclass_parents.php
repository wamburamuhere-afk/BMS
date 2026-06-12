<?php
/**
 * 2026_06_07_detach_crossclass_parents.php
 * ----------------------------------------
 * scope-audit: skip — data hygiene on accounts only.
 *
 * Enforces the same-class nesting rule on EXISTING data: an account whose class
 * (account_types.category) differs from its parent's is an illogical link
 * (e.g. a revenue account parented under an asset). We detach such children to
 * top-level (parent_account_id = NULL, level = 1) so the whole tree is
 * accounting-consistent and mirrors the classification. save_account.php already
 * blocks new cross-class links; this cleans up any that pre-date that guard.
 *
 * Idempotent: after it runs there are no cross-class links, so a re-run is a no-op.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: detach cross-class parent links...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  accounts table missing — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // Report what will be detached (for the deploy log).
    $rows = $pdo->query("
        SELECT a.account_id, a.account_code, a.account_name, at.category AS child_cat,
               p.account_code AS parent_code, pt.category AS parent_cat
          FROM accounts a
          JOIN accounts p       ON a.parent_account_id = p.account_id
          JOIN account_types at ON a.account_type_id   = at.type_id
          JOIN account_types pt ON p.account_type_id   = pt.type_id
         WHERE a.parent_account_id <> a.account_id
           AND at.category IS NOT NULL AND pt.category IS NOT NULL
           AND at.category <> pt.category
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        echo "  · detaching #{$r['account_id']} {$r['account_code']} ({$r['child_cat']}) "
           . "from parent {$r['parent_code']} ({$r['parent_cat']})\n";
    }

    $stmt = $pdo->prepare("
        UPDATE accounts a
          JOIN accounts p       ON a.parent_account_id = p.account_id
          JOIN account_types at ON a.account_type_id   = at.type_id
          JOIN account_types pt ON p.account_type_id   = pt.type_id
           SET a.parent_account_id = NULL, a.level = 1
         WHERE a.parent_account_id <> a.account_id
           AND at.category IS NOT NULL AND pt.category IS NOT NULL
           AND at.category <> pt.category
    ");
    $stmt->execute();

    echo "\n  Detached {$stmt->rowCount()} cross-class child account(s) to top-level.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
