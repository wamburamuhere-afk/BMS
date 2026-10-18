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

if (!function_exists('sptPlanColumnReady')) {
    /** service_price_terms.plan_id + service_plans exist (service_plans_plan.md Phase 2 migration ran). */
    function sptPlanColumnReady(PDO $pdo): bool
    {
        static $ready = false;   // only a positive answer is cached
        if (!$ready) {
            try {
                $ready = sptReady($pdo)
                    && (bool)$pdo->query("SHOW COLUMNS FROM `service_price_terms` LIKE 'plan_id'")->fetch()
                    && (bool)$pdo->query("SHOW TABLES LIKE 'service_plans'")->fetch();
            } catch (Throwable $e) {
                return false;
            }
        }
        return $ready;
    }
}

if (!function_exists('sptDisplayLabel')) {
    /** What a document line shows: "Plan — Term" for a plan's term, else the term label. Fits the 100-char snapshot. */
    function sptDisplayLabel(?string $planName, string $termLabel): string
    {
        $planName = trim((string)$planName);
        $label = $planName !== '' ? $planName . ' — ' . $termLabel : $termLabel;
        return mb_substr($label, 0, 100);
    }
}

if (!function_exists('sptForProduct')) {
    /**
     * @return array<int, array> ordered as the user arranged them. After the plans
     * migration each row also carries plan_id, plan_name and display_label.
     */
    function sptForProduct(PDO $pdo, int $productId, bool $activeOnly = true): array
    {
        if (!sptReady($pdo)) return [];
        $plans = sptPlanColumnReady($pdo);
        $st = $pdo->prepare("SELECT t.term_id, t.product_id, t.term_label, t.duration_value, t.duration_unit, t.price, t.description,
                                    t.sort_order, t.is_default, t.status"
                           . ($plans ? ", t.plan_id, sp.plan_name" : "") . "
                               FROM service_price_terms t"
                           . ($plans ? " LEFT JOIN service_plans sp ON sp.plan_id = t.plan_id" : "") . "
                              WHERE t.product_id = ? AND t.status " . ($activeOnly ? "= 'active'" : "<> 'deleted'") . "
                              ORDER BY t.sort_order, t.term_id");
        $st->execute([$productId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['plan_id'] = isset($r['plan_id']) && $r['plan_id'] !== null ? (int)$r['plan_id'] : null;
            $r['plan_name'] = $r['plan_name'] ?? null;
            $r['display_label'] = sptDisplayLabel($r['plan_name'], (string)$r['term_label']);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('vssPlatformProduct')) {
    /**
     * The platform product (control DB product_key, e.g. bms_shop) this service
     * sells, or null. Such a service's plans and prices live in Superadmin and
     * are read-only in BJP's books (service_plans_plan.md D1).
     */
    function vssPlatformProduct(PDO $pdo, int $productId): ?string
    {
        if ($productId <= 0 || !sptReady($pdo)) return null;
        try {
            $st = $pdo->prepare("SELECT platform_product_key FROM vendor_subscription_services WHERE product_id = ?");
            $st->execute([$productId]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
        } catch (Throwable $e) {
            return null;   // column not there yet
        }
    }
}

if (!function_exists('vssPlatformProductsFor')) {
    /** @return array<int,string> product id => platform product key, for those of $ids that are mapped */
    function vssPlatformProductsFor(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !sptReady($pdo)) return [];
        try {
            $rows = $pdo->query("SELECT product_id, platform_product_key FROM vendor_subscription_services
                                  WHERE platform_product_key IS NOT NULL AND platform_product_key <> ''
                                    AND product_id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_KEY_PAIR);
            return array_map('strval', $rows);
        } catch (Throwable $e) {
            return [];   // column not there yet
        }
    }
}

if (!function_exists('sptAssertNotMapped')) {
    /** Server-side lock: a service mapped to a platform product never takes local prices. */
    function sptAssertNotMapped(PDO $pdo, int $productId): void
    {
        if (vssPlatformProduct($pdo, $productId) !== null) {
            throw new InvalidArgumentException("This service's plans and prices are managed in Superadmin → Plans. They cannot be changed here.");
        }
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
        if ($rows) sptAssertNotMapped($pdo, $productId);

        $existing = [];
        foreach (sptForProduct($pdo, $productId, false) as $e) $existing[(int)$e['term_id']] = $e;

        // A row's plan_id (set by spSave() in core/service_plans.php) is written
        // only after the plans migration; a plain term row clears it.
        $planCol = sptPlanColumnReady($pdo);
        $keep = [];
        $upd = $pdo->prepare("UPDATE service_price_terms SET term_label = ?, duration_value = ?, duration_unit = ?, price = ?,
                                     description = ?, sort_order = ?, is_default = ?, status = 'active', updated_by = ?"
                             . ($planCol ? ", plan_id = ?" : "") . "
                               WHERE term_id = ? AND product_id = ?");
        $ins = $pdo->prepare("INSERT INTO service_price_terms
                                (product_id, term_label, duration_value, duration_unit, price, description, sort_order, is_default, status, created_by"
                             . ($planCol ? ", plan_id" : "") . ")
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?" . ($planCol ? ", ?" : "") . ")");
        foreach ($rows as $r) {
            $plan = isset($r['plan_id']) && (int)$r['plan_id'] > 0 ? (int)$r['plan_id'] : null;
            if ($r['term_id'] !== null) {
                if (!isset($existing[$r['term_id']])) {
                    throw new InvalidArgumentException('One of the price terms no longer exists. Reload the page and try again.');
                }
                $args = [$r['term_label'], $r['duration_value'], $r['duration_unit'], $r['price'], $r['description'],
                         $r['sort_order'], $r['is_default'], $userId];
                if ($planCol) $args[] = $plan;
                $args[] = $r['term_id'];
                $args[] = $productId;
                $upd->execute($args);
                $keep[$r['term_id']] = true;
            } else {
                $args = [$productId, $r['term_label'], $r['duration_value'], $r['duration_unit'], $r['price'],
                         $r['description'], $r['sort_order'], $r['is_default'], $userId];
                if ($planCol) $args[] = $plan;
                $ins->execute($args);
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

if (!function_exists('vssContractTemplate')) {
    /** The Document Template a subscription service's contract is made from (Phase 6), or null. */
    function vssContractTemplate(PDO $pdo, int $productId): ?int
    {
        try {
            $st = $pdo->prepare("SELECT contract_template_id FROM vendor_subscription_services WHERE product_id = ?");
            $st->execute([$productId]);
            $v = $st->fetchColumn();
            return $v ? (int)$v : null;
        } catch (Throwable $e) {
            return null;   // column not there yet
        }
    }
}

if (!function_exists('vssSetContractTemplate')) {
    /** 0 = no contract. Only an active template with content is accepted. Skipped before the migration ran. */
    function vssSetContractTemplate(PDO $pdo, int $productId, int $templateId): void
    {
        if (!$pdo->query("SHOW COLUMNS FROM `vendor_subscription_services` LIKE 'contract_template_id'")->fetch()) return;
        if ($templateId > 0) {
            $ok = $pdo->prepare("SELECT 1 FROM document_templates WHERE id = ? AND is_active = 1 AND content IS NOT NULL");
            $ok->execute([$templateId]);
            if (!$ok->fetchColumn()) throw new InvalidArgumentException('The chosen contract template is not available.');
        }
        $pdo->prepare("UPDATE vendor_subscription_services SET contract_template_id = ? WHERE product_id = ?")
            ->execute([$templateId > 0 ? $templateId : null, $productId]);
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
    function vssSet(PDO $pdo, int $productId, bool $on, array $terms, int $userId, bool $mapped = false): void
    {
        if ($on) {
            // A service mapped to a platform product takes its prices from Superadmin — no local terms.
            if (!$terms && !$mapped) throw new InvalidArgumentException('A system subscription needs at least one price term (e.g. 1 Month, 1 Year).');
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

/* ── Document lines (Quotation / Sales Order / Invoice) ────────────────────── */

if (!defined('SPT_LINE_TABLES')) {
    // table => primary key. convert_quote_to_order.php copies every quotation-line
    // column into sales_order_items, so all three must always gain these together.
    define('SPT_LINE_TABLES', ['quotation_items' => 'order_item_id', 'sales_order_items' => 'order_item_id', 'invoice_items' => 'invoice_item_id']);
}

if (!function_exists('sptEnsureLineColumns')) {
    function sptEnsureLineColumns(PDO $pdo): void
    {
        $cols = [
            'price_term_id'       => "ADD COLUMN `price_term_id` INT UNSIGNED NULL DEFAULT NULL",
            'price_term_label'    => "ADD COLUMN `price_term_label` VARCHAR(100) NULL DEFAULT NULL",
            'term_duration_value' => "ADD COLUMN `term_duration_value` INT UNSIGNED NULL DEFAULT NULL",
            'term_duration_unit'  => "ADD COLUMN `term_duration_unit` VARCHAR(10) NULL DEFAULT NULL",
        ];
        foreach (array_keys(SPT_LINE_TABLES) as $t) {
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
        }
    }
}

if (!function_exists('sptLineColumnsReady')) {
    function sptLineColumnsReady(PDO $pdo, string $table): bool
    {
        static $ready = [];
        if (!empty($ready[$table])) return true;
        try {
            return $ready[$table] = (bool)$pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE 'term_duration_unit'")->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sptStripTermLine')) {
    /** Remove a previous "Term: …" line the form or server appended. */
    function sptStripTermLine(?string $description): string
    {
        $d = preg_replace('/(^|\R)Term: [^\r\n]*/u', '', (string)$description);
        return trim((string)$d);
    }
}

if (!function_exists('sptResolveLineTerm')) {
    /**
     * The term recorded on one document line, read from the DATABASE (never the
     * browser): it must belong to the line's product and not be deleted (an
     * inactive term is still accepted, so an older document can be re-saved).
     * The description ends with "Term: <label>" so every existing print shows it.
     *
     * @return array{price_term_id:?int, price_term_label:?string, term_duration_value:?int, term_duration_unit:?string, description:string}
     * @throws InvalidArgumentException
     */
    function sptResolveLineTerm(PDO $pdo, $productId, $termId, ?string $description, string $lineName = 'a line'): array
    {
        $none = ['price_term_id' => null, 'price_term_label' => null, 'term_duration_value' => null,
                 'term_duration_unit' => null, 'description' => trim((string)$description)] + SPT_PLAN_LINE_NONE;
        // A platform plan price ("p12") or add-on ("a3-12") — read from the control DB.
        if (sptParseLineToken($termId) !== null) {
            return sptResolvePlanToken($pdo, (int)$productId, sptParseLineToken($termId), $description, $lineName);
        }
        $termId = (int)$termId;
        if ($termId <= 0 || !sptReady($pdo)) return $none;

        $plans = sptPlanColumnReady($pdo);
        $st = $pdo->prepare("SELECT t.term_id, t.product_id, t.term_label, t.duration_value, t.duration_unit"
                           . ($plans ? ", sp.plan_name" : ", NULL AS plan_name") . "
                               FROM service_price_terms t"
                           . ($plans ? " LEFT JOIN service_plans sp ON sp.plan_id = t.plan_id" : "") . "
                              WHERE t.term_id = ? AND t.status <> 'deleted'");
        $st->execute([$termId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || (int)$t['product_id'] !== (int)$productId) {
            throw new InvalidArgumentException("The term chosen for $lineName does not belong to that service. Choose the term again.");
        }
        // A plan's term is recorded as "Plan — Term" so the line says which plan was sold.
        $label = sptDisplayLabel($t['plan_name'], (string)$t['term_label']);
        $desc = sptStripTermLine($description);
        return [
            'price_term_id'       => (int)$t['term_id'],
            'price_term_label'    => $label,
            'term_duration_value' => (int)$t['duration_value'],
            'term_duration_unit'  => $t['duration_unit'],
            'description'         => ($desc !== '' ? $desc . "\n" : '') . 'Term: ' . $label,
        ] + SPT_PLAN_LINE_NONE;
    }
}

/* ── Platform plans on document lines (service_plans_plan.md Phase 5, D9) ──────
 * A service mapped to a platform product sells the superadmin's plans. Its line
 * "term" is a token: "p{plan_price_id}" for the plan, "a{addon_id}-{plan_price_id}"
 * for an add-on locked to that plan's term. price_term_id stays NULL (it points
 * at BJP's local terms); the plan snapshot goes in the plan_* columns. */

if (!defined('SPT_PLAN_LINE_NONE')) {
    define('SPT_PLAN_LINE_NONE', ['plan_key' => null, 'plan_name' => null, 'plan_price_id' => null,
                                  'plan_addon_id' => null, 'addon_quota' => null, 'addon_units' => null]);
}

if (!function_exists('sptParseLineToken')) {
    /** @return array{kind:string, plan_price_id:int, addon_id:?int}|null */
    function sptParseLineToken($v): ?array
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        if (preg_match('/^p([1-9]\d{0,9})$/', $v, $m)) return ['kind' => 'plan', 'plan_price_id' => (int)$m[1], 'addon_id' => null];
        if (preg_match('/^a([1-9]\d{0,9})-([1-9]\d{0,9})$/', $v, $m)) return ['kind' => 'addon', 'plan_price_id' => (int)$m[2], 'addon_id' => (int)$m[1]];
        return null;
    }
}

if (!function_exists('sptLineToken')) {
    /** The token a saved line was resolved from (null = a local term or no term). */
    function sptLineToken(array $line): ?string
    {
        $pp = (int)($line['plan_price_id'] ?? 0);
        if ($pp <= 0) return null;
        $a = (int)($line['plan_addon_id'] ?? 0);
        return $a > 0 ? "a{$a}-{$pp}" : "p{$pp}";
    }
}

if (!function_exists('sptResolvePlanToken')) {
    /**
     * Resolve a plan/add-on token against the CONTROL DB. The line's service must
     * be mapped to the plan's platform product. A retired price still resolves,
     * so an older document can be re-saved.
     *
     * @throws InvalidArgumentException
     */
    function sptResolvePlanToken(PDO $pdo, int $productId, array $tok, ?string $description, string $lineName): array
    {
        $bad = "The plan chosen for $lineName does not belong to that service. Choose the plan again.";
        $productKey = vssPlatformProduct($pdo, $productId);
        if ($productKey === null) throw new InvalidArgumentException($bad);
        require_once __DIR__ . '/plans.php';
        $pp = planPriceById($tok['plan_price_id']);
        if (!$pp || (string)$pp['product_key'] !== $productKey) throw new InvalidArgumentException($bad);

        $planLabel = sptDisplayLabel((string)$pp['plan_name'], (string)$pp['term_label']);
        $out = [
            'price_term_id'       => null,
            'price_term_label'    => $planLabel,
            'term_duration_value' => (int)$pp['duration_value'],
            'term_duration_unit'  => (string)$pp['duration_unit'],
            'plan_key'            => (string)$pp['plan_key'],
            'plan_name'           => mb_substr((string)$pp['plan_name'], 0, 100),
            'plan_price_id'       => (int)$pp['id'],
        ] + SPT_PLAN_LINE_NONE;

        if ($tok['kind'] === 'addon') {
            $st = getControlPdo()->prepare("SELECT a.id, a.plan_id, a.name, a.quota, a.units FROM plan_addons a
                                              JOIN plan_addon_prices ap ON ap.addon_id = a.id AND ap.plan_price_id = ?
                                             WHERE a.id = ?");
            $st->execute([(int)$pp['id'], $tok['addon_id']]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a || (int)$a['plan_id'] !== (int)$pp['plan_id']) {
                throw new InvalidArgumentException("The extra chosen for $lineName is not sold with that plan and term. Choose it again.");
            }
            $out['price_term_label'] = sptDisplayLabel((string)$a['name'], $planLabel);
            $out['plan_addon_id']    = (int)$a['id'];
            $out['addon_quota']      = (string)$a['quota'];
            $out['addon_units']      = (int)$a['units'];
        }
        $desc = sptStripTermLine($description);
        $out['description'] = ($desc !== '' ? $desc . "\n" : '') . 'Term: ' . $out['price_term_label'];
        return $out;
    }
}

if (!function_exists('sptAssertPlanLines')) {
    /**
     * Whole-document rules for plan lines, checked after every line is resolved:
     * one plan per document, plan qty 1 (a longer period is a longer term), and
     * every add-on sits next to its own plan line with a whole, positive qty.
     *
     * @param array<int, array{term:array, qty:float|int|string, name:string}> $lines
     * @throws InvalidArgumentException
     */
    function sptAssertPlanLines(array $lines): void
    {
        $planPrice = null;
        foreach ($lines as $l) {
            $t = $l['term'];
            if (empty($t['plan_price_id']) || !empty($t['plan_addon_id'])) continue;
            if ($planPrice !== null) throw new InvalidArgumentException('A document can sell only one plan. Remove the second plan line.');
            if ((float)$l['qty'] != 1.0) {
                throw new InvalidArgumentException("The plan line \"{$l['name']}\" must have quantity 1 — for a longer period choose a longer term instead.");
            }
            $planPrice = (int)$t['plan_price_id'];
        }
        foreach ($lines as $l) {
            $t = $l['term'];
            if (empty($t['plan_addon_id'])) continue;
            if ($planPrice === null || (int)$t['plan_price_id'] !== $planPrice) {
                throw new InvalidArgumentException("The extra \"{$l['name']}\" must be sold with its plan on the same document and the same term.");
            }
            $q = (float)$l['qty'];
            if ($q < 1 || floor($q) != $q) throw new InvalidArgumentException("The quantity of \"{$l['name']}\" must be a whole number (1, 2, 3…).");
        }
    }
}

if (!function_exists('sptEnsurePlanLineColumns')) {
    function sptEnsurePlanLineColumns(PDO $pdo): void
    {
        $cols = [
            'plan_key'      => "ADD COLUMN `plan_key` VARCHAR(64) NULL DEFAULT NULL",
            'plan_name'     => "ADD COLUMN `plan_name` VARCHAR(100) NULL DEFAULT NULL",
            'plan_price_id' => "ADD COLUMN `plan_price_id` INT UNSIGNED NULL DEFAULT NULL",
            'plan_addon_id' => "ADD COLUMN `plan_addon_id` INT UNSIGNED NULL DEFAULT NULL",
            'addon_quota'   => "ADD COLUMN `addon_quota` VARCHAR(20) NULL DEFAULT NULL",
            'addon_units'   => "ADD COLUMN `addon_units` INT NULL DEFAULT NULL",
        ];
        // One ALTER per table (not per column): each ALTER can rebuild the table,
        // and 18 rebuilds per tenant DB pushed the deploy past its SSH timeout.
        // INSTANT makes it a metadata-only change where MySQL supports it.
        foreach (array_keys(SPT_LINE_TABLES) as $t) {
            if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch()) continue;
            $missing = [];
            foreach ($cols as $c => $ddl) {
                if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $missing[$c] = $ddl;
            }
            if (!$missing) continue;
            $sql = "ALTER TABLE `$t` " . implode(', ', $missing);
            try {
                try {
                    $pdo->exec("$sql, ALGORITHM=INSTANT");
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) === 1060) throw $e;
                    $pdo->exec($sql);                                    // INSTANT not supported here
                }
                echo "  + $t: " . implode(', ', array_keys($missing)) . " added\n";
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;   // a concurrent run added one
                sptEnsurePlanLineColumns($pdo);                          // add whatever is still missing
                return;
            }
        }
    }
}

if (!function_exists('sptPlanLineColumnsReady')) {
    function sptPlanLineColumnsReady(PDO $pdo, string $table): bool
    {
        static $ready = [];
        if (!empty($ready[$table])) return true;
        try {
            return $ready[$table] = (bool)$pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE 'addon_units'")->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sptStampLine')) {
    /** Write the resolved term onto a just-inserted line (no-op without a term or before the migration). */
    function sptStampLine(PDO $pdo, string $table, int $lineId, array $resolved): void
    {
        if ($lineId <= 0 || !isset(SPT_LINE_TABLES[$table])) return;
        $isPlan = !empty($resolved['plan_price_id']);
        if (empty($resolved['price_term_id']) && !$isPlan) return;
        if (!sptLineColumnsReady($pdo, $table)) return;
        if ($isPlan && !sptPlanLineColumnsReady($pdo, $table)) {
            throw new RuntimeException('Plan lines need the latest database update. Ask the administrator to run the migrations.');
        }
        $pk = SPT_LINE_TABLES[$table];
        $sql = "UPDATE `$table` SET price_term_id = ?, price_term_label = ?, term_duration_value = ?, term_duration_unit = ?";
        $args = [$resolved['price_term_id'], $resolved['price_term_label'], $resolved['term_duration_value'], $resolved['term_duration_unit']];
        if ($isPlan) {
            $sql .= ", plan_key = ?, plan_name = ?, plan_price_id = ?, plan_addon_id = ?, addon_quota = ?, addon_units = ?";
            array_push($args, $resolved['plan_key'], $resolved['plan_name'], $resolved['plan_price_id'],
                       $resolved['plan_addon_id'], $resolved['addon_quota'], $resolved['addon_units']);
        }
        $args[] = $lineId;
        $pdo->prepare("$sql WHERE `$pk` = ?")->execute($args);
    }
}

if (!function_exists('sptTermOfSalesOrderLine')) {
    /**
     * The term recorded on a sales-order line — an invoice raised from it inherits
     * it. A local term comes back as its id, a platform plan line as its token.
     *
     * @return int|string|null
     */
    function sptTermOfSalesOrderLine(PDO $pdo, int $orderItemId)
    {
        if ($orderItemId <= 0 || !sptLineColumnsReady($pdo, 'sales_order_items')) return null;
        $plans = sptPlanLineColumnsReady($pdo, 'sales_order_items');
        $st = $pdo->prepare("SELECT price_term_id" . ($plans ? ", plan_price_id, plan_addon_id" : "") . " FROM sales_order_items WHERE order_item_id = ?");
        $st->execute([$orderItemId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($plans && ($tok = sptLineToken($r)) !== null) return $tok;
        return !empty($r['price_term_id']) ? (int)$r['price_term_id'] : null;
    }
}
