<?php
/**
 * 2026_07_09_leaves_type_fk.php
 * -----------------------------
 * Leaves module — Phase 1: connect `leaves` to `leave_types`.
 *
 * Why:
 *   `leaves.leave_type` is an ENUM('annual','sick','maternity','paternity',
 *   'study','unpaid','other') with no link to the `leave_types` table.
 *   app/bms/pos/leaves.php joins them on `l.leave_type = lt.type_name`, i.e.
 *   'annual' = 'Annual Leave' — a condition that is never true (0 of 27 rows).
 *   api/apply_leave.php maps the posted type_name back onto the ENUM and falls
 *   back to 'other', so a NEW leave type (e.g. 'Compassionate Leave', which has
 *   no ENUM member) can never be stored against the leave that uses it.
 *
 * What this does:
 *   1. leaves.leave_type_id  INT NULL  FK -> leave_types(type_id)
 *   2. leaves.half_day       ENUM('none','first_half','second_half','other')
 *      leaves.leave_hours    DECIMAL(4,2) NULL   (only when half_day='other')
 *      — both were posted by the form and silently discarded: neither column
 *        existed and neither was in the INSERT column list.
 *   3. leaves.is_paid        TINYINT(1) NULL — a SNAPSHOT of the type's is_paid
 *      at apply time, so re-classifying a leave type later cannot rewrite history.
 *   4. Backfill leave_type_id + is_paid from the ENUM, matching on the first word
 *      of type_name (case-insensitive). Criteria-based; no hard-coded ids.
 *
 * What this deliberately does NOT do:
 *   - Drop the ENUM. leaves.php, leave_details.php, leave_reports.php,
 *     export_leaves.php and project_view.php still read it. The APIs dual-write
 *     both columns; the ENUM is dropped in a later cleanup migration once every
 *     reader is migrated.
 *   - Guess a type for the 15 rows whose ENUM is '' (MySQL coerced invalid values
 *     because sql_mode is non-strict). They keep leave_type_id = NULL and are
 *     reported below; the UI renders them as an em dash.
 *
 * Additive + idempotent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: leaves -> leave_types foreign key + half-day/hours/is_paid...\n";

/** Does a column already exist? */
function leaves_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch();
}

/** Does a named constraint already exist on this schema? */
function leaves_has_fk(PDO $pdo, string $table, string $constraint): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $st->execute([$table, $constraint]);
    return (bool)$st->fetchColumn();
}

try {
    /* 0. Both tables must be InnoDB for the FK to be enforceable. ------------- */
    foreach (['leaves', 'leave_types'] as $t) {
        $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn();
        if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
            $pdo->exec("ALTER TABLE `$t` ENGINE=InnoDB");
            echo "  * $t converted to InnoDB (was $engine).\n";
        }
    }

    /* 1. New columns (DDL — never inside a transaction). ---------------------- */
    $cols = [
        'leave_type_id' => "INT NULL AFTER `employee_id`",
        'half_day'      => "ENUM('none','first_half','second_half','other') NOT NULL DEFAULT 'none'",
        'leave_hours'   => "DECIMAL(4,2) NULL",
        'is_paid'       => "TINYINT(1) NULL",
    ];
    foreach ($cols as $col => $def) {
        if (leaves_has_column($pdo, 'leaves', $col)) {
            echo "  = leaves.$col already present, skipped.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `leaves` ADD COLUMN `$col` $def");
        echo "  + leaves.$col added.\n";
    }

    /* 2. Index + foreign key. ------------------------------------------------- */
    $hasIdx = $pdo->query("SHOW INDEX FROM `leaves` WHERE Key_name = 'idx_leaves_leave_type_id'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE `leaves` ADD INDEX `idx_leaves_leave_type_id` (`leave_type_id`)");
        echo "  + index idx_leaves_leave_type_id added.\n";
    } else {
        echo "  = index idx_leaves_leave_type_id already present, skipped.\n";
    }

    if (!leaves_has_fk($pdo, 'leaves', 'fk_leaves_leave_type')) {
        // ON DELETE RESTRICT: a type with leaves booked against it must not vanish.
        // The app soft-deletes types (status='inactive'), so this only guards
        // against a hard DELETE slipping through.
        $pdo->exec(
            "ALTER TABLE `leaves`
               ADD CONSTRAINT `fk_leaves_leave_type`
               FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`type_id`)
               ON DELETE RESTRICT ON UPDATE CASCADE"
        );
        echo "  + FK fk_leaves_leave_type added.\n";
    } else {
        echo "  = FK fk_leaves_leave_type already present, skipped.\n";
    }

    /* 3. Backfill (DML — a transaction is fine here). -------------------------- */
    $pdo->beginTransaction();

    // Match the ENUM member to the first word of type_name, case-insensitive:
    //   'annual' -> 'Annual Leave', 'unpaid' -> 'Unpaid Leave', ...
    // 'other' matches nothing (there is no 'Other' leave type) and stays NULL.
    $updated = $pdo->exec(
        "UPDATE `leaves` l
           JOIN `leave_types` lt
             ON LOWER(SUBSTRING_INDEX(lt.type_name, ' ', 1)) = LOWER(l.leave_type)
            SET l.leave_type_id = lt.type_id,
                l.is_paid       = COALESCE(l.is_paid, lt.is_paid)
          WHERE l.leave_type_id IS NULL
            AND l.leave_type IS NOT NULL
            AND l.leave_type <> ''"
    );
    echo "  * backfilled leave_type_id on $updated row(s).\n";

    $pdo->commit();

    /* 4. Report what could not be resolved — never guess. ---------------------- */
    $blank = (int)$pdo->query("SELECT COUNT(*) FROM `leaves` WHERE leave_type IS NULL OR leave_type = ''")->fetchColumn();
    $other = (int)$pdo->query("SELECT COUNT(*) FROM `leaves` WHERE leave_type = 'other'")->fetchColumn();
    $unres = (int)$pdo->query("SELECT COUNT(*) FROM `leaves` WHERE leave_type_id IS NULL")->fetchColumn();

    echo "  i $blank row(s) have an empty ENUM (sql_mode was non-strict) — left NULL, shown as '—'.\n";
    echo "  i $other row(s) are 'other' — no matching leave_types row — left NULL.\n";
    echo "  i $unres row(s) total have leave_type_id = NULL.\n";

    /* 5. Assert the backfill is sound. ---------------------------------------- */
    $bad = (int)$pdo->query(
        "SELECT COUNT(*) FROM `leaves` l
          WHERE l.leave_type IS NOT NULL AND l.leave_type <> '' AND l.leave_type <> 'other'
            AND l.leave_type_id IS NULL"
    )->fetchColumn();
    if ($bad > 0) {
        echo "Migration failed: $bad resolvable row(s) were not backfilled.\n";
        exit(1);
    }

    $mismatch = (int)$pdo->query(
        "SELECT COUNT(*) FROM `leaves` l
           JOIN `leave_types` lt ON lt.type_id = l.leave_type_id
          WHERE LOWER(SUBSTRING_INDEX(lt.type_name, ' ', 1)) <> LOWER(l.leave_type)"
    )->fetchColumn();
    if ($mismatch > 0) {
        echo "Migration failed: $mismatch row(s) point at the wrong leave type.\n";
        exit(1);
    }
    echo "  ✓ every resolvable row maps to the correct leave_types row.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
