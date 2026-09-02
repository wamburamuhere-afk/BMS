<?php
/**
 * Multi-tenancy — Phase 10 (regression) CLI test
 *   php tests/test_tenant_module_smoke_cli.php
 *
 * THE RISK THIS SUITE COVERS: every other tenant suite proves the plumbing —
 * routing, provisioning, isolation. None of them prove the APPLICATION actually
 * works once $pdo points at bms_t{id} instead of the main database.
 *
 * A tenant database is not a copy of production. It is built from
 * schema/tenant_schema_template.sql plus schema/tenant_seed_defaults.sql, so a
 * table or column that exists in the application database but never made it
 * into the template would break that module for EVERY new customer, while
 * working perfectly for the original company. That class of bug is invisible to
 * every other suite in this repo.
 *
 * What it proves, against a genuinely freshly provisioned tenant:
 *   1. the tenant provisions
 *   2. table parity — the tenant has every table the application database has
 *   3. column parity on the tables the modules below actually query
 *   4. the seeded defaults are usable (chart of accounts + RBAC baseline)
 *   5. the owner account created at signup can actually authenticate
 *   6. all four statutory reports run against a fresh tenant and reconcile
 *   7. every major module's core read path executes inside the tenant
 *   8. a real GL round-trip: post an entry, see it in the Trial Balance and
 *      Balance Sheet
 *
 * ternant.md's Phase 10 asks for this "against Tenant #1 AND a fresh tenant,
 * side by side". Tenant #1 does not exist yet (Phase 7 is still pending), so
 * the comparison is made against the APPLICATION database instead — which is
 * the same comparison in substance: the established schema versus what a new
 * customer is actually given.
 *
 * Creates one throwaway tenant and removes it. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/financial_reports.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function note($m){ echo "  \033[33m•\033[0m $m\n"; }

$made = ['databases' => [], 'users' => []];

function teardown(): void
{
    global $made;
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'smoketest%'");
        $c->exec("DELETE FROM tenants               WHERE subdomain LIKE 'smoketest%'");
    } catch (Throwable $e) {}
    try {
        $a = getProvisioningPdo();
        foreach ($made['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $a->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($made['users'] as $u) {
            try { $a->exec("DROP USER IF EXISTS " . $a->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Tables each module's pages actually read. A missing one breaks that module for every new customer. */
const MODULE_TABLES = [
    'Accounting / GL' => ['accounts', 'account_types', 'account_categories', 'journal_entries', 'journal_entry_items'],
    'Sales'           => ['customers', 'invoices', 'quotations'],
    'Purchasing'      => ['suppliers', 'purchase_orders'],
    'Inventory'       => ['products', 'stock_movements'],
    'POS'             => ['pos_sales', 'pos_sale_items', 'pos_sale_payments'],
    'HR'              => ['employees', 'attendance', 'attendance_rules'],
    'Payroll'         => ['payroll', 'payroll_items', 'payroll_settings'],
    'CRM'             => ['leads', 'crm_lead_activities', 'crm_labels'],
    'Projects'        => ['projects'],
    'Access control'  => ['users', 'roles', 'permissions', 'role_permissions'],
];

