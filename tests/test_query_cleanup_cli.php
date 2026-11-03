<?php
/**
 * Opening a form from the address (?edit=N, ?add=1, ?action=add) — once the
 * page has opened it, the one-time value is removed from the address bar, so a
 * refresh or a shared link does not reopen the form.
 *   php tests/test_query_cleanup_cli.php
 *
 * Static: header.php defines bmsCleanQuery(); each page that opens a form from
 * the address calls it with exactly its own one-time keys, AFTER reading them.
 * Behaviour: the helper's logic is run in Node when Node is installed (removes
 * only the named keys; keeps filters, tabs, hash; no-op when nothing to remove).
 * Exit 0 = pass.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

echo "\n── 1. The helper in header.php ──\n";
$hdr = (string)file_get_contents("$root/header.php");
ok(preg_match('/function bmsCleanQuery\(keys\)\s*\{/', $hdr) === 1, 'bmsCleanQuery(keys) is defined globally');
ok(str_contains($hdr, 'window.history.replaceState(window.history.state'), 'it replaces the address in place (no reload, no new history entry)');
ok(!preg_match('/function bmsCleanQuery[\s\S]{0,700}(location\.href\s*=|location\.assign|location\.reload)/', $hdr), 'it never navigates or reloads');
$posHelper = strpos($hdr, 'function bmsCleanQuery');
$posSwal   = strpos($hdr, 'sweetalert2@11');
ok($posHelper !== false && $posSwal !== false && $posHelper > $posSwal && $posHelper < strpos($hdr, '</head>') + 100000, 'defined in the header, before any page script runs');

echo "\n── 2. Each page cleans exactly its own one-time keys, after reading them ──\n";
$pages = [
    'app/bms/customer/customer_groups.php'   => [["'edit'"],          "editGroup(editId)"],
    'app/bms/customer/customers.php'         => [["'action'"],        "addModal.show()"],
    'app/bms/invoice/received_invoices.php'  => [["'edit'", "'add'", "'pay'"], "deepLinkPay(RI_PAY_ID)"],
    'app/bms/purchase/purchase_returns.php'  => [["'edit'", "'add'"], "editReturn(PR_EDIT_ID)"],
    'app/constant/accounts/expenses.php'     => [["'edit'", "'add'"], "\$exp_edit_id = intval(\$_GET['edit']"],
    'app/bms/product/services.php'           => [["'edit'"],          "openEditSvcModal(editSvcOnLoad)"],
];
foreach ($pages as $f => [$keys, $readMark]) {
    $src = (string)@file_get_contents("$root/$f");
    preg_match_all('/bmsCleanQuery\(\[([^\]]*)\]\)/', $src, $m, PREG_OFFSET_CAPTURE);
    ok(count($m[0]) === 1, "$f calls bmsCleanQuery once");
    if (!$m[0]) continue;
    $got = array_map('trim', explode(',', $m[1][0][0])); sort($got); $want = $keys; sort($want);
    ok($got === $want, "   …with exactly [" . implode(', ', $keys) . "] (got [" . implode(', ', $got) . "])");
    $posRead = strpos($src, $readMark);
    ok($posRead !== false && $posRead < $m[0][0][1], '   …after the page has read/used them');
}
// Pages whose ?edit= is a mode the page itself depends on must NOT be cleaned.
foreach (['app/bms/purchase/purchase_order_create.php', 'app/bms/sales/lpo/lpo_create.php', 'app/bms/purchase/rfq_create.php', 'app/bms/grn/dn_create.php'] as $f)
    ok(!str_contains((string)@file_get_contents("$root/$f"), 'bmsCleanQuery'), "$f keeps ?edit= (the page is in edit mode; saving needs it)");

echo "\n── 3. The helper's behaviour (Node) ──\n";
$node = trim((string)shell_exec((DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')));
if ($node === '') {
    echo "  (Node not installed — behaviour check skipped)\n";
} else {
    preg_match('/function bmsCleanQuery\(keys\)\s*\{[\s\S]*?\r?\n    \}\r?\n/', $hdr, $fm);
    $js = ($fm[0] ?? '') . <<<'JS'
const cases = [
  ['https://x.test/expenses?edit=12', ['edit','add'], '/expenses'],
  ['https://x.test/expenses?edit=12&month=5&tab=paid#list', ['edit','add'], '/expenses?month=5&tab=paid#list'],
  ['https://x.test/customers?action=add&q=juma', ['action'], '/customers?q=juma'],
  ['https://x.test/customers?q=juma', ['action'], null],
];
const out = [];
for (const [href, keys, want] of cases) {
  let replaced = null;
  global.window = { location: { href }, history: { state: null, replaceState: (s, t, u) => { replaced = u; } } };
  bmsCleanQuery(keys);
  out.push(replaced === want ? 'ok' : `bad ${href} -> ${replaced} (want ${want})`);
}
console.log(out.join('\n'));
JS;
    $tmp = tempnam(sys_get_temp_dir(), 'bcq') . '.js';
    file_put_contents($tmp, $js);
    $res = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    $lines = explode("\n", $res);
    ok(count($lines) === 4 && !array_diff($lines, ['ok']), 'removes only the named keys, keeps filters/tabs/#hash, does nothing when there is nothing to remove' . ($res !== str_repeat("ok\n", 3) . 'ok' ? " — $res" : ''));
}

echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
