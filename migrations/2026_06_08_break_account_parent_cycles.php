<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: detect & break cycles in accounts.parent_account_id...\n";

/*
 * A cycle in the chart-of-accounts parent chain (e.g. A's parent is B and B's
 * parent is A) makes every recursive tree query loop forever — MySQL aborts it
 * with "Recursive query aborted after 1001 iterations" and the Account Details /
 * Chart of Accounts pages fatal-error. This migration finds any such cycle and
 * breaks it by setting parent_account_id = NULL on the node that closes the loop
 * (making it a top-level account), then logs what it changed. Idempotent: on a
 * clean tree it changes nothing.
 */
try {
    $rows = $pdo->query("SELECT account_id, parent_account_id, account_code, account_name FROM accounts")->fetchAll(PDO::FETCH_ASSOC);
    $parent = [];
    $label  = [];
    foreach ($rows as $r) {
        $parent[(int)$r['account_id']] = $r['parent_account_id'] !== null ? (int)$r['parent_account_id'] : null;
        $label[(int)$r['account_id']]  = trim(($r['account_code'] ?? '') . ' ' . ($r['account_name'] ?? ''));
    }

    $toBreak = [];   // account_id => true  (node whose parent link closes a cycle)

    foreach ($parent as $startId => $_p) {
        $seen = [];
        $cur  = $startId;
        $prev = null;
        $steps = 0;
        while ($cur !== null && array_key_exists($cur, $parent)) {
            if (isset($seen[$cur])) {
                // We re-entered a node already on this walk → the link we just
                // followed INTO $cur closes the cycle. Break it at $prev (the node
                // whose parent_account_id points back into the loop).
                if ($prev !== null) $toBreak[$prev] = true;
                break;
            }
            $seen[$cur] = true;
            $prev = $cur;
            $cur  = $parent[$cur];
            if (++$steps > 100000) break;   // hard safety stop
        }
    }

    // A node that is its own direct parent is also a (trivial) cycle.
    foreach ($parent as $id => $p) {
        if ($p !== null && $p === $id) $toBreak[$id] = true;
    }

    if (empty($toBreak)) {
        echo "No cycles found — chart of accounts is acyclic. Nothing to change.\n";
        echo "Migration complete.\n";
        return;
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE accounts SET parent_account_id = NULL WHERE account_id = ?");
    foreach (array_keys($toBreak) as $id) {
        $upd->execute([$id]);
        echo "  Broke cycle: set parent_account_id = NULL on account [{$id}] " . ($label[$id] ?? '') . "\n";
    }
    $pdo->commit();

    echo "Broke " . count($toBreak) . " cyclic link(s).\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
