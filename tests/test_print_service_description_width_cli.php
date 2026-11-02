<?php
/**
 * Default print templates — service Description column width
 *   php tests/test_print_service_description_width_cli.php
 *
 * Default Quotation / Sales Order / Invoice print designs only. In the
 * service layout the Description column was squeezed to ~175px (Service Name
 * 30% + fixed Qty/Price/Total), so long descriptions wrapped to dozens of
 * lines and pushed the totals onto a 2nd page. Now: Service Name 20%, Qty 70px,
 * Unit Price 100px, and the description cell is 11px / 1.35 line-height.
 * The product layout and every other print design are untouched.
 *
 * The live section creates its own fixtures and deletes them on exit.
 */

$root = dirname(__DIR__);
$pdo  = null;
if (is_file("$root/includes/config.php")) {
    try { require_once "$root/roots.php"; } catch (Throwable $e) { $pdo = null; }
}

$passes = 0; $failures = 0;
function check(bool $ok, string $m): void {
    global $passes, $failures;
    if ($ok) { $passes++; echo "  \033[32m✅\033[0m $m\n"; } else { $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
}
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function finish(): void {
    global $passes, $failures;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    exit($failures > 0 ? 1 : 0);
}

$defaults = [
    'quotation'   => 'app/bms/sales/quotations/print_quotation.php',
    'sales_order' => 'app/bms/sales/print_sales_order.php',
    'invoice'     => 'app/bms/invoice/invoice_print.php',
];
$others = [
    'app/bms/sales/quotations/print_quotation_meadow.php', 'app/bms/sales/quotations/print_quotation_noir.php',
    'app/bms/sales/quotations/print_quotation_terra.php',  'app/bms/sales/print_sales_order_confirmation.php',
    'app/bms/sales/print_sales_order_ledger.php',          'app/bms/sales/print_sales_order_studio.php',
    'app/bms/invoice/invoice_print_onyx.php',              'app/bms/invoice/invoice_print_summit.php',
    'app/bms/invoice/invoice_print_wave.php',
];

section('1. Default templates — static source');
foreach ($defaults as $f) {
    $s = file_get_contents("$root/$f");
    check(str_contains($s, '<th class="text-center" style="width:20%;">Service Name</th>'), "$f: Service Name is 20%");
    check(!str_contains($s, 'style="width:30%;">Service Name'), "$f: old 30% Service Name width is gone");
    check(str_contains($s, '<?= $svcLayout ? 70 : 80 ?>px;">Qty'), "$f: Qty 70px for services, 80px for products");
    check(str_contains($s, '<?= $svcLayout ? 100 : 105 ?>px;">Unit Price'), "$f: Unit Price 100px for services, 105px for products");
    check(str_contains($s, 'tbody tr td.svc-desc { font-size: 11px; line-height: 1.35; }'), "$f: compact description CSS present");
    check(substr_count($s, 'class="svc-desc"') === 1, "$f: only the service description cell uses it");
    check(str_contains($s, 'width:105px;">Unit Price') === false && str_contains($s, 'style="width:115px;">Total'), "$f: Total column width unchanged");
    check(str_contains($s, 'bmsAutoFitPrint'), "$f: shared auto-fit still wired");
}

section('2. Other print designs untouched');
foreach ($others as $f) {
    $s = file_get_contents("$root/$f");
    check(!str_contains($s, 'svc-desc'), "$f: no svc-desc change");
    check(!str_contains($s, '<?= $svcLayout ? 70 : 80 ?>'), "$f: widths unchanged");
}

if (!$pdo) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no database config)\n";
    finish();
}
$uid = 0;
try { $uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
$srcHdr = [];
try {
    $srcHdr['quotation']   = $pdo->query("SELECT * FROM quotations ORDER BY sales_order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $srcHdr['sales_order'] = $pdo->query("SELECT * FROM sales_orders ORDER BY sales_order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $srcHdr['invoice']     = $pdo->query("SELECT * FROM invoices ORDER BY invoice_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$uid || in_array(false, $srcHdr, true) || count($srcHdr) < 3) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (needs an admin user and one quotation, sales order and invoice)\n";
    finish();
}

$tag = 'ZZSVCW' . strtoupper(bin2hex(random_bytes(3)));
$created = ['products' => [], 'quotations' => [], 'sales_orders' => [], 'invoices' => []];
register_shutdown_function(function () use (&$created, $pdo) {
    foreach ($created['quotations'] as $id)   { $pdo->prepare("DELETE FROM quotation_items WHERE order_id = ?")->execute([$id]);   $pdo->prepare("DELETE FROM quotations WHERE sales_order_id = ?")->execute([$id]); }
    foreach ($created['sales_orders'] as $id) { $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$id]); $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id = ?")->execute([$id]); }
    foreach ($created['invoices'] as $id)     { $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);   $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$id]); }
    foreach ($created['products'] as $id)     { $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]); }
});

