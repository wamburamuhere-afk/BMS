<?php
/**
 * core/service_price_terms.php — optional "price by term" for services.
 *
 * A service is registered exactly as before. If the user also defines terms
 * (any label, any length, any price — nothing here is hardcoded), documents ask
 * "which term?" and take that term's price. products.selling_price keeps the
 * DEFAULT term's price and min_selling_price the cheapest term's, so every
 * existing reader (POS, lists, reports, the POS minimum-price check) keeps
 * working unchanged.
 *
 * vendor_subscription_services marks the services BJP sells as BMS
 * subscriptions — only meaningful on the platform root (core/vendor_context.php).
 */

if (!defined('SPT_UNITS')) {
    define('SPT_UNITS', ['day', 'week', 'month', 'year']);
    define('SPT_MAX_TERMS', 24);
}

if (!function_exists('sptEnsureSchema')) {
    function sptEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `service_price_terms` (
                `term_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id`     INT NOT NULL,
                `term_label`     VARCHAR(100) NOT NULL,
                `duration_value` INT UNSIGNED NOT NULL,
                `duration_unit`  ENUM('day','week','month','year') NOT NULL,
                `price`          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `description`    TEXT NULL,
                `sort_order`     INT NOT NULL DEFAULT 0,
                `is_default`     TINYINT(1) NOT NULL DEFAULT 0,
                `status`         ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
                `created_by`     INT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by`     INT NULL,
                `updated_at`     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`term_id`),
                KEY `idx_spt_product` (`product_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `vendor_subscription_services` (
                `product_id` INT NOT NULL,
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('sptReady')) {
    /** The tables exist (deploy order: code can briefly be live before migrations run). */
    function sptReady(PDO $pdo): bool
    {
        static $ready = false;   // only a positive answer is cached
        if (!$ready) {
            try {
                $ready = (bool)$pdo->query("SHOW TABLES LIKE 'service_price_terms'")->fetch();
            } catch (Throwable $e) {
                return false;
            }
        }
        return $ready;
    }
}

if (!function_exists('sptMonths')) {
    /** Whole months a term covers, or null for day/week terms. */
    function sptMonths(int $value, string $unit): ?int
    {
        if ($unit === 'month') return $value;
        if ($unit === 'year')  return $value * 12;
        return null;
    }
}

if (!function_exists('sptDurationText')) {
    function sptDurationText(int $value, string $unit): string
    {
        $u = in_array($unit, SPT_UNITS, true) ? $unit : 'month';
        return $value . ' ' . $u . ($value === 1 ? '' : 's');
    }
}

if (!function_exists('sptParse')) {
    /**
     * Validate the form's terms. null = the field was not sent at all (leave the
     * service's terms untouched — older clients, the mobile API). [] = remove all.
     *
     * @return array<int, array{term_id:?int, term_label:string, duration_value:int, duration_unit:string, price:float, description:?string, is_default:int, sort_order:int}>|null
     * @throws InvalidArgumentException with a message fit for the user
     */
    function sptParse($raw): ?array
    {
        if ($raw === null) return null;
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') return [];
            $raw = json_decode($raw, true);
            if (!is_array($raw)) throw new InvalidArgumentException('Price terms could not be read. Please try again.');
        }
        if (!is_array($raw)) throw new InvalidArgumentException('Price terms could not be read. Please try again.');
        if (count($raw) > SPT_MAX_TERMS) throw new InvalidArgumentException('A service can have at most ' . SPT_MAX_TERMS . ' price terms.');

        $out = []; $seen = []; $defaults = 0;
        foreach (array_values($raw) as $i => $r) {
            if (!is_array($r)) throw new InvalidArgumentException('Price terms could not be read. Please try again.');
            $n = $i + 1;
            $label = trim((string)($r['term_label'] ?? ''));
            if ($label === '') throw new InvalidArgumentException("Price term #$n needs a name (e.g. \"3 Months\").");
            if (mb_strlen($label) > 100) throw new InvalidArgumentException("Price term #$n: the name is too long (100 characters max).");
            $key = mb_strtolower($label);
            if (isset($seen[$key])) throw new InvalidArgumentException("Two price terms are both called \"$label\".");
            $seen[$key] = true;

            $val = filter_var($r['duration_value'] ?? null, FILTER_VALIDATE_INT);
            if ($val === false || $val < 1 || $val > 1000) throw new InvalidArgumentException("Price term \"$label\": length must be a whole number from 1 to 1000.");
            $unit = (string)($r['duration_unit'] ?? '');
            if (!in_array($unit, SPT_UNITS, true)) throw new InvalidArgumentException("Price term \"$label\": choose days, weeks, months or years.");

            $priceRaw = $r['price'] ?? '';
            if ($priceRaw === '' || !is_numeric($priceRaw)) throw new InvalidArgumentException("Price term \"$label\" needs a price.");
            $price = round((float)$priceRaw, 2);
            if ($price < 0 || $price > 9999999999999) throw new InvalidArgumentException("Price term \"$label\": the price is out of range.");

            $desc = trim((string)($r['description'] ?? ''));
            if (mb_strlen($desc) > 1000) throw new InvalidArgumentException("Price term \"$label\": the description is too long.");

            $def = !empty($r['is_default']) ? 1 : 0;
            $defaults += $def;
            $tid = isset($r['term_id']) && (int)$r['term_id'] > 0 ? (int)$r['term_id'] : null;

            $out[] = ['term_id' => $tid, 'term_label' => $label, 'duration_value' => (int)$val, 'duration_unit' => $unit,
                      'price' => $price, 'description' => $desc !== '' ? $desc : null, 'is_default' => $def, 'sort_order' => $i];
        }
        if ($defaults > 1) throw new InvalidArgumentException('Only one price term can be the default.');
        if ($out && $defaults === 0) $out[0]['is_default'] = 1;
        return $out;
    }
}

if (!function_exists('sptForProduct')) {
    /** @return array<int, array> ordered as the user arranged them */
    function sptForProduct(PDO $pdo, int $productId, bool $activeOnly = true): array
    {
        if (!sptReady($pdo)) return [];
        $st = $pdo->prepare("SELECT term_id, product_id, term_label, duration_value, duration_unit, price, description,
                                    sort_order, is_default, status
                               FROM service_price_terms
                              WHERE product_id = ? AND status " . ($activeOnly ? "= 'active'" : "<> 'deleted'") . "
                              ORDER BY sort_order, term_id");
        $st->execute([$productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('sptProductIdsWithTerms')) {
    /** @return array<int,true> product ids (of $ids) that have at least one active term */
    function sptProductIdsWithTerms(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !sptReady($pdo)) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($pdo->query("SELECT DISTINCT product_id FROM service_price_terms WHERE status = 'active' AND product_id IN ($in)") as $r) {
            $out[(int)$r['product_id']] = true;
        }
        return $out;
    }
}

if (!function_exists('sptSummary')) {
    /** @return array<int, array{count:int, min:float}> per product id with active terms — one query for a whole list */
    function sptSummary(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !sptReady($pdo)) return [];
        $out = [];
        foreach ($pdo->query("SELECT product_id, COUNT(*) AS n, MIN(price) AS mn FROM service_price_terms
                               WHERE status = 'active' AND product_id IN (" . implode(',', $ids) . ") GROUP BY product_id") as $r) {
            $out[(int)$r['product_id']] = ['count' => (int)$r['n'], 'min' => (float)$r['mn']];
        }
        return $out;
    }
}

if (!function_exists('sptTermUsed')) {
    /** Has any document line recorded this term? Then it is kept (inactive) rather than deleted. */
    function sptTermUsed(PDO $pdo, int $termId): bool
    {
        foreach (['quotation_items', 'sales_order_items', 'invoice_items'] as $t) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE 'price_term_id'")->fetch()) continue;
                $st = $pdo->prepare("SELECT 1 FROM `$t` WHERE price_term_id = ? LIMIT 1");
                $st->execute([$termId]);
                if ($st->fetchColumn()) return true;
            } catch (Throwable $e) {
                continue;
            }
        }
        return false;
    }
}

if (!function_exists('sptSave')) {
    /**
     * Make the service's terms match $rows (from sptParse). Joins the caller's
     * transaction. A term no longer listed is deleted, or kept as inactive if a
     * document already used it. Returns the active terms' default and lowest price.
     *
     * @return array{count:int, default_price:?float, min_price:?float}
     * @throws InvalidArgumentException when a term id does not belong to this service
     */
    function sptSave(PDO $pdo, int $productId, array $rows, int $userId): array
    {
        $existing = [];
        foreach (sptForProduct($pdo, $productId, false) as $e) $existing[(int)$e['term_id']] = $e;

        $keep = [];
        $upd = $pdo->prepare("UPDATE service_price_terms SET term_label = ?, duration_value = ?, duration_unit = ?, price = ?,
                                     description = ?, sort_order = ?, is_default = ?, status = 'active', updated_by = ?
                               WHERE term_id = ? AND product_id = ?");
        $ins = $pdo->prepare("INSERT INTO service_price_terms
                                (product_id, term_label, duration_value, duration_unit, price, description, sort_order, is_default, status, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
        foreach ($rows as $r) {
            if ($r['term_id'] !== null) {
                if (!isset($existing[$r['term_id']])) {
                    throw new InvalidArgumentException('One of the price terms no longer exists. Reload the page and try again.');
                }
                $upd->execute([$r['term_label'], $r['duration_value'], $r['duration_unit'], $r['price'], $r['description'],
                               $r['sort_order'], $r['is_default'], $userId, $r['term_id'], $productId]);
                $keep[$r['term_id']] = true;
            } else {
                $ins->execute([$productId, $r['term_label'], $r['duration_value'], $r['duration_unit'], $r['price'],
                               $r['description'], $r['sort_order'], $r['is_default'], $userId]);
            }
        }

        $retire = $pdo->prepare("UPDATE service_price_terms SET status = ?, is_default = 0, updated_by = ? WHERE term_id = ?");
        foreach ($existing as $id => $e) {
            if (isset($keep[$id]) || $e['status'] === 'deleted') continue;
            $retire->execute([sptTermUsed($pdo, $id) ? 'inactive' : 'deleted', $userId, $id]);
        }

        if (!$rows) return ['count' => 0, 'default_price' => null, 'min_price' => null];
        $default = $rows[0]['price']; $min = null;
        foreach ($rows as $r) {
            if ($r['is_default']) $default = $r['price'];
            $min = $min === null ? $r['price'] : min($min, $r['price']);
        }
        return ['count' => count($rows), 'default_price' => $default, 'min_price' => $min];
    }
}

if (!function_exists('vssIsSubscription')) {
    function vssIsSubscription(PDO $pdo, int $productId): bool
    {
        if (!sptReady($pdo)) return false;
        $st = $pdo->prepare("SELECT 1 FROM vendor_subscription_services WHERE product_id = ?");
        $st->execute([$productId]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('vssSet')) {
    /**
     * Mark/unmark a service as a BMS subscription. Callers gate this on
     * isPlatformVendorContext(). A subscription's terms must all be months or
     * years — the bridge converts a paid term into months of access.
     *
     * @throws InvalidArgumentException
     */
    function vssSet(PDO $pdo, int $productId, bool $on, array $terms, int $userId): void
    {
        if ($on) {
            if (!$terms) throw new InvalidArgumentException('A system subscription needs at least one price term (e.g. 1 Month, 1 Year).');
            foreach ($terms as $t) {
                if (sptMonths((int)$t['duration_value'], (string)$t['duration_unit']) === null) {
                    throw new InvalidArgumentException('Price term "' . $t['term_label'] . '": a system subscription can only use months or years.');
                }
            }
            $pdo->prepare("INSERT IGNORE INTO vendor_subscription_services (product_id, created_by) VALUES (?, ?)")
                ->execute([$productId, $userId]);
        } else {
            $pdo->prepare("DELETE FROM vendor_subscription_services WHERE product_id = ?")->execute([$productId]);
        }
    }
}
