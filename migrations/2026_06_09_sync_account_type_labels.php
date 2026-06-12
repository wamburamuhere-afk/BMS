<?php
/**
 * 2026_06_09_sync_account_type_labels.php
 * ---------------------------------------
 * Fix stale account_type LABELS that disagree with the account_type_id LINK.
 *
 * Some legacy accounts have accounts.account_type (a denormalised text cache)
 * that no longer matches the type their accounts.account_type_id actually points
 * to. The link (account_type_id) is the source of truth — every report and the
 * edit form already classify by it. The stale label made the "type change" guard
 * in save_account.php misfire (it saw a type change when only the parent/code was
 * being edited), blocking the whole save once Gap 1 gave those accounts journal
 * lines.
 *
 * This sets account_type = the canonical type_name derived from account_type_id,
 * ONLY for rows where they currently disagree. It changes NO classification or
 * reporting (those already use the link) — it just makes the label honest.
 *
 * SAFE: criteria-based (no hard-coded ids → correct on live too), idempotent
 * (re-run touches 0 rows), reversible in principle (it only rewrites a cache
 * column to match the FK).
 *
 * Modes:
 *   php ...php            → DRY-RUN: prints the before/after, changes nothing.
 *   php ...php --apply    → APPLY: performs the update.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

$apply = in_array('--apply', $argv ?? [], true);
echo "Starting migration: sync account_type labels to account_type_id" . ($apply ? " [APPLY]" : " [DRY-RUN]") . "...\n\n";

try {
    // Find accounts whose label disagrees with the canonical type_name of their link.
    $rows = $pdo->query("
        SELECT a.account_id, a.account_code, a.account_name,
               a.account_type            AS stale_label,
               at.type_name              AS correct_label,
               a.account_type_id,
               (SELECT COUNT(*) FROM journal_entry_items j WHERE j.account_id = a.account_id) AS jrnl_lines
          FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.account_type_id IS NOT NULL
           AND (a.account_type IS NULL OR a.account_type <> at.type_name)
         ORDER BY a.account_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "  No mismatched account labels found — nothing to do (already consistent).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    printf("  %-6s %-14s %-26s %-10s -> %-10s %s\n", 'id', 'code', 'name', 'label(now)', 'label(fix)', 'jrnl');
    printf("  %s\n", str_repeat('-', 86));
    foreach ($rows as $r) {
        printf("  %-6s %-14s %-26s %-10s -> %-10s %s\n",
            $r['account_id'], $r['account_code'], substr($r['account_name'], 0, 26),
            $r['stale_label'] ?? 'NULL', $r['correct_label'], $r['jrnl_lines']);
    }
    echo "\n  " . count($rows) . " account(s) have a stale label.\n\n";

    if (!$apply) {
        echo "  DRY-RUN — no changes made. Re-run with --apply to fix.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // APPLY: set the label to the canonical type_name of the linked type.
    $upd = $pdo->prepare("
        UPDATE accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
           SET a.account_type = at.type_name, a.updated_at = NOW()
         WHERE a.account_type_id IS NOT NULL
           AND (a.account_type IS NULL OR a.account_type <> at.type_name)
    ");
    $upd->execute();
    echo "  Applied: " . $upd->rowCount() . " label(s) synced to their account_type_id.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