try {
    $sfx = bin2hex(random_bytes(3));
    $sub = 'smoketest' . $sfx;
    $ownerPw = 'Password!123';

    // The application database this deployment already runs on — the established
    // schema a new tenant is measured against.
    $appPdo = new PDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ────────────────────────────────────────────────────────────────────────
    section('1. A freshly provisioned tenant');
    $r = provisionTenant('Smoke Test Ltd', $sub, "owner@$sub.test", $ownerPw);
    ok($r['ok'] === true, 'tenant provisioned' . ($r['ok'] ? '' : ': ' . ($r['error'] ?? '')));
    if (!$r['ok']) throw new RuntimeException($r['error'] ?? 'provisioning failed');
    $made['databases'][] = $r['db_name'];
    $made['users'][]     = $r['db_username'];

    $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$r['tenant_id']]);
    $T = $st->fetch();

    $pw = decryptTenantSecret((string)$T['db_password_encrypted']);
    $tPdo = new PDO("mysql:host={$T['db_host']};dbname={$T['db_name']};charset=utf8mb4",
        $T['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ok($tPdo->query('SELECT DATABASE()')->fetchColumn() === $T['db_name'], "connected to {$T['db_name']}");

    // ────────────────────────────────────────────────────────────────────────
    section('2. Table parity — a new customer gets everything the app has');
    $appTables = $appPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tenTables = $tPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing   = array_values(array_diff($appTables, $tenTables));

    note(count($appTables) . ' tables in ' . DB_NAME . ', ' . count($tenTables) . ' in the fresh tenant');
    ok($missing === [], 'no table is missing from a fresh tenant'
        . ($missing ? ' — MISSING: ' . implode(', ', array_slice($missing, 0, 15)) : ''));

    // schema_migrations is expected to exist ONLY in tenants: it is how the
    // per-tenant migration runner tracks what it has applied.
    $extra = array_values(array_diff($tenTables, $appTables));
    ok(in_array('schema_migrations', $tenTables, true),
        'the tenant carries schema_migrations for the per-tenant migration runner');
    $unexpected = array_values(array_diff($extra, ['schema_migrations']));
    ok($unexpected === [], 'no unexpected leftover tables in a fresh tenant'
        . ($unexpected ? ' — FOUND: ' . implode(', ', $unexpected) : ''));

    // ────────────────────────────────────────────────────────────────────────
    section('3. Column parity on the tables the modules query');
    // Table parity alone would miss a column added to the app database by a
    // migration that never reached the schema template.
    $colDrift = [];
    foreach (MODULE_TABLES as $module => $tables) {
        foreach ($tables as $tbl) {
            if (!in_array($tbl, $appTables, true) || !in_array($tbl, $tenTables, true)) continue;
            $a = $appPdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
            $b = $tPdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
            $gone = array_diff($a, $b);
            if ($gone) $colDrift[] = "$tbl(" . implode('/', $gone) . ')';
        }
    }
    ok($colDrift === [], 'every module table has all its application columns'
        . ($colDrift ? ' — DRIFT: ' . implode(', ', $colDrift) : ''));

    // ────────────────────────────────────────────────────────────────────────
    section('4. The seeded defaults are usable');
    $nAcc  = (int)$tPdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $nType = (int)$tPdo->query('SELECT COUNT(*) FROM account_types')->fetchColumn();
    $nRole = (int)$tPdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    $nPerm = (int)$tPdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    $nRp   = (int)$tPdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn();

    ok($nAcc  > 0, "a chart of accounts is seeded ($nAcc accounts)");
    ok($nType > 0, "account types are seeded ($nType)");
    ok($nRole > 0, "roles are seeded ($nRole)");
    ok($nPerm > 0, "permissions are seeded ($nPerm)");
    ok($nRp   > 0, "roles are actually wired to permissions ($nRp mappings)");

    // A chart of accounts that cannot express all five sides cannot produce a
    // balance sheet or a P&L.
    $kinds = $tPdo->query("SELECT DISTINCT LOWER(account_type) k FROM accounts WHERE account_type IS NOT NULL")
                  ->fetchAll(PDO::FETCH_COLUMN);
    $kinds = array_map(fn($k) => (string)$k, $kinds);
    foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $need) {
        $hit = false;
        foreach ($kinds as $k) { if (strpos($k, $need) !== false) { $hit = true; break; } }
        ok($hit, "the seeded chart can express '$need' accounts");
    }

    // ────────────────────────────────────────────────────────────────────────
    section('5. The owner account created at signup can authenticate');
    $owner = $tPdo->query("SELECT * FROM users ORDER BY user_id LIMIT 1")->fetch();
    ok($owner !== false, 'exactly one owner user exists');
    ok((int)$tPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1,
        'and no other account was created alongside it');
    ok(is_array($owner) && password_verify($ownerPw, (string)$owner['password']),
        "the owner's password verifies (they can actually sign in)");
    ok(is_array($owner) && (int)$owner['is_active'] === 1, 'the owner account is active');
    ok(is_array($owner) && strpos((string)$owner['password'], $ownerPw) === false,
        'the password is stored hashed, never in the clear');

    // ────────────────────────────────────────────────────────────────────────
    section('6. All four statutory reports run against a fresh tenant');
    // These are the reports .claude/reporting-source.md designates as the only
    // sanctioned way to read money out of this system.
    $today = date('Y-m-d');
    $from  = date('Y-01-01');

    $tb = glTrialBalance($tPdo, $today);
    ok(is_array($tb), 'Trial Balance runs');
    $pl = glProfitLoss($tPdo, $from, $today);
    ok(is_array($pl), 'Income Statement runs');
    $bs = glBalanceSheet($tPdo, $today);
    ok(is_array($bs), 'Balance Sheet runs');
    $cf = glCashFlow($tPdo, $from, $today);
    ok(is_array($cf), 'Cash Flow runs');

    $bal = assertLedgerBalanced($tPdo, $today);
    ok($bal['ledger_balanced'] === true, 'a brand-new tenant\'s ledger is balanced');
    ok($bal['bs_balanced'] === true, 'and its balance sheet balances');

    // ────────────────────────────────────────────────────────────────────────
    section('7. Every major module\'s core read path executes inside the tenant');
    // A SELECT that parses and runs proves the table AND the columns behind it
    // exist. This is the cheapest possible stand-in for opening every page.
    foreach (MODULE_TABLES as $module => $tables) {
        $broken = [];
        foreach ($tables as $tbl) {
            try { $tPdo->query("SELECT * FROM `$tbl` LIMIT 1")->fetchAll(); }
            catch (Throwable $e) { $broken[] = $tbl; }
        }
        ok($broken === [], "$module reads cleanly (" . count($tables) . ' tables)'
            . ($broken ? ' — FAILED: ' . implode(', ', $broken) : ''));
    }

    // ────────────────────────────────────────────────────────────────────────
    section('8. A real GL round-trip inside the tenant');
    // Not just "the tables exist" — money actually posts and reaches the
    // statements, in a database that was created minutes ago.
    $ids = $tPdo->query('SELECT account_id FROM accounts ORDER BY account_id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    ok(count($ids) === 2, 'two seeded accounts available to post between');

    if (count($ids) === 2) {
        $tPdo->prepare("INSERT INTO journal_entries (entry_date, description, status) VALUES (?, ?, 'posted')")
             ->execute([$today, 'phase 10 smoke ' . $sfx]);
        $eid = (int)$tPdo->lastInsertId();
        $ins = $tPdo->prepare('INSERT INTO journal_entry_items (entry_id, account_id, type, amount) VALUES (?,?,?,?)');
        $ins->execute([$eid, (int)$ids[0], 'debit',  2500.00]);
        $ins->execute([$eid, (int)$ids[1], 'credit', 2500.00]);
        ok($eid > 0, 'a balanced journal entry posts');

        $after = assertLedgerBalanced($tPdo, $today);
        ok($after['sum_debit'] >= 2500.0, "the posting reaches the ledger (Dr {$after['sum_debit']})");
        ok($after['ledger_balanced'] === true, 'the ledger still balances after posting');

        $tb2 = glTrialBalance($tPdo, $today);
        ok(is_array($tb2), 'the Trial Balance still runs with real data in it');

        // The guardrail from .claude/reporting-source.md, per tenant.
        $bs2 = glBalanceSheet($tPdo, $today);
        ok($bs2['balanced'] === true, 'the Balance Sheet still balances after a real posting');
    }

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
}
