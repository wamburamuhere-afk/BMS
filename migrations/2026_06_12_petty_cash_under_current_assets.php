<?php
/**
 * 2026_06_12_petty_cash_under_current_assets.php
 * ----------------------------------------------
 * Re-homes the configured Petty Cash account so it sits where cash belongs on the
 * Balance Sheet — under Current Assets (specifically the "Cash On Hand" group) —
 * instead of being misclassified (e.g. under Fixed Assets). Recomputes its level
 * and re-codes it to the next free slot under its new parent so the chart numbering
 * stays consistent.
 *
 * Criteria-based + idempotent: only moves the account if Current Assets is NOT
 * already in its ancestor chain, so it self-corrects per environment and is safe
 * to re-run. Balance and history are untouched (postings reference the account id,
 * not the code).
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

echo "Starting migration: re-home Petty Cash under Current Assets...\n";

try {
    $pettyId = (int)(pettyCashAccountId($pdo) ?: 0);
    if (!$pettyId) { echo "  ~ No default petty cash account configured — nothing to do.\n\nMigration complete.\n"; exit(0); }

    // Resolve the Current Assets ancestor + the preferred new parent (Cash On Hand).
    $currentAssetsId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1000' LIMIT 1")->fetchColumn() ?: 0);
    if (!$currentAssetsId) {
        $currentAssetsId = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
            WHERE at.category='asset' AND a.account_name LIKE '%current asset%' AND a.status='active' LIMIT 1")->fetchColumn() ?: 0);
    }
    $cashOnHandId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1100' LIMIT 1")->fetchColumn() ?: 0);
    if (!$cashOnHandId) $cashOnHandId = $currentAssetsId;   // fall back to Current Assets directly
    if (!$cashOnHandId) { echo "  ! Could not find Current Assets / Cash On Hand — skipping.\n\nMigration complete.\n"; exit(0); }

    // Already under Current Assets? Walk the ancestor chain. (Idempotent guard.)
    $isUnder = false; $cur = $pettyId; $seen = []; $hops = 0;
    while ($cur && $hops++ < 100 && !isset($seen[$cur])) {
        $seen[$cur] = true;
        if ($currentAssetsId && $cur === $currentAssetsId) { $isUnder = true; break; }
        $cur = (int)($pdo->query("SELECT parent_account_id FROM accounts WHERE account_id=$cur")->fetchColumn() ?: 0);
    }
    if ($isUnder) { echo "  ~ Petty Cash already sits under Current Assets — no change.\n\nMigration complete.\n"; exit(0); }

    // Guard against cycles: the new parent must not be the petty account or its descendant.
    if ($cashOnHandId === $pettyId) { echo "  ! New parent equals the account — skipping.\n\nMigration complete.\n"; exit(0); }

    $parent = $pdo->query("SELECT account_id, account_code, level FROM accounts WHERE account_id=$cashOnHandId")->fetch(PDO::FETCH_ASSOC);
    $parentLevel = (int)$parent['level'];
    $newLevel = $parentLevel + 1;

    // Next free child code under the new parent (mirrors get_next_account_code logic).
    $newCode = null;
    if (preg_match('/^(\d)-(\d{4})$/', (string)$parent['account_code'], $m)) {
        $D = $m[1]; $pos = $parentLevel - 1; $prefix = substr($m[2], 0, $pos);
        $kids = $pdo->query("SELECT account_code FROM accounts WHERE parent_account_id=$cashOnHandId AND account_id<>$cashOnHandId")->fetchAll(PDO::FETCH_COLUMN);
        $used = [];
        foreach ($kids as $c) if (preg_match('/^\d-(\d{4})$/', (string)$c, $mm)) $used[(int)substr($mm[1], $pos, 1)] = true;
        for ($d = 1; $d <= 9; $d++) if (empty($used[$d])) { $cand = $D . '-' . $prefix . $d . str_repeat('0', 3 - $pos);
            // ensure globally free
            $taken = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code=" . $pdo->quote($cand))->fetchColumn();
            if (!$taken) { $newCode = $cand; break; }
        }
    }

    // Apply: re-parent (+ re-code if we found a consistent one) + fix level.
    if ($newCode) {
        $pdo->prepare("UPDATE accounts SET parent_account_id=?, level=?, account_code=?, updated_at=NOW() WHERE account_id=?")
            ->execute([$cashOnHandId, $newLevel, $newCode, $pettyId]);
        echo "  + Re-homed Petty Cash under {$parent['account_code']} → new code {$newCode}, level {$newLevel}.\n";
    } else {
        $pdo->prepare("UPDATE accounts SET parent_account_id=?, level=?, updated_at=NOW() WHERE account_id=?")
            ->execute([$cashOnHandId, $newLevel, $pettyId]);
        echo "  + Re-homed Petty Cash under {$parent['account_code']} (code unchanged), level {$newLevel}.\n";
    }

    // Cascade level to any descendants (petty cash is normally a leaf, but be safe).
    $queue = [$pettyId]; $seen2 = [$pettyId => true]; $guard = 0;
    $sel = $pdo->prepare("SELECT account_id FROM accounts WHERE parent_account_id=? AND account_id<>parent_account_id");
    $updL = $pdo->prepare("UPDATE accounts SET level=? WHERE account_id=?");
    while ($queue && $guard++ < 10000) {
        $p = array_shift($queue);
        $pl = (int)$pdo->query("SELECT level FROM accounts WHERE account_id=" . (int)$p)->fetchColumn();
        $sel->execute([$p]);
        foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            if (isset($seen2[$cid])) continue;
            $updL->execute([$pl + 1, $cid]); $seen2[$cid] = true; $queue[] = $cid;
        }
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
