<?php
/**
 * 2026_05_28_backfill_workflow_created_signatures.php
 * ---------------------------------------------------
 * One-time backfill: every existing document that has a `created_by` user_id
 * but no `workflow_signatures` row with action='created' gets one inserted.
 *
 * Why this exists
 * ---------------
 * The three-approval e-signature feature was rolled out with review/approve
 * capture only — no save endpoint ever recorded a 'created' row. The print
 * pages therefore showed the Reviewed By / Approved By signature columns
 * fully populated (image + caption + timestamp) while the Created By column
 * stayed blank for every document in the database.
 *
 * After this migration runs, every legacy doc retroactively has a 'created'
 * row in workflow_signatures, with:
 *   - user_id    = <table>.created_by
 *   - user_name  = users.first_name + last_name  (falls back to '')
 *   - user_role  = users.user_role / users.role  (falls back to 'Staff')
 *   - sig_path   = the user's NEWEST user_signatures.file_path (NULL if none)
 *   - signed_at  = <table>.created_at            (the doc's creation time)
 *
 * Idempotency
 * -----------
 * Uses INSERT ... SELECT ... ON DUPLICATE KEY UPDATE against the existing
 * uq_entity_action UNIQUE KEY. Safe to re-run any number of times.
 *
 * Existing sig_path values are never overwritten:
 *   sig_path = COALESCE(workflow_signatures.sig_path, VALUES(sig_path))
 *
 * Existing signed_at values are preserved:
 *   signed_at = signed_at        (defeats ON UPDATE CURRENT_TIMESTAMP)
 *
 * Defensive guards
 * ----------------
 *  - Verifies workflow_signatures exists and has the uq_entity_action key.
 *  - Skips any doc type whose source table is missing.
 *  - Skips any doc type whose source table has no `created_by` column.
 *  - Falls back to NULL for sig_path if user_signatures table does not exist.
 *  - Per-doc-type errors are logged but the loop continues; non-zero exit
 *    at the end if any doc type failed.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: backfill workflow 'created' signatures...\n";

$failures = 0;
$totals   = [];

try {
    // ── Precondition 1: workflow_signatures table exists ────────────────────
    $exists = $pdo->query("SHOW TABLES LIKE 'workflow_signatures'")->fetch();
    if (!$exists) {
        echo "  ! workflow_signatures table not found — run create migration first.\n";
        exit(1);
    }

    // ── Precondition 2: uq_entity_action UNIQUE KEY exists ──────────────────
    $hasKey = false;
    $idx = $pdo->query("SHOW INDEX FROM workflow_signatures WHERE Key_name = 'uq_entity_action'")->fetchAll();
    if ($idx) $hasKey = true;
    if (!$hasKey) {
        echo "  ! UNIQUE KEY uq_entity_action missing on workflow_signatures.\n";
        echo "    Cannot safely run idempotent backfill. Aborting.\n";
        exit(1);
    }

    // ── Optional: user_signatures table presence dictates sig_path lookup ──
    $hasUserSig = (bool)$pdo->query("SHOW TABLES LIKE 'user_signatures'")->fetch();
    if (!$hasUserSig) {
        echo "  ! user_signatures table not found — sig_path will be NULL for all rows.\n";
    }

    // ── Doc-type map: entity_type → (table, pk, created_by, created_at) ────
    $docs = [
        // entity_type      table                              pk                 created_by   created_at
        ['quotation',       'quotations',                      'sales_order_id',  'created_by', 'created_at'],
        ['sales_order',     'sales_orders',                    'sales_order_id',  'created_by', 'created_at'],
        ['invoice',         'invoices',                        'invoice_id',      'created_by', 'created_at'],
        ['purchase_order',  'purchase_orders',                 'purchase_order_id','created_by','created_at'],
        ['rfq',             'rfq',                             'rfq_id',          'created_by', 'created_at'],
        ['grn',             'purchase_receipts',               'receipt_id',      'created_by', 'created_at'],
        ['delivery',        'deliveries',                      'delivery_id',     'created_by', 'created_at'],
        ['ipc',             'interim_payment_certificates',    'ipc_id',          'created_by', 'created_at'],
    ];

    $sigPathExpr = $hasUserSig
        ? "(SELECT us.file_path
              FROM user_signatures us
             WHERE us.user_id = t.{cb}
          ORDER BY us.updated_at DESC, us.id DESC
             LIMIT 1)"
        : "NULL";

    foreach ($docs as [$entityType, $table, $pk, $cb, $ca]) {
        echo "\n── {$entityType} (from {$table}) ──\n";
        try {
            // Skip if table missing
            if (!$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch()) {
                echo "    · table '{$table}' not found — skipping.\n";
                continue;
            }

            // Skip if created_by column missing
            if (!$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$cb}'")->fetch()) {
                echo "    · column '{$cb}' missing on '{$table}' — skipping.\n";
                continue;
            }

            // If created_at missing, fall back to NOW() in SELECT
            $caExpr = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$ca}'")->fetch()
                ? "COALESCE(t.{$ca}, NOW())"
                : "NOW()";

            $sigPathSub = str_replace('{cb}', $cb, $sigPathExpr);

            $sql = "
                INSERT INTO workflow_signatures
                    (entity_type, entity_id, action, user_id,
                     user_name, user_role, sig_path, ip_address,
                     signed_at, consent_accepted)
                SELECT
                    '{$entityType}'                                              AS entity_type,
                    t.`{$pk}`                                                    AS entity_id,
                    'created'                                                    AS action,
                    t.`{$cb}`                                                    AS user_id,
                    TRIM(CONCAT(COALESCE(u.first_name,''),' ',
                                COALESCE(u.last_name,'')))                       AS user_name,
                    COALESCE(u.user_role, u.role, 'Staff')                       AS user_role,
                    {$sigPathSub}                                                AS sig_path,
                    NULL                                                         AS ip_address,
                    {$caExpr}                                                    AS signed_at,
                    1                                                            AS consent_accepted
                FROM `{$table}` t
                LEFT JOIN users u ON u.user_id = t.`{$cb}`
                WHERE t.`{$cb}` IS NOT NULL
                ON DUPLICATE KEY UPDATE
                    user_name = VALUES(user_name),
                    user_role = VALUES(user_role),
                    sig_path  = COALESCE(workflow_signatures.sig_path, VALUES(sig_path)),
                    signed_at = signed_at
            ";

            $affected = $pdo->exec($sql);
            $totals[$entityType] = $affected;
            echo "    + processed (affected rows: {$affected}).\n";
        } catch (PDOException $e) {
            $failures++;
            echo "    ! FAILED: " . $e->getMessage() . "\n";
        }
    }

    echo "\n── Summary ──\n";
    foreach ($totals as $type => $n) {
        echo "    {$type}: {$n} affected\n";
    }
    if ($failures > 0) {
        echo "\nMigration finished with {$failures} doc-type failure(s).\n";
        exit(1);
    }
    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
