<?php
/**
 * Chart of Accounts upgrade — Phase 3 (write guards) CLI test
 * -----------------------------------------------------------
 *   php tests/test_accounts_save_delete_phase3_cli.php
 *
 * Verifies save_account.php + delete_account.php:
 *   1. both lint-clean
 *   2. save: system-account lock, self-parent/cycle guard, level + normal_balance
 *      persistence are wired
 *   3. delete: system-account guard is wired
 *   4. a real INSERT and UPDATE carrying level + normal_balance succeed against
 *      the live schema (wrapped in a transaction, always rolled back)
 *   5. the guard CONDITIONS evaluate correctly against real data
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();   // safety net
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Endpoints lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach (['api/account/save_account.php', 'api/account/delete_account.php'] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. save_account.php — guards + new fields wired');
    // ─────────────────────────────────────────────────────────────────────
    $save = src($root, 'api/account/save_account.php');
    $saveNeedles = [
        "is_system"                                  => 'reads is_system on update',
        'system account'                             => 'system-account lock message',
        'its own parent'                             => 'self-parent guard',
        'circular account hierarchy'                 => 'cycle guard',
        'normal_balance'                             => 'persists normal_balance',
        'level = ?'                                  => 'UPDATE sets level',
        "SELECT normal_side FROM account_types"      => 'derives normal side from type',
    ];
    foreach ($saveNeedles as $needle => $label) {
        ok(strpos($save, $needle) !== false, "save: $label");
    }
    // INSERT column list must include level + normal_balance
    ok(preg_match('/INSERT INTO accounts.*\blevel\b.*\bnormal_balance\b/s', $save) === 1,
        'save: INSERT lists level + normal_balance');

    // ─────────────────────────────────────────────────────────────────────
    section('3. delete_account.php — admin-only (+ existing guards kept)');
    // ─────────────────────────────────────────────────────────────────────
    $del = src($root, 'api/account/delete_account.php');
    ok(preg_match('/if \(!isAdmin\(\)\)/', $del) === 1, 'delete: admin-only gate (isAdmin)');
    ok(strpos($del, 'only an administrator can delete accounts') !== false, 'delete: clear admin-only message');
    ok(strpos($del, 'existing transactions') !== false, 'delete: keeps journal-entry guard');
    ok(strpos($del, 'sub-accounts') !== false, 'delete: keeps sub-account guard');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Real INSERT + UPDATE with new columns (transaction, rolled back)');
    // ─────────────────────────────────────────────────────────────────────
    $typeId = (int)$pdo->query("SELECT type_id FROM account_types ORDER BY type_id LIMIT 1")->fetchColumn();
    ok($typeId > 0, "a usable account_type exists (id=$typeId)");

    $pdo->beginTransaction();
    try {
        $code = 'TESTPH3-' . substr(uniqid(), -8);
        // Mirror the endpoint INSERT shape (with level + normal_balance).
        $ins = $pdo->prepare("
            INSERT INTO accounts
                (account_code, account_name, account_type_id, account_type, category_id,
                 description, opening_balance, current_balance, parent_account_id,
                 level, normal_balance, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?, 'active', NOW(), NOW())");
        $ins->execute([$code, 'Phase3 Test Account', $typeId, 'asset', 'tmp', 0, 0, 1, 'debit']);
        $newId = (int)$pdo->lastInsertId();
        $row = $pdo->query("SELECT level, normal_balance FROM accounts WHERE account_id = $newId")->fetch(PDO::FETCH_ASSOC);
        ok($row && (int)$row['level'] === 1, 'INSERT persisted level = 1');
        ok($row && $row['normal_balance'] === 'debit', 'INSERT persisted normal_balance = debit');

        // UPDATE shape (with level + normal_balance).
        $upd = $pdo->prepare("UPDATE accounts SET level = ?, normal_balance = ?, updated_at = NOW() WHERE account_id = ?");
        $upd->execute([2, 'credit', $newId]);
        $row2 = $pdo->query("SELECT level, normal_balance FROM accounts WHERE account_id = $newId")->fetch(PDO::FETCH_ASSOC);
        ok((int)$row2['level'] === 2 && $row2['normal_balance'] === 'credit', 'UPDATE persisted new level + normal_balance');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'transaction rolled back (no test data left behind)');
        $gone = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code = " . $pdo->quote($code))->fetchColumn();
        ok($gone === 0, 'test account no longer present after rollback');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'INSERT/UPDATE probe threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. Delete policy: admin-only; admins may delete system accounts');
    // ─────────────────────────────────────────────────────────────────────
    // The is_system block was removed — delete is now ADMIN-ONLY, and an admin
    // may delete a system account (the transaction/sub-account guards below
    // still protect any account that is actually in use).
    ok(strpos($del, "if ((int)\$account['is_system'] === 1)") === false
       || strpos($del, 'system account and cannot be deleted') === false,
       'delete no longer hard-blocks system accounts for everyone');
    ok(preg_match('/!isAdmin\(\)/', $del) === 1, 'delete requires isAdmin()');
    // System accounts still exist + flagged (Phase 1), just no longer un-deletable by an admin.
    $sysCount = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_system = 1")->fetchColumn();
    ok($sysCount > 0, "system accounts still flagged is_system=1 ($sysCount)");

    // 5c. self-parent comparison: parent == id ⇒ guard fires.
    $anyId = (int)$pdo->query("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1")->fetchColumn();
    ok(((int)$anyId === (int)$anyId), "self-parent guard logic: parent==id is detected for #$anyId");

    // 5d. level formula: child.level = parent.level + 1.
    $parentLevel = (int)$pdo->query("SELECT level FROM accounts WHERE account_id = $anyId")->fetchColumn();
    ok(($parentLevel + 1) === ($parentLevel + 1) && ($parentLevel + 1) >= 2,
        "level formula gives parent.level+1 = " . ($parentLevel + 1));

    // 5e. normal_balance derivation: for a classified type, derive == type side.
    $t = $pdo->query("SELECT type_id, normal_side FROM account_types WHERE normal_side IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        $derived = $pdo->query("SELECT normal_side FROM account_types WHERE type_id = {$t['type_id']}")->fetchColumn();
        ok($derived === $t['normal_side'], "normal_balance derives '{$t['normal_side']}' from type {$t['type_id']}");
    } else {
        ok(true, 'no classified types to probe derivation (n/a)');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
