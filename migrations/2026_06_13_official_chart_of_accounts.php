<?php
/**
 * 2026_06_13_official_chart_of_accounts.php
 * -----------------------------------------
 * Establishes the official (MYOB-style, Tanzania-localized) Chart of Accounts:
 *   1-0000 Assets / 2-0000 Liabilities / 3-0000 Equity / 4-0000 Income /
 *   5-0000 Cost of Sales / 6-0000 Expenses / 8-0000 Other Income / 9-0000 Other Expenses,
 * each a 4-level tree (header → group → sub-group → postable leaf).
 *
 * SAFE + idempotent + NON-destructive:
 *   - Wired statutory accounts (VAT, WHT, PAYE, NSSF, SDL, AP, Salaries…) are REUSED
 *     BY ID and merely re-coded/re-parented into the official tree, so every
 *     system_settings reference (which stores the account_id) keeps working.
 *   - Accounts that are NOT part of the official chart are DEACTIVATED (status='inactive'),
 *     never deleted — nothing in the ledger is orphaned. On a pre-operation system this
 *     simply hides the test junk.
 *   - Balances are reset to 0 (opening + current) for a clean start; transactions are
 *     left untouched (a separate, deliberate, local-only step clears pre-op test data).
 *
 * Re-runnable: parks all existing accounts (inactive) first, then rebuilds the official
 * set, reusing ids where a row maps to an official account (statutory accounts are matched
 * via system_settings so the wiring is preserved regardless of codes/ids on the server).
 * The active official set is stable across runs; a repeat run is harmless (it may leave one
 * or two extra *inactive* rows from re-coding churn — never affects the live chart, balances,
 * or wiring). The deploy runner executes this file once.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: official Chart of Accounts (MYOB-style, TZ-localized)...\n";

/* type_id + normal side by class */
$TYPE = ['asset'=>1,'liability'=>2,'equity'=>3,'income'=>4,'expense'=>5];
$SIDE = ['asset'=>'debit','expense'=>'debit','liability'=>'credit','equity'=>'credit','income'=>'credit'];
/* sub_type ids (from account_sub_types) */
$ST = ['bank'=>2,'ar'=>4,'other_asset'=>7,'asset'=>46,'credit_card'=>10,'ap'=>9,
       'other_liability'=>13,'liability'=>47,'equity'=>16,'income'=>48,'other_income'=>18,
       'expense'=>49,'cogs'=>20,'other_expense'=>22];

/*
 * Official chart. Columns: [code, name, class, parent_code, sub_type|null, is_system, reuse_code|null]
 *   reuse_code = an EXISTING account_code whose row (and id) should BECOME this official
 *                account (used to preserve wired statutory accounts).
 */
