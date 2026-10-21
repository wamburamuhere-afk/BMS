<?php
/**
 * core/sales_line_discount.php — one line's money on a Quotation / Sales Order / Invoice.
 *
 * A line discount is either a percentage of the line or a fixed amount of money
 * ("10,000 off"). A fixed amount is stored as typed in <table>.discount_amount, so
 * the document, its print and the ledger carry exactly that figure — converting it
 * to a 2-decimal percentage would drift (10,000 off 894,000 is 1.1185…%).
 * discount_percent stays filled (the equivalent %, display only) for older readers.
 *
 * Every save endpoint calls salesLineMath() — the browser's figures are never trusted.
 * The form default (% or amount) is a System Settings choice: salesDiscountDefaultType().
 */

if (!defined('SALES_DISCOUNT_TABLES')) {
    define('SALES_DISCOUNT_TABLES', ['quotation_items', 'sales_order_items', 'invoice_items']);
}

if (!function_exists('salesDiscountDefaultType')) {
    /** 'percent' (the long-standing behaviour) or 'amount'. */
    function salesDiscountDefaultType(): string
    {
        return get_setting('sales_discount_type', 'percent') === 'amount' ? 'amount' : 'percent';
    }
}

if (!function_exists('salesDiscountAllowSwitch')) {
    /** Whether a user may switch one line between % and amount. */
    function salesDiscountAllowSwitch(): bool
    {
        return get_setting('sales_discount_allow_switch', '1') !== '0';
    }
}

if (!function_exists('salesLineMath')) {
    /**
     * @param array  $item  quantity, unit_price, tax_rate, discount_type ('percent'|'amount'),
     *                      discount_value (the % or the amount typed); older callers send only
     *                      discount_percent, which is read as a percentage.
     * @param string $name  the line's name, for messages
     * @return array{qty:float, price:float, tax_rate:float, line_subtotal:float, discount_type:string,
     *               discount_percent:float, line_discount:float, line_tax:float, line_total:float}
     * @throws InvalidArgumentException with a message fit for the user
     */
    function salesLineMath(array $item, string $name = 'a line'): array
    {
        $qty      = (float)($item['quantity'] ?? 0);
        $price    = (float)($item['unit_price'] ?? 0);
        $tax_rate = (float)($item['tax_rate'] ?? 0);
        $type     = ($item['discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        $raw      = $item['discount_value'] ?? ($item['discount_percent'] ?? 0);
        $value    = (float)(is_string($raw) ? str_replace(',', '', $raw) : $raw);

        $line_subtotal = $qty * $price;
        if ($value < 0) {
            throw new InvalidArgumentException("The discount on $name cannot be negative.");
        }
        if ($type === 'amount') {
            $line_discount = round($value, 2);
            if ($line_discount > round($line_subtotal, 2) + 0.004) {
                throw new InvalidArgumentException(sprintf(
                    'The discount on %s (%s) is more than the line amount (%s).',
                    $name, number_format($line_discount, 2), number_format($line_subtotal, 2)
                ));
            }
            $discount_percent = $line_subtotal > 0 ? min(round($line_discount / $line_subtotal * 100, 2), 100.0) : 0.0;
        } else {
            $discount_percent = min($value, 100.0);
            $line_discount    = round($line_subtotal * ($discount_percent / 100), 2);
        }

        $line_tax   = ($line_subtotal - $line_discount) * ($tax_rate / 100);
        $line_total = $line_subtotal - $line_discount + $line_tax;

        return [
            'qty' => $qty, 'price' => $price, 'tax_rate' => $tax_rate, 'line_subtotal' => $line_subtotal,
            'discount_type' => $type, 'discount_percent' => $discount_percent,
            'line_discount' => $line_discount, 'line_tax' => $line_tax, 'line_total' => $line_total,
        ];
    }
}

if (!function_exists('salesEnsureDiscountColumns')) {
    /** discount_type + discount_amount on the three line tables (migrations call this). Idempotent. */
    function salesEnsureDiscountColumns(PDO $pdo): void
    {
        $cols = [
            'discount_amount' => "ADD COLUMN `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'discount_type'   => "ADD COLUMN `discount_type` VARCHAR(10) NOT NULL DEFAULT 'percent'",
        ];
        foreach (SALES_DISCOUNT_TABLES as $t) {
            if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch()) continue;
            foreach ($cols as $c => $ddl) {
                if ($pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) continue;
                try {
                    $pdo->exec("ALTER TABLE `$t` $ddl");
                    echo "  + $t.$c added\n";
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;   // a concurrent run added it
                }
            }
            // Lines saved before: their money discount from the stored percentage (once — only rows still at 0).
            $pdo->exec("UPDATE `$t` SET discount_amount = ROUND(quantity * unit_price * discount_percent / 100, 2)
                          WHERE discount_amount = 0 AND discount_percent > 0");
        }
    }
}

if (!function_exists('salesDiscountColumnsReady')) {
    /** The table has discount_type (code can briefly be live before its migration runs). */
    function salesDiscountColumnsReady(PDO $pdo, string $table): bool
    {
        static $ready = [];
        if (!empty($ready[$table])) return true;
        try {
            return $ready[$table] = (bool)$pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE 'discount_type'")->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('salesStampLineDiscount')) {
    /** Record the line's discount type and money amount (after its INSERT). */
    function salesStampLineDiscount(PDO $pdo, string $table, string $pk, int $lineId, array $math): void
    {
        if ($lineId <= 0 || !in_array($table, SALES_DISCOUNT_TABLES, true) || !salesDiscountColumnsReady($pdo, $table)) return;
        $pdo->prepare("UPDATE `$table` SET discount_type = ?, discount_amount = ? WHERE `$pk` = ?")
            ->execute([$math['discount_type'], $math['line_discount'], $lineId]);
    }
}

if (!function_exists('salesDiscountValueOf')) {
    /** What the form shows in a saved line's discount box: the amount for 'amount' lines, else the %. */
    function salesDiscountValueOf(array $line): float
    {
        return ($line['discount_type'] ?? 'percent') === 'amount'
            ? (float)($line['discount_amount'] ?? 0)
            : (float)($line['discount_percent'] ?? 0);
    }
}
