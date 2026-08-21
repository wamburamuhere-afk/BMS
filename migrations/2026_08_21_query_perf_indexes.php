<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

/**
 * Query-performance indexes — read paths only, no logic change.
 *
 * Pure `ALTER TABLE ... ADD KEY`. No query text, no result set and no business
 * rule is touched by this migration; it only gives the optimiser a path it
 * currently lacks. Every statement is guarded by a SHOW INDEX check so the
 * file is safe to re-run.
 *
 * Why these three:
 *
 *  1. activity_logs (user_id, created_at)
 *     app/dashboard.php:443-447 filters `WHERE user_id = ?` then
 *     `ORDER BY created_at DESC LIMIT 10` for non-admins. Equality on the
 *     leading column + ordered second column = index-ordered read, killing
 *     both the scan and the filesort.
 *
 *  2. activity_logs (created_at)
 *     The admin variant of the same dashboard query has no user_id predicate,
 *     so it cannot use the composite above. A standalone created_at key lets
 *     MySQL walk the index backwards and stop after 10 rows.
 *     Measured before: type=ALL, key=NULL, rows=16372, Using filesort.
 *
 *  3. journal_entries (status, entry_date)
 *     The mandatory reporting filter from .claude/reporting-source.md —
 *     `status = 'posted' AND entry_date <= :as_of`. Equality first, range
 *     second, which is the correct column order for this shape. Feeds every
 *     Trial Balance, P&L, Balance Sheet, Cash Flow and AR-aging query.
 *     Measured before: type=ALL, key=NULL, Using where; Using temporary.
 */

echo "Starting migration: query-performance indexes...\n";

/** Add an index only when a key of that name is not already present. */
$addIndex = function (PDO $pdo, string $table, string $keyName, string $columns): void {
    $exists = $pdo->query(
        "SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($keyName)
    )->fetch();

    if ($exists) {
        echo "  · $table.$keyName already exists, skipping.\n";
        return;
    }

    $pdo->exec("ALTER TABLE `$table` ADD KEY `$keyName` ($columns)");
    echo "  + added $table.$keyName on ($columns).\n";
};

try {
    // ── activity_logs ────────────────────────────────────────────────────
    // 18k+ rows in the dev DB with only PRIMARY(id); read by 8 call sites,
    // the busiest being the dashboard activity feed on every page load.
    $addIndex($pdo, 'activity_logs', 'ix_al_user_created', '`user_id`, `created_at`');
    $addIndex($pdo, 'activity_logs', 'ix_al_created',      '`created_at`');

    // ── journal_entries ──────────────────────────────────────────────────
    // Existing keys cover tracing (entity/parent/reverses) and project scope,
    // but never the status+date filter every financial report is built on.
    $addIndex($pdo, 'journal_entries', 'ix_je_status_date', '`status`, `entry_date`');

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
