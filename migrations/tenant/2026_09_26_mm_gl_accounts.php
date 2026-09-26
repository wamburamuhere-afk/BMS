<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../roots.php';
global $pdo;

echo "Starting migration: MM GL accounts provisioning...\n";

try {
    $assetTypeId  = (int)$pdo->query("SELECT type_id FROM account_types WHERE category='asset'    LIMIT 1")->fetchColumn();
    $incomeTypeId = (int)$pdo->query("SELECT type_id FROM account_types WHERE category='revenue'  LIMIT 1")->fetchColumn();
    $expTypeId    = (int)$pdo->query("SELECT type_id FROM account_types WHERE category='expense'  LIMIT 1")->fetchColumn();

    $ensure = function (string $code, string $name, string $type, int $typeId, string $cf, string $nb, int $level, ?int $parentId) use ($pdo): int {
        $existing = $pdo->prepare("SELECT account_id FROM accounts WHERE account_code = ? LIMIT 1");
        $existing->execute([$code]);
        $id = $existing->fetchColumn();
        if ($id) {
            echo "  [skip] $code — $name already exists (id=$id)\n";
            return (int)$id;
        }
        $pdo->prepare(
            "INSERT INTO accounts
                (account_code, account_name, account_type, account_type_id, cash_flow_category,
                 opening_balance, current_balance, status, level, normal_balance, is_system,
                 parent_account_id, created_at, updated_at)
             VALUES (?,?,?,?,?, 0,0,'active',?,?,1, ?,NOW(),NOW())"
        )->execute([$code, $name, $type, $typeId, $cf, $level, $nb, $parentId]);
        $id = (int)$pdo->lastInsertId();
        echo "  [created] $code — $name (id=$id)\n";
        return $id;
    };

    // Parent: Mobile Money (Asset group)
    $mmAssetParent = $ensure('MM-1000', 'Mobile Money Float',   'asset',    $assetTypeId,  'cash',      'debit',  1, null);

    // E-Float per network (current assets under MM-1000)
    $efMpesa   = $ensure('MM-1001', 'E-Float — M-Pesa',        'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);
    $efAirtel  = $ensure('MM-1002', 'E-Float — Airtel Money',  'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);
    $efTigo    = $ensure('MM-1003', 'E-Float — Tigo Pesa',     'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);
    $efHalo    = $ensure('MM-1004', 'E-Float — HaloPesa',      'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);
    $efTpesa   = $ensure('MM-1005', 'E-Float — T-Pesa',        'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);

    // Cash float held at agent outlets
    $cashFloat = $ensure('MM-1050', 'MM Cash at Outlets',      'asset',    $assetTypeId,  'cash',      'debit',  2, $mmAssetParent);

    // Income & expense
    $commIncome = $ensure('MM-4100', 'MM Commission Income',   'income',   $incomeTypeId, 'operating', 'credit', 1, null);
    $mmExpense  = $ensure('MM-5100', 'MM Agent Expenses',      'expense',  $expTypeId,    'operating', 'debit',  1, null);

    // Store IDs in system_settings for quick lookup by the posting engine
    $ups = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()"
    );
    $ups->execute(['mm_gl_efloat_mpesa',   (string)$efMpesa]);
    $ups->execute(['mm_gl_efloat_airtel',  (string)$efAirtel]);
    $ups->execute(['mm_gl_efloat_tigo',    (string)$efTigo]);
    $ups->execute(['mm_gl_efloat_halotel', (string)$efHalo]);
    $ups->execute(['mm_gl_efloat_tpesa',   (string)$efTpesa]);
    $ups->execute(['mm_gl_cash_float',     (string)$cashFloat]);
    $ups->execute(['mm_gl_commission_income', (string)$commIncome]);
    $ups->execute(['mm_gl_agent_expenses',    (string)$mmExpense]);
    echo "  [ok] system_settings updated with all MM GL account IDs.\n";

    // Wire network rows to their e-float GL account
    $nets = [
        'MPESA'   => $efMpesa,
        'AIRTEL'  => $efAirtel,
        'TIGO'    => $efTigo,
        'HALOTEL' => $efHalo,
        'TPESA'   => $efTpesa,
    ];
    foreach ($nets as $code => $acctId) {
        $upd = $pdo->prepare("UPDATE mm_networks SET float_account_id=?, commission_account_id=? WHERE network_code=? AND (float_account_id IS NULL OR float_account_id=0)");
        $upd->execute([$acctId, $commIncome, $code]);
    }
    echo "  [ok] mm_networks gl_efloat_account_id wired.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
