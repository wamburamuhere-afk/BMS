<?php
/**
 * Service prints — Quotation / Sales Order / Invoice (all 12 print designs)
 *   php tests/test_service_print_columns_cli.php
 *
 * A document created with the "Service" switch on holds only service lines
 * (the item picker then lists is_service = 1 products only). Its print drops
 * the Product Code column and splits Item / Description into Service Name +
 * Description, the description coming from the service as defined in
 * services.php (products.description), falling back to the line's own text.
 * Any document with a product line prints exactly as before.
 *
 * Also checks the Sales menu now lists Invoices after POS (same link and
 * permission as the Finance menu entry, which stays).
 *
 * The live section creates its own fixtures (three products, one header copy
 * per document per case) and deletes them on exit.
 */

$root = dirname(__DIR__);
$pdo  = null;
// Static sections need no database; the live section runs only where a config exists (not in CI).
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

$templates = [
    'quotation'   => ['app/bms/sales/quotations/print_quotation.php', 'app/bms/sales/quotations/print_quotation_meadow.php',
                      'app/bms/sales/quotations/print_quotation_noir.php', 'app/bms/sales/quotations/print_quotation_terra.php'],
    'sales_order' => ['app/bms/sales/print_sales_order.php', 'app/bms/sales/print_sales_order_confirmation.php',
                      'app/bms/sales/print_sales_order_ledger.php', 'app/bms/sales/print_sales_order_studio.php'],
    'invoice'     => ['app/bms/invoice/invoice_print.php', 'app/bms/invoice/invoice_print_onyx.php',
                      'app/bms/invoice/invoice_print_summit.php', 'app/bms/invoice/invoice_print_wave.php'],
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Every print design: lint + both layouts present');
foreach ($templates as $files) {
    foreach ($files as $f) {
        $rc = 0; $o = [];
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
        check($rc === 0, "$f lint-clean");
        $s = file_get_contents("$root/$f");
        check(str_contains($s, 'p.is_service AS svc_is_service, p.description AS svc_description'), "$f reads the service flag and its registered description");
        check(str_contains($s, "\$svcLayout = !empty(\$items) && !array_filter(\$items, fn(\$r) => (int)(\$r['svc_is_service'] ?? 0) !== 1);"), "$f switches layout only when every line is a service");
        check(substr_count($s, '<?php if ($svcLayout)') === 2, "$f has the service branch in both header and rows");
        check(str_contains($s, "htmlspecialchars(\$item['sku'])"), "$f keeps the original product row");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Sales menu lists Invoices after POS; Finance entry unchanged');
$hdr = file_get_contents("$root/header.php");
$salesStart = strpos($hdr, 'aria-labelledby="salesDropdown"');
$salesEnd   = $salesStart !== false ? strpos($hdr, '</ul>', $salesStart) : false;
$sales      = ($salesStart !== false && $salesEnd !== false) ? substr($hdr, $salesStart, $salesEnd - $salesStart) : '';
$posAt = strpos($sales, "getUrl('pos')");
$invAt = strpos($sales, "getUrl('invoices')");
$retAt = strpos($sales, "t('Returns')");
check($invAt !== false, 'Sales menu has an Invoices link');
check($posAt !== false && $invAt !== false && $retAt !== false && $posAt < $invAt && $invAt < $retAt, 'Invoices sits right after POS, before the Returns group');
check((bool)preg_match("/<\?php if\(canView\('invoices'\)\): \?>(\s*<\?php[^?]*\?>)*\s*<li><a class=\"dropdown-item\"[^>]*href=\"<\?= getUrl\('invoices'\) \?>\"/", $sales), 'Sales-menu Invoices uses the same canView(\'invoices\') gate and link (now a data-nav-companion)');
check(substr_count($hdr, "href=\"<?= getUrl('invoices') ?>\"") === 2, 'Finance > Sales & Purchases still has its Invoices link');

// ─────────────────────────────────────────────────────────────────────────
// Live renders
// ─────────────────────────────────────────────────────────────────────────
if (!($pdo instanceof PDO)) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no database configured)\n";
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

$tag = 'ZZSVCPRT' . strtoupper(bin2hex(random_bytes(3)));
$created = ['products' => [], 'quotations' => [], 'sales_orders' => [], 'invoices' => []];
register_shutdown_function(function () use (&$created, $pdo) {
    foreach ($created['quotations'] as $id)   { $pdo->prepare("DELETE FROM quotation_items WHERE order_id = ?")->execute([$id]);   $pdo->prepare("DELETE FROM quotations WHERE sales_order_id = ?")->execute([$id]); }
    foreach ($created['sales_orders'] as $id) { $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$id]); $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id = ?")->execute([$id]); }
    foreach ($created['invoices'] as $id)     { $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);   $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$id]); }
    foreach ($created['products'] as $id)     { $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]); }
});

