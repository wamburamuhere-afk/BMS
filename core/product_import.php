<?php
/**
 * core/product_import.php — bulk product import from a CSV (Smart POS).
 *
 * Every row goes through the same steps as Add Product (api/create_product.php):
 *   - name already used (case/space-insensitive) → the row is skipped, never duplicated
 *   - category matched by name (case-insensitive) or created, type 'product'
 *   - SKU / barcode generated, unique against the database and the rest of the file
 *   - opening stock received as a real batch in the chosen shop (receiveProductBatch)
 *     and posted to the ledger, Dr Inventory / Cr Opening Balance (postStockAdjustmentGl)
 *   - wholesale price written to the Wholesale price group POS charges from
 * piImportCommit() runs the whole file in one transaction: one bad row and nothing
 * is saved, so a shop never ends up with half a list.
 *
 * CSV columns (header row, any order, case-insensitive):
 *   product_name*, category, unit, cost_price*, wholesale_price, selling_price*,
 *   opening_stock, min_stock_level
 */

require_once __DIR__ . '/stock_ledger.php';
require_once __DIR__ . '/stock_posting.php';
require_once __DIR__ . '/stock_intake.php';
require_once __DIR__ . '/warehouse_scope.php';

if (!function_exists('piColumns')) {
    function piColumns(): array
    {
        return ['product_name', 'category', 'unit', 'cost_price', 'wholesale_price', 'selling_price', 'opening_stock', 'min_stock_level'];
    }

    /** A CSV template the user fills (header + one example row). */
    function piTemplateCsv(): string
    {
        return implode(',', piColumns()) . "\n" . 'Example Product,Vyakula,pcs,1000,1200,1500,10,2' . "\n";
    }

    /**
     * Reads the CSV into rows of raw strings keyed by column. Accepts an Excel
     * UTF-8 BOM and comma or semicolon separators (Excel in some locales saves ';').
     * Returns ['rows' => [...], 'error' => null|string].
     */
    function piParseCsv(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return ['rows' => [], 'error' => t('The file is empty.')];
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        $firstLine = strtok($raw, "\r\n");
        $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, 0, $sep);
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $header ?: []);
        foreach (['product_name', 'cost_price', 'selling_price'] as $need) {
            if (!in_array($need, $header, true)) {
                fclose($fh);
                return ['rows' => [], 'error' => sprintf(t('Column "%s" is missing. Download the template and keep its first row.'), $need)];
            }
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $sep)) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue; // blank line
            $row = ['line' => $line];
            foreach ($header as $i => $col) {
                if (in_array($col, piColumns(), true)) $row[$col] = trim((string)($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        if (!$rows) return ['rows' => [], 'error' => t('The file has no products under the header row.')];
        if (count($rows) > 2000) return ['rows' => [], 'error' => t('At most 2,000 products per file. Split the file and import it in parts.')];
        return ['rows' => $rows, 'error' => null];
    }

    /** "1,500" / "1 500" / "1500.00" → 1500.0; '' → null; anything else → false. */
    function piNumber(?string $v)
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace([',', ' '], '', $v);
        return is_numeric($v) ? (float)$v : false;
    }

    /**
     * Validates every row and decides what happens to it, without writing.
     * Each row gets 'status' = new | exists | duplicate | error, plus 'message'.
     */
    function piPlan(PDO $pdo, array $rows): array
    {
        $existsStmt = $pdo->prepare("SELECT product_id FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) LIMIT 1");
        $catStmt = $pdo->prepare("SELECT category_id FROM categories WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?)) AND status = 'active' AND type = 'product' LIMIT 1");
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $name = preg_replace('/\s+/', ' ', trim($r['product_name'] ?? ''));
            $cost = piNumber($r['cost_price'] ?? '');
            $sell = piNumber($r['selling_price'] ?? '');
            $whole = piNumber($r['wholesale_price'] ?? '');
            $stock = piNumber($r['opening_stock'] ?? '');
            $min = piNumber($r['min_stock_level'] ?? '');
            $category = preg_replace('/\s+/', ' ', trim($r['category'] ?? ''));
            $unit = trim($r['unit'] ?? '');

            $p = [
                'line' => $r['line'], 'product_name' => $name, 'category' => $category, 'unit' => $unit !== '' ? mb_substr($unit, 0, 20) : 'pcs',
                'cost_price' => $cost, 'wholesale_price' => $whole, 'selling_price' => $sell,
                'opening_stock' => $stock, 'min_stock_level' => $min, 'status' => 'new', 'message' => '',
                'category_new' => false,
            ];
            $errors = [];
            if ($name === '') $errors[] = t('Name is empty.');
            elseif (mb_strlen($name) > 255) $errors[] = t('Name is too long.');
            if ($cost === false || $cost === null || $cost < 0) $errors[] = t('Buying price must be a number (0 or more).');
            if ($sell === false || $sell === null || $sell <= 0) $errors[] = t('Selling price must be a number above 0.');
            foreach (['wholesale_price' => $whole, 'opening_stock' => $stock, 'min_stock_level' => $min] as $k => $v) {
                if ($v === false || ($v !== null && $v < 0)) $errors[] = sprintf(t('%s must be a number (0 or more).'), $k);
            }
            if (mb_strlen($category) > 100) $errors[] = t('Category name is too long.');

            if ($errors) {
                $p['status'] = 'error';
                $p['message'] = implode(' ', $errors);
            } else {
                $key = mb_strtolower($name);
                $existsStmt->execute([$name]);
                if ($existsStmt->fetchColumn()) {
                    $p['status'] = 'exists';
                    $p['message'] = t('A product with this name already exists — skipped.');
                } elseif (isset($seen[$key])) {
                    $p['status'] = 'duplicate';
                    $p['message'] = sprintf(t('Same name as line %d of this file — skipped.'), $seen[$key]);
                } else {
                    $seen[$key] = $r['line'];
                    if ($category !== '') {
                        $catStmt->execute([$category]);
                        $p['category_new'] = !$catStmt->fetchColumn();
                    }
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    function piSummary(array $plan): array
    {
        $s = ['new' => 0, 'exists' => 0, 'duplicate' => 0, 'error' => 0, 'new_categories' => []];
        foreach ($plan as $p) {
            $s[$p['status']]++;
            // First spelling wins — the one piImportCommit() creates the category with.
            if ($p['status'] === 'new' && $p['category_new']) $s['new_categories'][mb_strtolower($p['category'])] ??= $p['category'];
        }
        $s['new_categories'] = array_values($s['new_categories']);
        return $s;
    }

    /** A code not used by any product nor already handed out in this import. */
    function piUniqueCode(PDO $pdo, callable $make, string $sql, array &$taken): string
    {
        $check = $pdo->prepare($sql);
        for ($i = 0; $i < 50; $i++) {
            $code = $make();
            if (isset($taken[$code])) continue;
            $check->execute([$code, $code]);
            if ($check->fetchColumn()) continue;
            $taken[$code] = true;
            return $code;
        }
        throw new RuntimeException('Could not generate a unique code.');
    }

    function piSku(): string
    {
        return 'PROD' . time() . random_int(100000, 999999);
    }

    function piBarcode(): string
    {
        $b = '00' . random_int(10000, 99999) . random_int(10000, 99999);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += ($i % 2 === 0) ? (int)$b[$i] : (int)$b[$i] * 3;
        return $b . ((10 - ($sum % 10)) % 10);
    }

    /**
     * Saves every 'new' row of the plan, all or nothing. Rows that exist / repeat
     * are skipped; any 'error' row stops the whole import before anything is written.
     * Returns ['created' => n, 'skipped' => n, 'categories_created' => [...], 'products' => [[id, name]...]].
     */
    function piImportCommit(PDO $pdo, array $plan, int $warehouseId, int $userId): array
    {
        foreach ($plan as $p) {
            if ($p['status'] === 'error') throw new InvalidArgumentException(sprintf(t('Line %d: %s'), $p['line'], $p['message']));
        }
        $catFind = $pdo->prepare("SELECT category_id FROM categories WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?)) AND status = 'active' AND type = 'product' LIMIT 1");
        $catAdd = $pdo->prepare("INSERT INTO categories (category_name, type, status) VALUES (?, 'product', 'active')");
        $existsStmt = $pdo->prepare("SELECT product_id FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) LIMIT 1");
        $takenSku = []; $takenBar = [];
        $result = ['created' => 0, 'skipped' => 0, 'categories_created' => [], 'products' => []];
        $date = date('Y-m-d');

        $pdo->beginTransaction();
        try {
            foreach ($plan as $p) {
                if ($p['status'] !== 'new') { $result['skipped']++; continue; }
                // Re-checked inside the transaction: someone may have added it since the preview.
                $existsStmt->execute([$p['product_name']]);
                if ($existsStmt->fetchColumn()) { $result['skipped']++; continue; }

                $categoryId = null;
                if ($p['category'] !== '') {
                    $catFind->execute([$p['category']]);
                    $categoryId = $catFind->fetchColumn() ?: null;
                    if (!$categoryId) {
                        $catAdd->execute([$p['category']]);
                        $categoryId = (int)$pdo->lastInsertId();
                        $result['categories_created'][] = $p['category'];
                    }
                }
                $sku = piUniqueCode($pdo, 'piSku', "SELECT 1 FROM products WHERE sku = ? OR product_code = ? LIMIT 1", $takenSku);
                $barcode = piUniqueCode($pdo, 'piBarcode', "SELECT 1 FROM products WHERE barcode = ? OR barcode = ? LIMIT 1", $takenBar);
                $cost = (float)$p['cost_price'];
                $sell = (float)$p['selling_price'];
                $whole = (float)($p['wholesale_price'] ?? 0);

                // Same columns and defaults as api/create_product.php, so an imported
                // product is indistinguishable from one added by hand.
                $data = [
                    'product_name' => $p['product_name'], 'sku' => $sku, 'product_code' => $sku, 'barcode' => $barcode,
                    'description' => null, 'category_id' => $categoryId, 'brand_id' => null, 'unit' => $p['unit'],
                    'weight' => 0, 'dimensions' => null, 'cost_price' => $cost, 'selling_price' => $sell,
                    'min_selling_price' => $sell, 'wholesale_price' => $whole, 'tax_id' => null, 'tax_rate' => 0,
                    'discount_rate' => 0, 'reorder_level' => 0, 'min_stock_level' => (float)($p['min_stock_level'] ?? 0),
                    'max_stock_level' => 0, 'image_url' => '', 'status' => 'active', 'is_service' => 0, 'is_taxable' => 0,
                    'track_inventory' => 1, 'manufacturer' => null, 'model' => null, 'serial_number' => null,
                    'warranty_period' => 0, 'warranty_unit' => null, 'guarantee_period' => null, 'guarantee_unit' => null,
                    'barcode_symbology' => 'CODE128', 'track_serials' => 0, 'kitchen_station_id' => null, 'expiry_days' => 0,
                    'created_by' => $userId,
                ];
                $cols = array_keys($data);
                $pdo->prepare("INSERT INTO products (" . implode(', ', $cols) . ") VALUES (:" . implode(', :', $cols) . ")")->execute($data);
                $productId = (int)$pdo->lastInsertId();

                if ($whole > 0) {
                    require_once __DIR__ . '/pos_price_groups.php';
                    syncWholesaleGroupPrice($pdo, $productId, $whole);
                }

                $qty = (float)($p['opening_stock'] ?? 0);
                if ($qty > 0) {
                    $intake = receiveProductBatch($pdo, [
                        'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $qty,
                        'unit_cost' => $cost, 'write_batch' => true, 'expiry_date' => null, 'manufacturing_date' => null,
                        'selling_price' => $sell, 'wholesale_price' => $whole,
                        'movement_type' => 'adjustment_in', 'reference_type' => 'manual', 'reference_id' => $productId,
                        'reference_number' => $sku, 'movement_date' => $date, 'created_by' => $userId,
                        'notes' => 'Initial product stock (import)',
                    ]);
                    postStockAdjustmentGl($pdo, (int)$intake['movement_id'], $qty, 'adjustment_in', $cost, null, $userId, $date, $sku);
                }
                $result['created']++;
                $result['products'][] = [$productId, $p['product_name']];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $result;
    }
}
