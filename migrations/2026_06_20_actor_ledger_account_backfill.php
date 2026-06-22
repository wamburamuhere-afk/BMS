<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/actor_account.php';
global $pdo;

/**
 * Phase 3 — Actor-as-account backfill.
 *
 * Creates GL sub-accounts for every existing actor that has no ledger_account_id
 * yet (i.e. was created before Phase 2 was deployed).
 *
 * DML only — wrapped in one transaction; rolls back everything on any error.
 * Idempotent: skips rows that already have ledger_account_id set.
 */

echo "Starting migration: actor GL sub-account backfill (Phase 3)...\n";

$targets = [
    [
        'type'       => 'customer',
        'table'      => 'customers',
        'pk'         => 'customer_id',
        'name_sql'   => 'customer_name',
        'where'      => "status != 'deleted'",
    ],
    [
        'type'       => 'supplier',
        'table'      => 'suppliers',
        'pk'         => 'supplier_id',
        'name_sql'   => 'supplier_name',
        'where'      => "status != 'deleted'",
    ],
    [
        'type'       => 'sub_contractor',
        'table'      => 'sub_contractors',
        'pk'         => 'supplier_id',
        'name_sql'   => 'supplier_name',
        'where'      => "status != 'deleted'",
    ],
    [
        'type'       => 'employee',
        'table'      => 'employees',
        'pk'         => 'employee_id',
        'name_sql'   => null, // built from first/middle/last below
        'where'      => "status != 'deleted' OR status IS NULL",
    ],
];

try {
    $pdo->beginTransaction();

    $total_created = 0;
    $total_skipped = 0;

    foreach ($targets as $t) {
        $type  = $t['type'];
        $table = $t['table'];
        $pk    = $t['pk'];
        $where = $t['where'];

        if ($t['name_sql']) {
            $cols = "$pk, {$t['name_sql']}";
        } else {
            $cols = "$pk, first_name, middle_name, last_name";
        }

        $rows = $pdo->query(
            "SELECT $cols FROM `$table`
             WHERE ledger_account_id IS NULL AND ($where)
             ORDER BY $pk ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $actorId = (int) $row[$pk];

            if ($t['name_sql']) {
                $actorName = trim((string) $row[$t['name_sql']]);
            } else {
                $parts = array_filter([
                    trim($row['first_name']   ?? ''),
                    trim($row['middle_name']  ?? ''),
                    trim($row['last_name']    ?? ''),
                ]);
                $actorName = implode(' ', $parts);
            }

            if ($actorName === '') {
                echo "  - $type #$actorId has no name — skipping.\n";
                $skipped++;
                continue;
            }

            $accId = ensureActorLedgerAccount($pdo, $type, $actorId, $actorName);
            echo "  - $type #$actorId ($actorName) → account #$accId\n";
            $created++;
        }

        echo "  [$table] created: $created  skipped: $skipped\n";
        $total_created += $created;
        $total_skipped += $skipped;
    }

    // Ensure account_type_id is set on all actor sub-accounts (inherited from parent).
    $fixed = $pdo->exec("
        UPDATE accounts a
        JOIN accounts p ON p.account_id = a.parent_account_id
        SET a.account_type_id = p.account_type_id
        WHERE a.account_type_id IS NULL
          AND a.account_code REGEXP '^[12]-[0-9]+-[A-Z]+-[0-9]+\$'
          AND p.account_type_id IS NOT NULL
    ");
    if ($fixed > 0) echo "  - fixed account_type_id on $fixed actor sub-accounts.\n";

    $pdo->commit();
    echo "Migration complete. Sub-accounts created: $total_created  skipped: $total_skipped\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