$longDesc = str_repeat("Supply, install, configure and test the full CCTV system including cabling and a one year warranty. ", 3);
$pdo->prepare("INSERT INTO products (product_name, product_code, sku, description, is_service, status) VALUES (?, ?, NULL, ?, 1, 'active')")
    ->execute(["$tag Installation Service", "$tag-SVC", $longDesc]);
$svcId = (int)$pdo->lastInsertId(); $created['products'][] = $svcId;
$pdo->prepare("INSERT INTO products (product_name, product_code, sku, description, is_service, status) VALUES (?, ?, ?, 'goods text', 0, 'active')")
    ->execute(["$tag Cement Bag", "$tag-GDS", "$tag-SKU"]);
$gdsId = (int)$pdo->lastInsertId(); $created['products'][] = $gdsId;

$cfg = [
    'quotation'   => ['hdr' => 'quotations',   'pk' => 'sales_order_id', 'num' => 'order_number',   'items' => 'quotation_items',   'fk' => 'order_id'],
    'sales_order' => ['hdr' => 'sales_orders', 'pk' => 'sales_order_id', 'num' => 'order_number',   'items' => 'sales_order_items', 'fk' => 'order_id'],
    'invoice'     => ['hdr' => 'invoices',     'pk' => 'invoice_id',     'num' => 'invoice_number', 'items' => 'invoice_items',     'fk' => 'invoice_id'],
];
function makeDoc(string $type, string $case, array $lines): int {
    global $pdo, $cfg, $srcHdr, $created, $tag;
    $c = $cfg[$type];
    $row = $srcHdr[$type];
    unset($row[$c['pk']]);
    $row[$c['num']] = "$tag-" . strtoupper(substr($type, 0, 2)) . "-$case";
    $cols = array_keys($row);
    $pdo->prepare("INSERT INTO {$c['hdr']} (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
        ->execute(array_values($row));
    $id = (int)$pdo->lastInsertId();
    $created[$c['hdr']][] = $id;
    foreach ($lines as [$pid, $name, $lineDesc]) {
        $pdo->prepare("INSERT INTO {$c['items']} ({$c['fk']}, product_id, product_name, description, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, 1, 200000, 200000)")
            ->execute([$id, $pid, $name, $lineDesc]);
    }
    return $id;
}

$tmp = tempnam(sys_get_temp_dir(), 'svcw_') . '.php';
register_shutdown_function(fn() => @unlink($tmp));
function render(string $file, int $id): string {
    global $root, $uid, $tmp;
    file_put_contents($tmp, "<?php
        chdir(" . var_export($root, true) . ");
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_GET['id'] = $id;
        require 'roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        include " . var_export("$root/$file", true) . ";
    ");
    return (string)shell_exec('php -d display_errors=1 -d html_errors=0 ' . escapeshellarg($tmp) . ' 2>&1');
}
function warnings(string $html): string {
    preg_match_all('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)\s*:?[^\n]{0,180}/', $html, $m);
    return implode(' | ', array_unique(array_filter($m[0], fn($w) => !str_contains($w, 'HTTP_HOST') && !str_contains($w, 'session') && !str_contains($w, 'headers already sent'))));
}

foreach ($defaults as $type => $f) {
    section("3. Live render — $f");
    $svcDoc = makeDoc($type, 'S', [[$svcId, "$tag Installation Service", ''], [$svcId, "$tag Installation Service", $longDesc]]);
    $gdsDoc = makeDoc($type, 'P', [[$gdsId, "$tag Cement Bag", 'goods line note']]);

    $html = render($f, $svcDoc);
    $w = warnings($html);
    check($w === '', 'service doc renders without PHP errors' . ($w ? " — $w" : ''));
    check(str_contains($html, 'style="width:20%;">Service Name'), 'service doc: Service Name header is 20%');
    check(str_contains($html, 'style="width:70px;">Qty') && str_contains($html, 'style="width:100px;">Unit Price'), 'service doc: Qty 70px / Unit Price 100px');
    check(substr_count($html, '<td class="svc-desc"') === 2, 'service doc: every description cell carries the compact class');
    check(substr_count($html, trim($longDesc)) >= 2, 'service doc: full description text still printed (nothing truncated)');
    check(str_contains($html, '200,000.00'), 'service doc: amounts still print');
    check(str_contains($html, 'bmsAutoFitPrint()'), 'service doc: auto-fit still runs on load');

    $html = render($f, $gdsDoc);
    $w = warnings($html);
    check($w === '', 'product doc renders without PHP errors' . ($w ? " — $w" : ''));
    check(str_contains($html, 'style="width:80px;">Qty') && str_contains($html, 'style="width:105px;">Unit Price'), 'product doc: Qty 80px / Unit Price 105px unchanged');
    check(!str_contains($html, 'class="svc-desc"') && !str_contains($html, 'Service Name'), 'product doc: no service styling, no Service Name column');
    check(str_contains($html, 'goods line note'), 'product doc: description printed as before');
}

finish();
