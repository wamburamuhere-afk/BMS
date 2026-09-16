<?php
/**
 * tests/test_pos_credit_receivables_cli.php
 *
 * Regression cover for the POS credit-sale receivables feature
 * (pos_credit_receivables_plan.md, Phases 0-4): due-date capture at sale
 * time, the shared aging/counters helper (core/pos_credit_aging.php), the
 * dedicated "Who Owes Me" page, the customer detail "Madeni" tab, the
 * dashboard.php "Credit" card, and the due-date reminder notifications —
 * all gated to Simple POS tenants only.
 *
 * Run: php tests/test_pos_credit_receivables_cli.php
 * Self-skips the HTTP sections if the local server is not reachable
 * (set BMS_TEST_URL to override the default http://dev.bms.local).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_nav.php";
require_once "$root/core/pos_credit_limit.php";
require_once "$root/core/pos_credit_aging.php";
require_once "$root/core/notify.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)    { echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p)     { return is_file($p) ? file_get_contents($p) : ''; }

$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$saleIds = [];

function cleanupCreditTestData(PDO $pdo, array $saleIds) {
    foreach ($saleIds as $sid) {
        $pdo->prepare("DELETE FROM notification_dedupe WHERE dedupe_key LIKE ?")->execute(['%|pos_sale|' . $sid . '|%']);
        $pdo->prepare("DELETE FROM notification_outbox WHERE entity_type = 'pos_sale' AND entity_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM pos_sale_payments WHERE sale_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM pos_sales WHERE sale_id = ?")->execute([$sid]);
    }
    $pdo->exec("DELETE FROM notifications WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue') AND message LIKE '%CRTEST-%'");
}

register_shutdown_function(function () use ($pdo, &$saleIds) {
    cleanupCreditTestData($pdo, $saleIds);
});

try {
    // ── 1. Source-level wiring across every phase ───────────────────────
    section('1. Source — Phase 0/1: schema + due-date capture at sale time');
    ok(is_file("$root/migrations/tenant/2026_09_16_pos_sales_due_date.php"), 'tenant migration for pos_sales.due_date exists');
    ok(is_file("$root/migrations/2026_09_16_pos_sales_due_date_legacy_db.php"), 'paired legacy migration exists');
    $hasDueDateCol = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'due_date'")->fetch();
    ok((bool)$hasDueDateCol, 'pos_sales.due_date column is present on this DB');

    $posJs = src("$root/app/bms/pos/pos_scripts_new.php");
    ok(str_contains($posJs, 'POS_SIMPLE_MODE') && str_contains($posJs, "paymentMethod === 'credit'") && str_contains($posJs, 'creditDueDateTitle'),
       'pos_scripts_new.php shows the due-date popup only for Simple POS credit sales with a customer chosen');

    $processSaleSrc = src("$root/api/pos/process_sale.php");
    ok(str_contains($processSaleSrc, "\$input['due_date']") && str_contains($processSaleSrc, 'due_date'),
       'process_sale.php accepts and stores due_date');

    section('2. Source — Phase 2: shared helper + APIs + dedicated page');
    ok(function_exists('posCreditOpenSales'), 'posCreditOpenSales() defined');
    ok(function_exists('posCreditCustomerCounters'), 'posCreditCustomerCounters() defined');
    ok(function_exists('posCreditTotalOutstanding'), 'posCreditTotalOutstanding() defined');
    foreach (['api/pos/get_credit_aging.php', 'api/pos/get_credit_sale_detail.php', 'api/pos/update_credit_due_date.php'] as $f) {
        $s = src("$root/$f");
        ok(is_file("$root/$f") && str_contains($s, 'posSimpleModeEnabled()'), "$f exists and is Simple-POS gated");
    }
    ok(is_file("$root/app/bms/pos/pos_credit_customers.php"), 'dedicated "Who Owes Me" page exists');
    $pageSrc = src("$root/app/bms/pos/pos_credit_customers.php");
    ok(str_contains($pageSrc, "posSimpleModeEnabled()"), 'the page redirects away when Simple POS is off');
    $navSrc = src("$root/core/pos_nav.php");
    ok(str_contains($navSrc, "'credit_customers'") && str_contains($navSrc, "posSimpleModeEnabled() && canView('pos')"),
       'posNavGroups() only includes the "Who Owes Me" hub card under Simple POS');
    $dashPosSrc = src("$root/app/bms/pos/pos_dashboard.php");
    ok(str_contains($dashPosSrc, "'credit_customers'  => 'bi-cash-coin'"), 'pos_dashboard.php maps an icon for the credit_customers card');

    section('3. Source — Phase 2b: Madeni tab on customer_details.php');
    $custSrc = src("$root/app/bms/customer/customer_details.php");
    ok(str_contains($custSrc, '$show_credit_tab = posSimpleModeEnabled()'), 'Madeni tab is gated by posSimpleModeEnabled()');
    ok(str_contains($custSrc, 'pane-madeni') && str_contains($custSrc, 'posCreditCustomerCounters'), 'Madeni pane renders the shared counters');

    section('4. Source — Phase 3: dashboard.php Credit card');
    $dashSrc = src("$root/app/dashboard.php");
    ok(str_contains($dashSrc, '$pos_credit_total') && str_contains($dashSrc, 'posCreditTotalOutstanding'), 'dashboard.php computes the Credit card total from the shared helper');
    ok(str_contains($dashSrc, "if (\$pos_simple_mode && canView('pos')):") ,'dashboard.php Credit card is gated by Simple POS mode');

    section('5. Source — Phase 4: reminder notifications');
    $cronSrc = src("$root/cron/run_notification_checks.php");
    ok(str_contains($cronSrc, "'pos_credit.due_soon'") && str_contains($cronSrc, "'pos_credit.overdue'"),
       'run_notification_checks.php dispatches pos_credit.due_soon / pos_credit.overdue');
    ok(str_contains($cronSrc, 'posSimpleModeEnabled()'), 'the reminder block is gated to Simple POS tenants');
    ok(is_file("$root/migrations/tenant/2026_09_16_pos_credit_due_notifications.php"), 'tenant migration seeding the two notification_events exists');
    ok(is_file("$root/migrations/2026_09_16_pos_credit_due_notifications_legacy_db.php"), 'paired legacy migration exists');
    $evRow = $pdo->prepare("SELECT COUNT(*) FROM notification_events WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue')");
    $evRow->execute();
    ok((int)$evRow->fetchColumn() === 2, 'both notification_events rows are seeded on this DB');

    // ── 6. Live DB math: posCreditOpenSales / counters / total ──────────
    section('6. Live — shared helper math against manufactured data');

    $custA = (int)$pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn();
    ok($custA > 0, "found a real customer to test against (customer_id=$custA)");

    $insSale = $pdo->prepare("
        INSERT INTO pos_sales (customer_id, customer_name, warehouse_id, receipt_number, grand_total,
            payment_method, payment_status, sale_status, sale_date, due_date, is_return_sale, user_id, created_at)
        VALUES (?, 'CR Test Customer', NULL, ?, ?, 'credit', 'pending', 'completed', NOW(), ?, 0, 1, NOW())
    ");
    $insSale->execute([$custA, 'CRTEST-OPEN-' . time(), 12000, date('Y-m-d', strtotime('-3 days'))]);
    $saleA = (int)$pdo->lastInsertId();
    $saleIds[] = $saleA;

    $open = posCreditOpenSales($pdo, $custA);
    $row = null;
    foreach ($open as $r) { if ((int)$r['sale_id'] === $saleA) { $row = $r; break; } }
    ok($row !== null, 'posCreditOpenSales() returns the newly inserted open credit sale');
    if ($row) {
        ok(abs($row['balance_due'] - 12000) < 0.01, "balance_due is 12000 (got {$row['balance_due']})");
        ok($row['is_overdue'] === true && $row['days_overdue'] === 3, "3-days-ago due date reports overdue=true, days_overdue=3 (got days_overdue={$row['days_overdue']})");
    }

    $total = posCreditTotalOutstanding($pdo);
    ok($total >= 12000, "posCreditTotalOutstanding() includes the test sale (total=$total)");

    // Settle it fully, on-time relative to a due date we set in the past —
    // i.e. simulate a LATE repayment (paid today, due 3 days ago).
    $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_method, notes, received_by, created_at) VALUES (?, 12000, 'cash', 'test', 1, NOW())")
        ->execute([$saleA]);
    $pdo->prepare("UPDATE pos_sales SET payment_status = 'paid' WHERE sale_id = ?")->execute([$saleA]);

    $counters = posCreditCustomerCounters($pdo, $custA);
    ok($counters['times_borrowed'] >= 1, "times_borrowed counts the test sale ({$counters['times_borrowed']})");
    ok($counters['times_repaid_late'] >= 1, "a payment made after due_date counts as late ({$counters['times_repaid_late']} late)");

    $stillOpen = posCreditOpenSales($pdo, $custA);
    $stillThere = false;
    foreach ($stillOpen as $r) { if ((int)$r['sale_id'] === $saleA) $stillThere = true; }
    ok(!$stillThere, 'a fully-paid sale no longer appears in posCreditOpenSales()');

    // ── 7. Live: reminder milestone logic ────────────────────────────────
    section('7. Live — reminder milestones (3-before / due-today / overdue / weekly re-fire)');

    if (!function_exists('run_notification_checks')) {
        // run_notification_checks.php's top-level "run now" block only fires
        // when included directly (not via require in another script's function
        // scope), so pull in just the function definition safely.
        include "$root/cron/run_notification_checks.php";
    }

    $custB = (int)$pdo->query("SELECT customer_id FROM customers ORDER BY customer_id DESC LIMIT 1")->fetchColumn();
    $insSale->execute([$custB, 'CRTEST-DUESOON-' . time(), 7000, date('Y-m-d', strtotime('+3 days'))]);
    $saleDueSoon = (int)$pdo->lastInsertId();
    $saleIds[] = $saleDueSoon;

    $insSale->execute([$custB, 'CRTEST-OVERDUE1-' . time(), 3000, date('Y-m-d', strtotime('-1 day'))]);
    $saleOverdue1 = (int)$pdo->lastInsertId();
    $saleIds[] = $saleOverdue1;

    $wasSimple = posSimpleModeEnabled();
    if (!$wasSimple) { save_setting('pos_simple_mode', '1'); }

    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue')")->fetchColumn();
    run_notification_checks($pdo);
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue')")->fetchColumn();
    ok($countAfter > $countBefore, "reminder run created notifications ($countBefore -> $countAfter)");

    $dueSoonMsg = $pdo->query("SELECT message FROM notifications WHERE event_key = 'pos_credit.due_soon' ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    ok($dueSoonMsg && str_contains($dueSoonMsg, 'due in 3 day'), 'due-in-3-days sale produced the expected "due in 3 day(s)" message');

    $overdueMsg = $pdo->query("SELECT message FROM notifications WHERE event_key = 'pos_credit.overdue' ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    ok($overdueMsg && str_contains($overdueMsg, 'overdue by 1 day'), 'day-1-overdue sale produced the expected "overdue by 1 day(s)" message');

    // Same-day re-run must not double-send (dedupe_suffix = today).
    $countRerun = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue')")->fetchColumn();
    run_notification_checks($pdo);
    $countRerunAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key IN ('pos_credit.due_soon','pos_credit.overdue')")->fetchColumn();
    ok($countRerun === $countRerunAfter, 'a second same-day run does not create duplicate notifications');

    // Day-2-overdue must NOT fire; day-8-overdue (weekly bucket) must fire.
    $pdo->prepare("UPDATE pos_sales SET due_date = ? WHERE sale_id = ?")->execute([date('Y-m-d', strtotime('-2 days')), $saleOverdue1]);
    $c2before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'pos_credit.overdue'")->fetchColumn();
    run_notification_checks($pdo);
    $c2after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'pos_credit.overdue'")->fetchColumn();
    ok($c2before === $c2after, 'day-2-overdue does not fire a new reminder');

    $pdo->prepare("DELETE FROM notification_dedupe WHERE dedupe_key LIKE ?")->execute(['pos_credit.overdue|pos_sale|' . $saleOverdue1 . '|' . date('Y-m-d') . '%']);
    $pdo->prepare("UPDATE pos_sales SET due_date = ? WHERE sale_id = ?")->execute([date('Y-m-d', strtotime('-8 days')), $saleOverdue1]);
    $c8before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'pos_credit.overdue'")->fetchColumn();
    run_notification_checks($pdo);
    $c8after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'pos_credit.overdue'")->fetchColumn();
    ok($c8after > $c8before, 'day-8-overdue (weekly re-fire bucket) fires a new reminder');

    if (!$wasSimple) { save_setting('pos_simple_mode', '0'); }

    // ── 8. Local server reachability + live page loads ───────────────────
    section('8. Local server reachability');
    $reachable = false;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;
    ok($reachable, "server reachable at $base");
    if (!$reachable) {
        skip('LIVE HTTP sections skipped — set BMS_TEST_URL to override');
        goto done;
    }

    $adminUid = (int)$pdo->query("
        SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id
        WHERE r.is_admin = 1 LIMIT 1
    ")->fetchColumn();
    ok($adminUid > 0, "found a real admin user (user_id=$adminUid)");

    $probe = "$root/_credit_receivables_test_probe.php";
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    loadUserPermissions(1);
    echo 'ok';
}
PHP);
    register_shutdown_function(function () use ($probe) { if (is_file($probe)) @unlink($probe); });

    $cookieJar = tempnam(sys_get_temp_dir(), 'crtest_cookies_');
    function httpGet($url, $cookieJar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    }

    if (!function_exists('curl_init')) {
        skip('curl extension not available — skipping live page-render checks');
    } else {
        httpGet("$base/_credit_receivables_test_probe.php?act=login&uid=$adminUid", $cookieJar);

        $wasSimple2 = posSimpleModeEnabled();
        save_setting('pos_simple_mode', '1');

        [$code, $body] = httpGet("$base/pos/credit-customers", $cookieJar);
        ok($code === 200, "Who Owes Me page returns HTTP 200 (got $code)");
        ok(str_contains((string)$body, 'creditAgingTable'), 'Who Owes Me page renders the aging table container');
        ok(!preg_match('/Fatal error|Parse error/i', (string)$body), 'Who Owes Me page has no PHP fatal/parse errors');

        [$code2, $body2] = httpGet("$base/pos_dashboard", $cookieJar);
        ok($code2 === 200 && str_contains((string)$body2, 'credit-customers'), 'POS hub shows the "Who Owes Me" card when Simple POS is on');

        [$code3, $body3] = httpGet("$base/dashboard", $cookieJar);
        ok($code3 === 200 && str_contains((string)$body3, 'pos/credit-customers'), 'dashboard.php shows the Credit card link when Simple POS is on');

        $custViewId = $custA;
        [$code4, $body4] = httpGet("$base/customers/view?id=$custViewId", $cookieJar);
        ok($code4 === 200 && str_contains((string)$body4, 'pane-madeni'), 'customer_details.php renders the Madeni tab when Simple POS is on');

        save_setting('pos_simple_mode', '0');
        [$code5, $body5] = httpGet("$base/pos_dashboard", $cookieJar);
        ok($code5 === 200 && !str_contains((string)$body5, 'credit-customers'), 'POS hub hides the "Who Owes Me" card when Simple POS is off');

        [$code6, $body6] = httpGet("$base/customers/view?id=$custViewId", $cookieJar);
        ok($code6 === 200 && !str_contains((string)$body6, 'pane-madeni'), 'customer_details.php hides the Madeni tab when Simple POS is off');

        if ($wasSimple2) { save_setting('pos_simple_mode', '1'); }
        @unlink($cookieJar);
    }

    done:
} catch (Throwable $e) {
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n" . $e->getTraceAsString() . "\n";
    $fail++;
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "Results: \033[32m$pass passed\033[0m, " . ($fail > 0 ? "\033[31m$fail failed\033[0m" : "$fail failed") . "\n";
exit($fail > 0 ? 1 : 0);
