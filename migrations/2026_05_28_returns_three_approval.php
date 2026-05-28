<?php
/**
 * 2026_05_28_returns_three_approval.php
 * -------------------------------------
 * Extends Purchase Returns and Sales Returns with the canonical three-approval
 * workflow columns (reviewed_by/_at, approved_by/_at) and backfills the
 * 'created' workflow_signatures row for every existing legacy record so the
 * Created By column on the print page renders immediately after deploy.
 *
 * What it does
 * ------------
 *   1. ALTER TABLE purchase_returns: add reviewed_by, reviewed_at,
 *      approved_by, approved_at  (each guarded by SHOW COLUMNS LIKE).
 *   2. ALTER TABLE sales_returns: same 4 columns, same guards.
 *   3. Expand the status ENUM on each table to include 'reviewed' if missing.
 *      All existing values are preserved.
 *   4. Backfill workflow_signatures rows with action='created' for every
 *      existing record where created_by IS NOT NULL. Idempotent via
 *      ON DUPLICATE KEY UPDATE on uq_entity_action. Existing sig_path values
 *      are never overwritten. signed_at is preserved on re-runs.
 *
 * Safety
 * ------
 *  - No data deleted.
 *  - No column renamed or dropped.
 *  - No existing ENUM value dropped.
 *  - Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: returns three-approval columns + backfill...\n";

try {
    $tables = ['purchase_returns', 'sales_returns'];
    $cols = [
        'reviewed_by' => "INT NULL",
        'reviewed_at' => "DATETIME NULL",
        'approved_by' => "INT NULL",
        'approved_at' => "DATETIME NULL",
    ];

    foreach ($tables as $table) {
        // Guard: table must exist
        if (!$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch()) {
            echo "  ! table {$table} not found — skipping.\n";
            continue;
        }
        echo "\n── {$table} ──\n";
        foreach ($cols as $col => $def) {
            $exists = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetch();
            if ($exists) {
                echo "    · column {$col} already exists, skipping.\n";
            } else {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                echo "    + added column {$col}.\n";
            }
        }

        // Expand status ENUM to include 'reviewed' if missing.
        // We read the current ENUM, append 'reviewed' if absent, and write it back.
        $row = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($row && stripos($row['Type'], 'enum(') === 0) {
            $type = $row['Type'];
            if (stripos($type, "'reviewed'") === false) {
                // Splice 'reviewed' into the ENUM right after 'pending' if present, else at the start.
                $newType = preg_replace_callback(
                    "/enum\\((.*)\\)/i",
                    function ($m) {
                        $list = $m[1];
                        if (stripos($list, "'pending'") !== false) {
                            return "enum(" . str_ireplace("'pending'", "'pending','reviewed'", $list) . ")";
                        }
                        return "enum('reviewed'," . $list . ")";
                    },
                    $type
                );
                $null = ($row['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
                $default = '';
                if ($row['Default'] !== null) {
                    $default = " DEFAULT " . $pdo->quote($row['Default']);
                }
                $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `status` {$newType} {$null}{$default}");
                echo "    + status ENUM expanded to include 'reviewed'.\n";
            } else {
                echo "    · status ENUM already has 'reviewed', skipping.\n";
            }
        } else {
            echo "    · status is not an ENUM column — leaving it untouched.\n";
        }
    }

    // ── Backfill workflow_signatures 'created' rows ────────────────────────
    echo "\n── Backfilling workflow_signatures (action='created') ──\n";

    // Preconditions for backfill
    $exists = $pdo->query("SHOW TABLES LIKE 'workflow_signatures'")->fetch();
    if (!$exists) {
        echo "  ! workflow_signatures table not found — backfill skipped.\n";
    } else {
        $hasKey = (bool)$pdo->query(
            "SHOW INDEX FROM workflow_signatures WHERE Key_name = 'uq_entity_action'"
        )->fetch();
        if (!$hasKey) {
            echo "  ! UNIQUE KEY uq_entity_action missing — backfill skipped (cannot safely run).\n";
        } else {
            $hasUserSig = (bool)$pdo->query("SHOW TABLES LIKE 'user_signatures'")->fetch();

            $docs = [
                // entity_type        table              pk
                ['purchase_return',   'purchase_returns', 'purchase_return_id'],
                ['sales_return',      'sales_returns',    'sales_return_id'],
            ];

            $sigPathExpr = $hasUserSig
                ? "(SELECT us.file_path
                      FROM user_signatures us
                     WHERE us.user_id = t.created_by
                  ORDER BY us.updated_at DESC, us.id DESC
                     LIMIT 1)"
                : "NULL";

            foreach ($docs as [$entityType, $table, $pk]) {
                if (!$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch()) {
                    echo "    · {$table} not found — skipping.\n";
                    continue;
                }
                if (!$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'created_by'")->fetch()) {
                    echo "    · {$table}.created_by missing — skipping.\n";
                    continue;
                }
                $caExpr = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'created_at'")->fetch()
                    ? "COALESCE(t.created_at, NOW())"
                    : "NOW()";

                $sql = "
                    INSERT INTO workflow_signatures
                        (entity_type, entity_id, action, user_id,
                         user_name, user_role, sig_path, ip_address,
                         signed_at, consent_accepted)
                    SELECT
                        '{$entityType}'                                              AS entity_type,
                        t.`{$pk}`                                                    AS entity_id,
                        'created'                                                    AS action,
                        t.created_by                                                 AS user_id,
                        TRIM(CONCAT(COALESCE(u.first_name,''),' ',
                                    COALESCE(u.last_name,'')))                       AS user_name,
                        COALESCE(u.user_role, u.role, 'Staff')                       AS user_role,
                        {$sigPathExpr}                                               AS sig_path,
                        NULL                                                         AS ip_address,
                        {$caExpr}                                                    AS signed_at,
                        1                                                            AS consent_accepted
                    FROM `{$table}` t
                    LEFT JOIN users u ON u.user_id = t.created_by
                    WHERE t.created_by IS NOT NULL
                    ON DUPLICATE KEY UPDATE
                        user_name = VALUES(user_name),
                        user_role = VALUES(user_role),
                        sig_path  = COALESCE(workflow_signatures.sig_path, VALUES(sig_path)),
                        signed_at = signed_at
                ";
                $affected = $pdo->exec($sql);
                echo "    + {$entityType}: {$affected} rows processed.\n";
            }
        }
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
