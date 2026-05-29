<?php
/**
 * 2026_05_28_journal_entries_project_and_source_link.php
 * ------------------------------------------------------
 * Phase 0.1 — designate `journal_entries` as the canonical ledger.
 *
 * Adds three columns + supporting indexes so every posted journal entry
 * can be (a) filtered by project for scoped reports (Trial Balance,
 * General Ledger, Balance Sheet, Income Statement, Cash Flow), and
 * (b) traced back to its source document (invoice, expense, payroll,
 * etc.) for the General Ledger drill-down.
 *
 *   project_id     INT NULL           — FK to projects(project_id).
 *                                       Filters journal entries by project
 *                                       in scoped reports. NULL = company-
 *                                       wide entry (overhead, manual adj).
 *   entity_id      INT UNSIGNED NULL  — Source document primary key, e.g.
 *                                       the invoice_id, expense_id,
 *                                       payroll_id, asset_id, etc. NULL =
 *                                       manual journal entry (no source).
 *   entity_type    VARCHAR(50) NULL   — Source document table name, e.g.
 *                                       'invoice', 'expense', 'payroll',
 *                                       'asset', 'depreciation_run'.
 *                                       Together with entity_id this
 *                                       enables drill-down: source doc =
 *                                       (entity_type, entity_id).
 *
 * Plus two indexes:
 *   ix_je_project  (project_id)               — scoped report filter speed
 *   ix_je_entity   (entity_type, entity_id)   — drill-down lookup speed
 *
 * Idempotent: every ALTER guarded by SHOW COLUMNS LIKE. Re-run safe.
 * Existing 2 rows are unaffected (all three new columns NULL by default).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: journal_entries project + source-link columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'journal_entries'")->fetch()) {
        echo "  ! journal_entries table not found — cannot proceed.\n";
        exit(1);
    }

    // ── Column additions ──────────────────────────────────────────────────
    $cols = [
        ['project_id',  "INT NULL COMMENT 'FK to projects.project_id; NULL = company-wide entry'"],
        ['entity_id',   "INT UNSIGNED NULL COMMENT 'Source document PK (invoice_id, expense_id, etc.)'"],
        ['entity_type', "VARCHAR(50) NULL COMMENT 'Source document table identifier (invoice, expense, payroll, etc.)'"],
    ];
    foreach ($cols as [$col, $def]) {
        $exists = $pdo->query("SHOW COLUMNS FROM `journal_entries` LIKE '{$col}'")->fetch();
        if ($exists) {
            echo "  · column {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE `journal_entries` ADD COLUMN `{$col}` {$def}");
            echo "  + added column {$col}.\n";
        }
    }

    // ── Indexes ───────────────────────────────────────────────────────────
    // ix_je_project — speeds scopeFilterSqlNullable('project') predicates
    $hasIxProj = $pdo->query("SHOW INDEX FROM `journal_entries` WHERE Key_name = 'ix_je_project'")->fetch();
    if ($hasIxProj) {
        echo "  · index ix_je_project already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE `journal_entries` ADD KEY ix_je_project (project_id)");
        echo "  + added index ix_je_project on (project_id).\n";
    }

    // ix_je_entity — composite for source-document drill-down
    $hasIxEntity = $pdo->query("SHOW INDEX FROM `journal_entries` WHERE Key_name = 'ix_je_entity'")->fetch();
    if ($hasIxEntity) {
        echo "  · index ix_je_entity already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE `journal_entries` ADD KEY ix_je_entity (entity_type, entity_id)");
        echo "  + added index ix_je_entity on (entity_type, entity_id).\n";
    }

    // ── Foreign key to projects ──────────────────────────────────────────
    // Wrapped because some MySQL configs reject FK adds in strict mode if
    // the referenced column type doesn't match exactly. We align if needed.
    try {
        // Pre-check: projects.project_id type. If signed INT, our INT NULL
        // matches. If anything else (rare), MySQL will reject the FK and
        // we log a warning instead of failing the whole migration.
        $existsFk = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'journal_entries'
               AND CONSTRAINT_NAME = 'fk_je_project_id'
        ")->fetchColumn();
        if (!$existsFk) {
            if (!$pdo->query("SHOW TABLES LIKE 'projects'")->fetch()) {
                echo "  ! `projects` table missing — FK skipped.\n";
            } else {
                $pdo->exec("ALTER TABLE `journal_entries`
                            ADD CONSTRAINT fk_je_project_id
                            FOREIGN KEY (project_id) REFERENCES projects(project_id)
                            ON DELETE SET NULL ON UPDATE CASCADE");
                echo "  + added FK fk_je_project_id (ON DELETE SET NULL).\n";
            }
        } else {
            echo "  · FK fk_je_project_id already present, skipping.\n";
        }
    } catch (PDOException $e) {
        echo "  ! Could not add FK (non-fatal): " . $e->getMessage() . "\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