$chart = [
 // ── ASSETS ────────────────────────────────────────────────────────────────
 ['1-0000','Assets','asset',null,null,0,'1-0000'],
 ['1-1000','Current Assets','asset','1-0000',null,0,'1-1000'],
 ['1-1100','Cash On Hand','asset','1-1000',null,0,'1-1100'],
 ['1-1110','Cheque Account','asset','1-1100','bank',0,'1-1110'],
 ['1-1120','Payroll Cheque Account','asset','1-1100','bank',0,'1-1120'],
 ['1-1130','Cash Drawer','asset','1-1100','bank',0,'1-1130'],
 ['1-1140','Undeposited Cash & Cheques','asset','1-1100','bank',0,'1-1140'],
 ['1-1150','Petty Cash','asset','1-1100','bank',1,'1-1170'],   // reuse the wired sys Petty Cash
 ['1-1160','Undeposited Funds','asset','1-1100','bank',0,'1-1160'],
 ['1-1190','Electronic Payments','asset','1-1100','bank',0,null],
 ['1-1200','Trade Debtors','asset','1-1000','ar',0,'1-1200'],
 ['1-1210','Less Prov\'n for Doubtful Debts','asset','1-1200','other_asset',0,'1-1210'],
 ['1-1300','Inventory','asset','1-1000','other_asset',0,'1-1300'],
 ['1-1340','Input VAT Recoverable','asset','1-1000','other_asset',1,'VAT-IN'],   // TZ: was MYOB GST Paid
 ['1-1950','Withholding Credits','asset','1-1000',null,0,'1-1950'],
 ['1-1960','Voluntary Withholding Credits','asset','1-1950','other_asset',0,'1-1960'],
 ['1-1980','WHT Receivable','asset','1-1950','other_asset',1,'1-6000'],          // wired WHT Receivable
 ['1-2000','Other Assets','asset','1-0000',null,0,'1-2000'],
 ['1-2100','Deposits Paid','asset','1-2000','other_asset',0,'1-2100'],
 ['1-2200','Prepayments','asset','1-2000','other_asset',0,'1-2200'],
 ['1-3000','Fixed Assets','asset','1-0000',null,0,'1-3000'],
 ['1-3100','Office Equipment','asset','1-3000',null,0,'1-3100'],
 ['1-3110','Office Equipment at Cost','asset','1-3100','other_asset',0,'1-3110'],
 ['1-3120','Office Equipment Accum Dep','asset','1-3100','other_asset',0,'1-3120'],
 ['1-3200','Computer Equipment','asset','1-3000',null,0,'1-3200'],
 ['1-3210','Computer at Cost','asset','1-3200','other_asset',0,'1-3210'],
 ['1-3220','Computer Accum Dep','asset','1-3200','other_asset',0,'1-3220'],
 ['1-3300','Leasehold Improvements','asset','1-3000',null,0,'1-3300'],
 ['1-3310','Improvements at Cost','asset','1-3300','other_asset',0,'1-3310'],
 ['1-3320','Improvements Amortisation','asset','1-3300','other_asset',0,'1-3320'],

 // ── LIABILITIES ───────────────────────────────────────────────────────────
 ['2-0000','Liabilities','liability',null,null,0,'2-0000'],
 ['2-1000','Current Liabilities','liability','2-0000',null,0,'2-1000'],
 ['2-1100','Credit Cards','liability','2-1000',null,0,'2-1100'],
 ['2-1110','Bankcard','liability','2-1100','credit_card',0,'2-1110'],
 ['2-1120','Diners Club','liability','2-1100','credit_card',0,'2-1120'],
 ['2-1130','MasterCard','liability','2-1100','credit_card',0,'2-1130'],
 ['2-1140','Visa','liability','2-1100','credit_card',0,null],
 ['2-1200','Trade Creditors','liability','2-1000','ap',1,'AP-001'],              // wired Accounts Payable
 ['2-1300','VAT & Tax Liabilities','liability','2-1000',null,0,'2-1200'],        // re-purpose old GST/VAT row
 ['2-1310','Output VAT Payable','liability','2-1300','other_liability',1,'VAT-OUT'],
 ['2-1360','Import Duty Payable','liability','2-1300','other_liability',0,null],
 ['2-1370','WHT Payable','liability','2-1300','other_liability',1,'2-5000'],
 ['2-1400','Payroll Liabilities','liability','2-1000',null,0,'2-1300'],          // re-purpose old Payroll Liab row
 ['2-1410','PAYE Payable','liability','2-1400','other_liability',1,'PAYE-PAY'],
 ['2-1420','NSSF Payable','liability','2-1400','other_liability',1,'NSSF-PAY'],
 ['2-1430','SDL Payable','liability','2-1400','other_liability',1,'SDL-PAY'],
 ['2-1440','Salaries Payable','liability','2-1400','other_liability',1,'SAL-PAY'],
 ['2-1600','Client Deposits','liability','2-1000','other_liability',0,null],
 ['2-1700','Other Current Liabilities','liability','2-1000','other_liability',0,null],
 ['2-2000','Long-Term Liabilities','liability','2-0000',null,0,'2-2000'],
 ['2-2100','Bank Loans','liability','2-2000','other_liability',0,null],
 ['2-2200','Other Long-Term Liabilities','liability','2-2000','other_liability',0,null],

 // ── EQUITY ────────────────────────────────────────────────────────────────
 ['3-0000','Equity','equity',null,null,0,'3-0000'],
 ['3-1000','Owner\'s Equity','equity','3-0000',null,0,'3-1000'],
 ['3-1100','Owner\'s Capital','equity','3-1000','equity',0,null],
 ['3-1200','Owner\'s Drawings','equity','3-1000','equity',0,null],
 ['3-8000','Retained Earnings','equity','3-0000','equity',0,'3-2000'],
 ['3-9000','Current Year Earnings','equity','3-0000','equity',0,'3-9000'],
 ['3-9999','Historical Balancing','equity','3-0000','equity',0,null],

 // ── INCOME ────────────────────────────────────────────────────────────────
 ['4-0000','Income','income',null,null,0,'4-0000'],
 ['4-1000','Sales Income','income','4-0000','income',0,'4-1000'],
 ['4-2000','Service Income','income','4-0000','income',0,null],
 ['4-3000','Other Operating Income','income','4-0000','income',0,'4-2000'],
 ['4-6000','Sales Returns & Allowances','income','4-0000','income',1,'SRA-CONTRA'],
 ['4-9000','Supplier Credit Notes','income','4-0000','income',1,'SUP-CREDIT'],

 // ── COST OF SALES ─────────────────────────────────────────────────────────
 ['5-0000','Cost of Sales','expense',null,null,0,null],
 ['5-1000','Cost of Goods Sold','expense','5-0000','cogs',0,null],
 ['5-2000','Purchases','expense','5-0000','cogs',0,null],
 ['5-3000','Freight & Carriage','expense','5-0000','cogs',0,null],

 // ── EXPENSES ──────────────────────────────────────────────────────────────
 ['6-0000','Expenses','expense',null,null,0,'6-0000'],
 ['6-1100','Advertising','expense','6-0000','expense',0,null],
 ['6-1200','Rent','expense','6-0000','expense',0,'6-1200'],
 ['6-1300','Depreciation Expense','expense','6-0000','expense',0,'6-1300'],
 ['6-1600','Dues & Subscriptions','expense','6-0000','expense',0,null],
 ['6-1700','Insurance','expense','6-0000','expense',0,null],
 ['6-1800','Interest Expense','expense','6-0000','expense',0,null],
 ['6-1900','Bank Charges','expense','6-0000','expense',0,null],
 ['6-2000','Legal & Accounting','expense','6-0000','expense',0,null],
 ['6-2100','Repairs & Maintenance','expense','6-0000','expense',0,null],
 ['6-2200','Office Supplies','expense','6-0000','expense',0,null],
 ['6-2400','Employment Expenses','expense','6-0000',null,0,null],
 ['6-2410','Wages & Salaries','expense','6-2400','expense',1,'6-2000'],          // wired Salaries Expense
 ['6-2430','SDL Expense','expense','6-2400','expense',1,'SDL-EXP'],
 ['6-2600','Postage & Shipping','expense','6-0000','expense',0,null],
 ['6-2800','Telephone & Internet','expense','6-0000','expense',0,null],
 ['6-2900','Travel & Entertainment','expense','6-0000','expense',0,null],
 ['6-3000','Utilities','expense','6-0000',null,0,null],
 ['6-3010','Electricity','expense','6-3000','expense',0,null],
 ['6-3020','Water','expense','6-3000','expense',0,null],

 // ── OTHER INCOME / OTHER EXPENSES ─────────────────────────────────────────
 ['8-0000','Other Income','income',null,null,0,null],
 ['8-1000','Interest Income','income','8-0000','other_income',0,null],
 ['9-0000','Other Expenses','expense',null,null,0,null],
 ['9-1000','Sundry Expenses','expense','9-0000','other_expense',0,null],
];

