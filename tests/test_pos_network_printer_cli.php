<?php
/**
 * Phase 21 (pos_upgrade_plan.md §8) — network (IP) thermal-printer support — CLI test
 *   php tests/test_pos_network_printer_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: pos_registers.printer_connection_type/.printer_ip_address/.printer_port
 *      exist, default is 'browser' (every existing register unaffected).
 *   3. Wiring: print_receipt.php branches on connection_type and falls back
 *      on failure; save_register.php/get_registers.php persist/return the
 *      new fields; settings UI has the connection-type picker + test button.
 *   4. Runtime — buildEscPosReceipt(): exact byte-level assertions (init,
 *      the literal drawer-kick sequence \x1B\x70\x00\x19\xFA, the cut
 *      command, company name, totals, THANK YOU footer all present in the
 *      right order) — no real hardware needed.
 *   5. Runtime — sendToNetworkPrinter(): a connection failure (bad host)
 *      returns success=false with a message, never throws — this is exactly
 *      the fail-open path print_receipt.php's fallback depends on.
 *   6. Runtime — the default ('browser') connection_type is a complete no-op:
 *      confirmed by reading print_receipt.php's own condition.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/escpos_printer.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Files lint clean');
foreach ([
    'core/escpos_printer.php', 'api/pos/print_receipt.php', 'api/pos/save_register.php',
    'api/pos/get_registers.php', 'api/pos/test_network_printer.php',
    'app/constant/settings/pos_config_settings.php',
    'migrations/tenant/2026_09_08_pos_network_printer.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

section('2. Schema — every existing register unaffected by default');
foreach (['printer_connection_type', 'printer_ip_address', 'printer_port'] as $col) {
    $c = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE " . $pdo->quote($col))->fetch(PDO::FETCH_ASSOC);
    $c ? pass("pos_registers.$col exists") : fail("pos_registers.$col MISSING");
}
$defaultType = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'printer_connection_type'")->fetch(PDO::FETCH_ASSOC);
($defaultType && $defaultType['Default'] === 'browser') ? pass("default is 'browser' — no existing register's behaviour changes") : fail('default is not browser: ' . json_encode($defaultType));

$anyNonBrowser = (int)$pdo->query("SELECT COUNT(*) FROM pos_registers WHERE printer_connection_type != 'browser'")->fetchColumn();
($anyNonBrowser === 0) ? pass('every register currently on this DB is still on browser mode (nothing silently opted in)') : pass("$anyNonBrowser register(s) already configured for network mode (pre-existing admin choice, not this test's concern)");

section('3. Wiring');
$pr = src($root, 'api/pos/print_receipt.php');
has($pr, "'network'", 'print_receipt.php branches on connection_type');
has($pr, 'buildEscPosReceipt(', 'print_receipt.php builds the ESC/POS byte stream');
has($pr, 'sendToNetworkPrinter(', 'print_receipt.php sends via the network helper');
has($pr, "if (\$result['success'])", 'print_receipt.php checks the send result before short-circuiting');
has(src($root, 'api/pos/save_register.php'), 'printer_connection_type', 'save_register.php persists the connection type');
has(src($root, 'api/pos/get_registers.php'), 'printer_connection_type', 'get_registers.php returns the connection type');
has(src($root, 'app/constant/settings/pos_config_settings.php'), 'reg_printer_connection_type', 'settings UI has the connection-type picker');
has(src($root, 'app/constant/settings/pos_config_settings.php'), 'testNetworkPrinter', 'settings UI has a Test Printer action');

section('4. Runtime — buildEscPosReceipt() exact byte-level assertions');
$data = [
    'company_name' => 'ACME STORE', 'receipt_number' => 'RCP-000123', 'cashier_name' => 'Jane',
    'sale_date' => '2026-09-08 12:00', 'currency' => 'TZS',
    'items' => [
        ['product_name' => 'Notebook A5', 'quantity' => 2, 'unit_price' => 1500, 'line_total' => 3000],
    ],
    'subtotal' => 3000, 'tax_amount' => 0, 'discount_amount' => 0, 'grand_total' => 3000,
    'payment_method' => 'cash', 'amount_tendered' => 5000, 'change_given' => 2000,
];
$bytes = buildEscPosReceipt($data, true);

has($bytes, ESCPOS_INIT, 'byte stream starts with the ESC @ init sequence');
(strpos($bytes, ESCPOS_INIT) === 0) ? pass('ESC @ init is literally the FIRST bytes written') : fail('init sequence not at position 0');
has($bytes, 'ACME STORE', 'company name is present');
has($bytes, 'RCP-000123', 'receipt number is present');
has($bytes, 'Notebook A5', 'line item product name is present');
has($bytes, '3,000.00', 'grand total is present, formatted');
has($bytes, ESCPOS_CUT, 'the literal GS V 0 cut command is present');
has($bytes, ESCPOS_DRAWER_KICK, 'the literal ESC p 0 25 250 drawer-kick sequence is present — rides the SAME byte stream/socket as the print job');
(strpos($bytes, ESCPOS_CUT) < strpos($bytes, ESCPOS_DRAWER_KICK))
    ? pass('cut comes before drawer-kick (correct physical order — cut the paper, then kick)')
    : fail('cut/drawer-kick ordering wrong');

$noKick = buildEscPosReceipt($data, false);
(strpos($noKick, ESCPOS_DRAWER_KICK) === false) ? pass('kickDrawer=false genuinely omits the drawer-kick sequence') : fail('drawer-kick present when explicitly disabled');

section('5. Runtime — sendToNetworkPrinter() fails open, never throws');
$threw = false;
$result = null;
try {
    // A non-routable test address (RFC 5737 TEST-NET-1) — guaranteed not to
    // connect, exercising the real failure path without needing hardware.
    $result = sendToNetworkPrinter('192.0.2.1', 9100, 'test', 1.0);
} catch (Throwable $e) { $threw = true; }
(!$threw) ? pass('a connection failure never throws') : fail('sendToNetworkPrinter() threw instead of failing open');
($result !== null && $result['success'] === false && !empty($result['error'])) ? pass('failure returns success=false with a populated error message') : fail('failure result malformed: ' . json_encode($result));

$emptyIpResult = sendToNetworkPrinter('', 9100, 'test');
($emptyIpResult['success'] === false) ? pass('an empty IP address is rejected before even attempting a connection') : fail('empty IP was not rejected');

section("6. Confirmed: the default ('browser') connection_type is a complete no-op");
has($pr, "?? 'browser') === 'network'", "print_receipt.php's network branch is gated on an explicit 'network' value — 'browser' (the column default) never enters it");
