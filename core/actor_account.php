<?php
/**
 * core/actor_account.php — Phase 2 actor-as-account service.
 *
 * Every new actor (customer/supplier/sub-contractor/employee) gets its own GL
 * sub-account under the matching control account:
 *   customer       → Trade Debtors  1-1200  → code 1-1200-CUST-NNNNN
 *   supplier       → Trade Creditors 2-1200 → code 2-1200-SUP-NNNNN
 *   sub_contractor → Trade Creditors 2-1200 → code 2-1200-SUB-NNNNN
 *   employee       → Salaries Payable 2-1440 → code 2-1440-EMP-NNNNN
 *
 * Called from add_customer / add_supplier / add_sub_contractor / add_employee
 * right after the actor INSERT.  Idempotent — if ledger_account_id is already
 * set on the actor row, returns the existing account_id immediately.
 */

/**
 * Ensures a GL sub-account exists for the actor and links it via ledger_account_id.
 *
 * @param PDO    $pdo
 * @param string $actorType  'customer' | 'supplier' | 'sub_contractor' | 'employee'
 * @param int    $actorId    PK of the actor row
 * @param string $actorName  Becomes the GL account name (actor's own name)
 * @return int   account_id of the GL sub-account
 * @throws Exception if the control parent is absent or the insert fails
 */
function ensureActorLedgerAccount(PDO $pdo, string $actorType, int $actorId, string $actorName): int
{
    static $cfg = [
        'customer'       => ['table' => 'customers',      'pk' => 'customer_id', 'parent_code' => '1-1200', 'prefix' => 'CUST'],
        'supplier'       => ['table' => 'suppliers',       'pk' => 'supplier_id', 'parent_code' => '2-1200', 'prefix' => 'SUP'],
        'sub_contractor' => ['table' => 'sub_contractors', 'pk' => 'supplier_id', 'parent_code' => '2-1200', 'prefix' => 'SUB'],
        'employee'       => ['table' => 'employees',       'pk' => 'employee_id', 'parent_code' => '2-1440', 'prefix' => 'EMP'],
    ];

    if (!isset($cfg[$actorType])) {
        throw new Exception("ensureActorLedgerAccount: unknown actor type '$actorType'.");
    }

    $c     = $cfg[$actorType];
    $table = $c['table'];
    $pk    = $c['pk'];

    // Idempotent: already linked → return immediately.
    $chk = $pdo->prepare("SELECT ledger_account_id FROM `$table` WHERE `$pk` = ? LIMIT 1");
    $chk->execute([$actorId]);
    $linked = $chk->fetchColumn();
    if ($linked) {
        return (int) $linked;
    }

    // Resolve the control-parent account.
    $par_stmt = $pdo->prepare(
        "SELECT account_id, account_type, account_type_id, normal_balance, level FROM accounts
         WHERE account_code = ? AND status = 'active' LIMIT 1"
    );
    $par_stmt->execute([$c['parent_code']]);
    $par = $par_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$par) {
        throw new Exception(
            "Control account '{$c['parent_code']}' not found or inactive. " .
            "Cannot create GL sub-account for $actorType #$actorId."
        );
    }

    $code  = $c['parent_code'] . '-' . $c['prefix'] . '-' . str_pad($actorId, 5, '0', STR_PAD_LEFT);
    $level = (int) $par['level'] + 1;

    // Code-existence guard (safe if a previous partial run already created it).
    $ea = $pdo->prepare("SELECT account_id FROM accounts WHERE account_code = ? LIMIT 1");
    $ea->execute([$code]);
    $acc_id = (int) ($ea->fetchColumn() ?: 0);

    if (!$acc_id) {
        $ins = $pdo->prepare("
            INSERT INTO accounts
                (account_code, account_name, account_type, account_type_id, parent_account_id, level,
                 normal_balance, status, is_system, is_subledger, opening_balance, current_balance, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, 1, 0.00, 0.00, NOW())
        ");
        $ins->execute([
            $code,
            trim($actorName),
            $par['account_type'],
            $par['account_type_id'],
            (int) $par['account_id'],
            $level,
            $par['normal_balance'],
        ]);
        $acc_id = (int) $pdo->lastInsertId();
        if (!$acc_id) {
            throw new Exception("GL account INSERT returned no ID for $actorType #$actorId.");
        }
    }

    // Write the link back to the actor row.
    $pdo->prepare("UPDATE `$table` SET ledger_account_id = ? WHERE `$pk` = ?")->execute([$acc_id, $actorId]);

    return $acc_id;
}
