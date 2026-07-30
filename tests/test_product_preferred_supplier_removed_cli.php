<?php
/**
 * "Preferred Supplier" field removed from product Create/Edit forms
 *   php tests/test_product_preferred_supplier_removed_cli.php
 *
 * User investigated the field and found it purely cosmetic: settable on
 * create/edit, displayed on the product view, and usable as a Products-list
 * filter — but never consulted anywhere in the actual purchasing/payment
 * workflow (Purchase Order creation has its own separate, required
 * supplier field; nothing pre-fills or cross-checks against a product's
 * "preferred" one). Asked to remove it from the create/edit forms.
 *
 * The one real risk in doing that: api/update_product.php builds its
 * UPDATE query dynamically from every key in $product_data, including
 * 'supplier_id' — with the field gone from the form, $_POST['supplier_id']
 * would always be empty, so every future edit-save would have silently
 * reset supplier_id to NULL, wiping any value already stored on existing
 * products. Fixed by removing 'supplier_id' from that array entirely, so
 * the UPDATE never touches the column — existing values are preserved.
 *
 * The products.php quick-add modal's "Preferred Supplier" field was also
 * removed — confirmed it only ever submits to create_product.php (a
 * create-only modal), so no existing-data risk there.
 *
 * Exit 0 = all pass. Exit 1 = a regression slipped in.
 */

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

$root = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");
if ($isLive) {
    require_once "$root/roots.php";
}

echo "\n\033[1m═══ Product forms — \"Preferred Supplier\" field removed, existing data preserved ═══\033[0m\n";

head('Syntax');
foreach ([
    'app/bms/product/product_create.php',
    'app/bms/product/product_edit.php',
    'app/bms/product/products.php',
    'api/update_product.php',
] as $rel) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$root/$rel") . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false) ? ok("$rel — no syntax errors") : bad("$rel — " . trim((string)$res));
}

head('Source — field removed from the create/edit forms');
foreach ([
    'app/bms/product/product_create.php' => 'Add Product form',
    'app/bms/product/product_edit.php'   => 'Edit Product form',
] as $rel => $label) {
    $src = file_get_contents("$root/$rel") ?: '';
    (strpos($src, 'Preferred Supplier') === false) ? ok("$label ($rel) no longer has a \"Preferred Supplier\" field") : bad("$label ($rel) still has a \"Preferred Supplier\" field");
    (strpos($src, 'name="supplier_id"') === false) ? ok("$label ($rel) no longer has a supplier_id form input") : bad("$label ($rel) still has a supplier_id form input");
}

$productsSrc = file_get_contents("$root/app/bms/product/products.php") ?: '';
(strpos($productsSrc, 'id="modal_supplier_id"') === false) ? ok("products.php quick-add modal no longer has the Preferred Supplier field") : bad("products.php quick-add modal still has the Preferred Supplier field");
// The list-page filter dropdown (a separate, still-legitimate use of the
// same column) must NOT have been touched by this change.
(strpos($productsSrc, "\$supplier_id = isset(\$_GET['supplier'])") !== false) ? ok("products.php list-page supplier filter is untouched") : bad("products.php list-page supplier filter appears to have been removed too — out of scope for this change");

head('Source — update_product.php no longer overwrites supplier_id on every save');
$updateSrc = file_get_contents("$root/api/update_product.php") ?: '';
(strpos($updateSrc, "'supplier_id' =>") === false) ? ok("api/update_product.php's UPDATE data no longer includes a 'supplier_id' key") : bad("api/update_product.php still includes 'supplier_id' in its UPDATE data — every edit-save would wipe it to NULL");

if (!$isLive) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no includes/config.php — not a live install)\n";
    exit(0);
}

global $pdo;

head('END-TO-END — an edit-save no longer wipes an existing supplier_id');
try {
    $product = $pdo->query("SELECT product_id, supplier_id, product_name, unit, cost_price, selling_price, status FROM products ORDER BY product_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        echo "  \033[33m—\033[0m skipped: no products exist in this DB to test against\n";
    } else {
        $pid = (int)$product['product_id'];
        $originalSupplierId = $product['supplier_id'];

        $testSupplierId = (int)($pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' ORDER BY supplier_id LIMIT 1")->fetchColumn() ?: 0);
        if (!$testSupplierId) {
            echo "  \033[33m—\033[0m skipped: no active suppliers exist in this DB to test against\n";
        } else {
            $pdo->prepare("UPDATE products SET supplier_id = ? WHERE product_id = ?")->execute([$testSupplierId, $pid]);

            $_SESSION['user_id'] = (int)($pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn() ?: 1);
            $_SESSION['is_admin'] = true;
            $_POST = [
                'product_id'     => $pid,
                'product_name'   => $product['product_name'],
                'unit'           => $product['unit'] ?: 'pcs',
                'cost_price'     => $product['cost_price'] ?: 0,
                'selling_price'  => $product['selling_price'] ?: 0,
                'status'         => $product['status'] ?: 'active',
                // Deliberately NOT sending supplier_id — this is exactly what
                // the real edit form now does since the field was removed.
            ];
            $_FILES = [];

            ob_start();
            include "$root/api/update_product.php";
            $raw = ob_get_clean();
            $res = json_decode($raw, true);

            $afterSupplierId = $pdo->query("SELECT supplier_id FROM products WHERE product_id = $pid")->fetchColumn();

            (is_array($res) && ($res['success'] ?? false) === true)
                ? ok("update_product.php save succeeded (response: " . ($res['message'] ?? 'ok') . ")")
                : bad("update_product.php save did not report success — raw response: " . substr((string)$raw, 0, 200));

            ((int)$afterSupplierId === $testSupplierId)
                ? ok("supplier_id preserved across the save ($testSupplierId unchanged) — not wiped to NULL")
                : bad("supplier_id changed from $testSupplierId to " . var_export($afterSupplierId, true) . " — the removal regressed and is wiping existing data");

            // Restore original state so the test is repeatable / non-destructive.
            $pdo->prepare("UPDATE products SET supplier_id = ? WHERE product_id = ?")->execute([$originalSupplierId, $pid]);
            $restored = $pdo->query("SELECT supplier_id FROM products WHERE product_id = $pid")->fetchColumn();
            ($restored == $originalSupplierId)
                ? ok("test product's supplier_id restored to its original value")
                : bad("failed to restore test product's original supplier_id — manual cleanup needed for product #$pid");

            unset($_SESSION['user_id'], $_SESSION['is_admin'], $_POST, $_FILES);
        }
    }
} catch (Throwable $e) {
    bad('Live section threw: ' . $e->getMessage());
}
