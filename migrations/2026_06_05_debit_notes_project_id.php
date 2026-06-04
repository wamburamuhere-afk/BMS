<?php
/**
 * 2026_06_05_debit_notes_project_id.php
 * -------------------------------------
 * Project integration for the Debit Note document. Adds a nullable project_id
 * so a debit note can be raised/managed from inside a project workspace and
 * always resolve "Back to Project" — even a standalone note created in-project.
 *
 *   1. ALTER TABLE debit_notes ADD COLUMN project_id INT NULL + index.
 *   2. Backfill project_id from the linked purchase return's project
 *      (purchase_returns.project_id) for existing rows.
 *
 * Purely ADDITIVE — nullable column, no data dropped. Idempotent; no
 * transactions around DDL.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: debit_notes.project_id...\n";

try {
    // Guard: table must exist (created by 2026_06_04_debit_notes_foundation.php)
    if (!$pdo->query("SHOW TABLES LIKE 'debit_notes'")->fetch()) {
        echo "  ! debit_notes table not found — skipping (run the foundation migration first).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // ── 1. Add column + index ───────────────────────────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM debit_notes LIKE 'project_id'")->fetch()) {
        $pdo->exec("ALTER TABLE debit_notes ADD COLUMN project_id INT NULL AFTER purchase_order_id");
        echo "  + debit_notes.project_id added.\n";
    } else {
        echo "  · debit_notes.project_id already present, skipping.\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM debit_notes WHERE Key_name = 'idx_dn_project'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE debit_notes ADD INDEX idx_dn_project (project_id)");
        echo "  + index idx_dn_project added.\n";
    } else {
        echo "  · index idx_dn_project already present, skipping.\n";
    }

    // ── 2. Backfill from the linked purchase return's project ────────────────
    $prHasProject = $pdo->query("SHOW TABLES LIKE 'purchase_returns'")->fetch()
        && $pdo->query("SHOW COLUMNS FROM purchase_returns LIKE 'project_id'")->fetch();
    if ($prHasProject) {
        $n = $pdo->exec("
            UPDATE debit_notes dn
              JOIN purchase_returns pr ON dn.purchase_return_id = pr.purchase_return_id
               SET dn.project_id = pr.project_id
             WHERE dn.project_id IS NULL
               AND pr.project_id IS NOT NULL
        ");
        echo "  + backfilled project_id on {$n} debit note(s) from their purchase return.\n";
    } else {
        echo "  · purchase_returns.project_id unavailable — backfill skipped.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