$longWord = str_repeat('W', 140);
$svcDesc  = "Fitting and testing of CCTV <b>cameras</b>\nSecond line $longWord";
$pdo->prepare("INSERT INTO products (product_name, product_code, sku, description, is_service, status) VALUES (?, ?, NULL, ?, 1, 'active')")
    ->execute(["$tag Installation Service", "$tag-SVC", $svcDesc]);
$svcId = (int)$pdo->lastInsertId(); $created['products'][] = $svcId;
$pdo->prepare("INSERT INTO products (product_name, product_code, sku, description, is_service, status) VALUES (?, ?, NULL, '', 1, 'active')")
    ->execute(["$tag Bare Service", "$tag-SVC2"]);
$svc2Id = (int)$pdo->lastInsertId(); $created['products'][] = $svc2Id;
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

$docs = [];
foreach (array_keys($cfg) as $type) {
    $docs[$type] = [
        'service' => makeDoc($type, 'S', [[$svcId, "$tag Installation Service", 'line text not used'], [$svc2Id, "$tag Bare Service", 'Fallback line description']]),
        'product' => makeDoc($type, 'P', [[$gdsId, "$tag Cement Bag", 'goods line note']]),
        'mixed'   => makeDoc($type, 'M', [[$svcId, "$tag Installation Service", ''], [$gdsId, "$tag Cement Bag", '']]),
    ];
}

$tmp = tempnam(sys_get_temp_dir(), 'svcprt_') . '.php';
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

$escDesc = htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8');
foreach ($templates as $type => $files) {
    foreach ($files as $f) {
        section('3. ' . basename($f));

        $html = render($f, $docs[$type]['service']);
        $w = warnings($html);
        check($w === '', 'service doc renders without PHP errors' . ($w ? " — $w" : ''));
        check(str_contains($html, '>Service Name</th>'), 'service doc: has a Service Name column');
        check(!str_contains($html, '>Product Code</th>') && !str_contains($html, '>Code</th>') && !str_contains($html, 'Item / Description'), 'service doc: no Product Code, no Item / Description');
        check(str_contains($html, '>Description</th>'), 'service doc: has a Description column');
        check(str_contains($html, $escDesc), 'service doc: description is the one defined in services.php, HTML-escaped');
        check(!str_contains($html, 'line text not used'), 'service doc: registered description wins over the line text');
        check(str_contains($html, 'Fallback line description'), 'service doc: a service with no registered description falls back to the line text');
        check(substr_count($html, 'overflow-wrap:anywhere;word-break:break-word;') >= 4, 'service doc: name and description cells wrap inside their box');
        check(str_contains($html, '200,000.00'), 'service doc: qty / price / total columns still print');

        $html = render($f, $docs[$type]['product']);
        $w = warnings($html);
        check($w === '', 'product doc renders without PHP errors' . ($w ? " — $w" : ''));
        check(str_contains($html, '>Product Code</th>') || str_contains($html, '>Code</th>'), 'product doc: Product Code column unchanged');
        check(str_contains($html, "$tag-SKU"), 'product doc: shows the product code');
        check(!str_contains($html, 'Service Name'), 'product doc: no Service Name column');
        check(str_contains($html, 'goods line note'), 'product doc: line description printed as before');

        $html = render($f, $docs[$type]['mixed']);
        check(!str_contains($html, 'Service Name') && (str_contains($html, '>Product Code</th>') || str_contains($html, '>Code</th>')), 'mixed doc: keeps the normal product layout');
    }
}

finish();