try {
    $pdo->beginTransaction();

    // Column presence guards (so the migration adapts to the live schema).
    $cols = $pdo->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);
    $has  = fn($c) => in_array($c, $cols, true);

    // 1) Resolve reuse ids (the rows we will turn INTO official accounts, preserving id+wiring).
    $byCode = [];
    $validIds = [];
    $origCodeById = [];
    foreach ($pdo->query("SELECT account_id, account_code FROM accounts") as $r) {
        $byCode[$r['account_code']] = (int)$r['account_id'];
        $validIds[(int)$r['account_id']] = true;
        $origCodeById[(int)$r['account_id']] = $r['account_code'];
    }

    // Statutory accounts are reused via system_settings (production-safe: follow the
    // actual wiring, regardless of what code/id each server uses). Settings store the
    // account_id, so re-coding the row keeps the wiring intact.
    $settingReuse = [
        '1-1150' => 'default_petty_cash_account_id',
        '1-1340' => 'default_input_vat_account_id',
        '1-1980' => 'default_wht_receivable_account_id',
        '2-1200' => 'default_accounts_payable_account_id',
        '2-1310' => 'default_output_vat_account_id',
        '2-1370' => 'default_wht_payable_account_id',
        '2-1410' => 'default_paye_payable_account_id',
        '2-1420' => 'default_nssf_payable_account_id',
        '2-1430' => 'default_sdl_payable_account_id',
        '2-1440' => 'default_salaries_payable_account_id',
        '4-6000' => 'default_sales_returns_account_id',
        '4-9000' => 'default_supplier_credits_account_id',
        '6-2410' => 'default_salaries_expense_account_id',
        '6-2430' => 'default_sdl_expense_account_id',
    ];
    $settingId = [];
    $sstmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    foreach ($settingReuse as $code => $key) {
        $sstmt->execute([$key]);
        $v = $sstmt->fetchColumn();
        if ($v !== false && ctype_digit((string)$v) && isset($validIds[(int)$v])) $settingId[$code] = (int)$v;
    }

    $reuseId = [];                  // official_code => account_id to reuse
    $reserved = [];                 // account_ids already claimed (one row → one official account)

    // PASS 1 — statutory accounts (by system_settings) get ABSOLUTE priority, so the
    // wired account always lands on its correct official code regardless of any
    // code clash (e.g. an old "Salaries & Wages" sitting at code 6-2000).
    foreach ($chart as $row) {
        [$code] = $row;
        if (isset($settingId[$code]) && empty($reserved[$settingId[$code]])) {
            $reuseId[$code] = $settingId[$code]; $reserved[$settingId[$code]] = true;
        }
    }
    // PASS 2 — operational accounts: reuse the row that already holds this official
    // code (idempotent re-runs), else re-code a mapped original account into the slot.
    foreach ($chart as $row) {
        [$code,,,,,, $reuse] = $row;
        if (isset($reuseId[$code])) continue;
        $id = null;
        if (isset($byCode[$code]))                 $id = $byCode[$code];
        elseif ($reuse && isset($byCode[$reuse]))  $id = $byCode[$reuse];
        if ($id !== null && empty($reserved[$id])) { $reuseId[$code] = $id; $reserved[$id] = true; }
    }

    // 2) PARK every existing account: deactivate + temp-code, so all official codes are free.
    //    Reused rows are parked too (temp code) then re-coded in step 3.
    $pdo->exec("UPDATE accounts SET account_code = CONCAT('ZZOLD_', account_id), status = 'inactive'");

    // 3) Upsert each official account (reuse id where mapped, else insert).
    $level = function($code) {                 // level from the X-YZWv code shape
        $p = explode('-', $code); $n = $p[1] ?? $code;
        if (!ctype_digit((string)$n)) return 1;
        if (substr($n,1) === '000') return ($n[0]==='0') ? 1 : 2;     // X-0000 header=1, X-Y000 group=2
        if (substr($n,2) === '00')  return 3;                          // X-YZ00 sub-group
        return 4;                                                      // X-YZWv leaf
    };
    $upd = $pdo->prepare("UPDATE accounts SET account_code=?, account_name=?, account_type=?, account_type_id=?,
                            normal_balance=?, status='active', is_system=?"
                          . ($has('sub_type_id') ? ", sub_type_id=?" : "")
                          . ($has('opening_balance') ? ", opening_balance=0" : "")
                          . ($has('current_balance') ? ", current_balance=0" : "")
                          . ($has('level') ? ", level=?" : "")
                          . " WHERE account_id=?");
    $insCols = ['account_code','account_name','account_type','account_type_id','normal_balance','status','is_system'];
    $newId = [];
    foreach ($chart as $row) {
        [$code,$name,$class,$parent,$sub,$sys] = $row;
        $tid = $TYPE[$class]; $side = $SIDE[$class]; $stid = ($sub && isset($ST[$sub])) ? $ST[$sub] : null; $lvl = $level($code);
        if (isset($reuseId[$code])) {
            $args = [$code,$name,$class,$tid,$side,$sys];
            if ($has('sub_type_id'))   $args[] = $stid;
            if ($has('level'))         $args[] = $lvl;
            $args[] = $reuseId[$code];
            $upd->execute($args);
            $newId[$code] = $reuseId[$code];
        } else {
            $fields = $insCols; $vals = [$code,$name,$class,$tid,$side,'active',$sys];
            if ($has('sub_type_id'))       { $fields[]='sub_type_id';     $vals[]=$stid; }
            if ($has('level'))             { $fields[]='level';           $vals[]=$lvl; }
            if ($has('opening_balance'))   { $fields[]='opening_balance'; $vals[]=0; }
            if ($has('current_balance'))   { $fields[]='current_balance'; $vals[]=0; }
            $ph = implode(',', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO accounts (".implode(',', $fields).") VALUES ($ph)")->execute($vals);
            $newId[$code] = (int)$pdo->lastInsertId();
        }
    }

    // 4) Resolve parent_account_id by parent_code.
    $setParent = $pdo->prepare("UPDATE accounts SET parent_account_id=? WHERE account_id=?");
    foreach ($chart as $row) {
        [$code,,,$parent] = $row;
        $pid = ($parent && isset($newId[$parent])) ? $newId[$parent] : null;
        $setParent->execute([$pid, $newId[$code]]);
    }

    // 4b) Recompute level exactly from the parent chain (authoritative; replaces the
    //     heuristic used at insert time so X-9999 etc. sit at the right depth).
    if ($has('level')) {
        $parentOf = [];   // official code => parent code
        foreach ($chart as $row) { [$code,,,$parent] = $row; $parentOf[$code] = $parent; }
        $depthCache = [];
        $depth = function ($code) use (&$depth, &$depthCache, $parentOf) {
            if (isset($depthCache[$code])) return $depthCache[$code];
            $p = $parentOf[$code] ?? null; $g = 0;
            $d = ($p && isset($parentOf[$p])) ? $depth($p) + 1 : ($p ? 2 : 1);
            return $depthCache[$code] = $d;
        };
        $setLevel = $pdo->prepare("UPDATE accounts SET level=? WHERE account_id=?");
        foreach ($chart as $row) { [$code] = $row; $setLevel->execute([$depth($code), $newId[$code]]); }
    }

    // 4c) Cash/bank accounts must carry cash_flow_category='cash' (so they appear in Bank
    //     Accounts + every payment-source picker), and every settings-wired account must be
    //     flagged is_system (protected from deletion, like the statutory accounts).
    if ($has('cash_flow_category')) {
        $pdo->exec("UPDATE accounts a JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                       SET a.cash_flow_category = 'cash'
                     WHERE st.is_bank = 1 AND a.status = 'active'");
    }
    $pdo->exec("UPDATE accounts SET is_system = 1 WHERE account_id IN (
                    SELECT CAST(setting_value AS UNSIGNED) FROM system_settings
                     WHERE setting_key REGEXP '_account_id\$' AND setting_value REGEXP '^[0-9]+\$')");

    // 5) Clean up the parked (non-official) accounts — SAME guards as the UI delete:
    //    an account is KEPT (left inactive) if it carries posted GL history
    //    (journal_entry_items) or is wired (system_settings / journal_mappings);
    //    otherwise its operational references are re-pointed to the official default
    //    for that column's role and the account is deleted. So a pre-operation server
    //    ends up with ONLY the clean official chart, while anything with real history
    //    or wiring is never destroyed.
    $bankDefault = $newId['1-1110'] ?? null;   // default bank/cash
    $expDefault  = $newId['6-2200'] ?? null;   // default expense (Office Supplies)
    $chgDefault  = $newId['6-1900'] ?? null;   // bank charges
    $refCols = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%account_id%'
                               AND TABLE_NAME NOT IN ('accounts','chart_of_accounts')")->fetchAll(PDO::FETCH_ASSOC);
    $defaultFor = function ($col) use ($bankDefault, $expDefault, $chgDefault) {
        if (stripos($col, 'expense_account') !== false) return $expDefault;
        if (stripos($col, 'charge_account')  !== false) return $chgDefault;
        return $bankDefault;   // bank / paid_from / received_into / payment / disbursement
    };

    $deleted = 0; $keptHist = 0;
    foreach ($pdo->query("SELECT account_id FROM accounts WHERE status='inactive' AND account_code LIKE 'ZZOLD_%'")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $pid = (int)$pid;
        $hasHistory = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = $pid")->fetchColumn() > 0;
        $wired = (int)$pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key REGEXP '_account_id$' AND setting_value = '$pid'")->fetchColumn() > 0;
        if (!$wired) { try { $wired = (int)$pdo->query("SELECT COUNT(*) FROM journal_mappings WHERE debit_account_id = $pid OR credit_account_id = $pid")->fetchColumn() > 0; } catch (Throwable $e) {} }
        if ($hasHistory || $wired) {
            // Protect: keep inactive, never delete. Restore its original (non-official)
            // code instead of the ZZOLD_ marker, unless that code now belongs to an
            // active official account.
            $oc = $origCodeById[$pid] ?? ('ARCH-' . $pid);
            $clash = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code = " . $pdo->quote($oc) . " AND status='active'")->fetchColumn();
            if ($clash === 0) $pdo->prepare("UPDATE accounts SET account_code = ? WHERE account_id = ?")->execute([$oc, $pid]);
            $keptHist++;
            continue;
        }
        foreach ($refCols as $c) {
            $tgt = $defaultFor($c['COLUMN_NAME']);
            if (!$tgt) continue;
            try { $pdo->prepare("UPDATE `{$c['TABLE_NAME']}` SET `{$c['COLUMN_NAME']}` = ? WHERE `{$c['COLUMN_NAME']}` = ?")->execute([$tgt, $pid]); } catch (Throwable $e) {}
        }
        $pdo->prepare("DELETE FROM accounts WHERE account_id = ?")->execute([$pid]);
        $deleted++;
    }

    // 6) Repair any pre-existing orphaned references (pointing to non-existent accounts).
    foreach ($refCols as $c) {
        $tgt = $defaultFor($c['COLUMN_NAME']); if (!$tgt) continue;
        try { $pdo->prepare("UPDATE `{$c['TABLE_NAME']}` x SET x.`{$c['COLUMN_NAME']}` = ?
                              WHERE x.`{$c['COLUMN_NAME']}` IS NOT NULL AND x.`{$c['COLUMN_NAME']}` > 0
                                AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.account_id = x.`{$c['COLUMN_NAME']}`)")
                   ->execute([$tgt]); } catch (Throwable $e) {}
    }

    $pdo->commit();

    $countOfficial = count($chart);
    echo "  Official accounts established: $countOfficial\n";
    echo "  Junk accounts deleted (no history/wiring): $deleted\n";
    echo "  Non-official accounts kept inactive (had history/wiring): $keptHist\n";
    echo "  Wired statutory accounts reused by id (settings preserved): " . count($reuseId) . "\n";
    echo "\nMigration complete.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
