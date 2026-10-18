<?php
/**
 * core/service_plans.php — optional plans on a service (service_plans_plan.md Phase 2).
 *
 * A service may be sold as plans (e.g. "Starter", "Business"), each with its own
 * price terms and a "what's included" list. A plan's terms are ordinary
 * service_price_terms rows with plan_id set, so quotation / sales order /
 * invoice lines, line_price_terms.js and the vendor bridge keep keying on the
 * term exactly as before. A service with no plans behaves exactly as before.
 *
 * On BJP's own install (core/vendor_context.php) a subscription service can
 * instead be MAPPED to a platform product (control DB, Superadmin → Plans).
 * Its plans are read live from there and are read-only here: sptSave() and
 * spSave() refuse local prices on it (the lock is on the server, not the screen).
 */

require_once __DIR__ . '/service_price_terms.php';
require_once __DIR__ . '/vendor_context.php';

if (!defined('SP_MAX_PLANS')) {
    define('SP_MAX_PLANS', 12);
    define('SP_MAX_ITEMS', 20);
    define('SP_MAX_PLAN_TERMS', 96);   // across all plans of one service
}

if (!function_exists('spEnsureSchema')) {
    function spEnsureSchema(PDO $pdo): void
    {
        sptEnsureSchema($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `service_plans` (
                `plan_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` INT NOT NULL,
                `plan_name`  VARCHAR(100) NOT NULL,
                `tagline`    VARCHAR(255) NULL DEFAULT NULL,
                `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `status`     ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`plan_id`),
                KEY `idx_sp_product` (`product_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `service_plan_items` (
                `item_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plan_id`    INT UNSIGNED NOT NULL,
                `label`      VARCHAR(100) NOT NULL,
                `value`      VARCHAR(100) NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`item_id`),
                KEY `idx_spi_plan` (`plan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $alters = [
            ['service_price_terms', 'plan_id', "ADD COLUMN `plan_id` INT UNSIGNED NULL DEFAULT NULL AFTER `product_id`, ADD KEY `idx_spt_plan` (`plan_id`)"],
            ['vendor_subscription_services', 'platform_product_key', "ADD COLUMN `platform_product_key` VARCHAR(40) NULL DEFAULT NULL, ADD UNIQUE KEY `uq_vss_platform_product` (`platform_product_key`)"],
        ];
        foreach ($alters as [$t, $c, $ddl]) {
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

if (!function_exists('spMappingReady')) {
    function spMappingReady(PDO $pdo): bool
    {
        static $ready = false;
        if (!$ready) {
            try {
                $ready = sptReady($pdo) && (bool)$pdo->query("SHOW COLUMNS FROM `vendor_subscription_services` LIKE 'platform_product_key'")->fetch();
            } catch (Throwable $e) {
                return false;
            }
        }
        return $ready;
    }
}

if (!function_exists('spPlansForProduct')) {
    /**
     * The service's plans, each with `items` and its active `terms`.
     * @return array<int, array{plan_id:int, plan_name:string, tagline:?string, is_popular:bool, status:string, items:array, terms:array}>
     */
    function spPlansForProduct(PDO $pdo, int $productId, bool $activeOnly = true): array
    {
        if (!sptPlanColumnReady($pdo)) return [];
        $st = $pdo->prepare("SELECT plan_id, plan_name, tagline, is_popular, status FROM service_plans
                              WHERE product_id = ? AND status " . ($activeOnly ? "= 'active'" : "<> 'deleted'") . "
                              ORDER BY sort_order, plan_id");
        $st->execute([$productId]);
        $plans = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plans[(int)$p['plan_id']] = [
                'plan_id' => (int)$p['plan_id'], 'plan_name' => $p['plan_name'], 'tagline' => $p['tagline'],
                'is_popular' => (int)$p['is_popular'] === 1, 'status' => $p['status'], 'items' => [], 'terms' => [],
            ];
        }
        if (!$plans) return [];
        $in = implode(',', array_keys($plans));
        foreach ($pdo->query("SELECT plan_id, label, value FROM service_plan_items WHERE plan_id IN ($in) ORDER BY sort_order, item_id") as $i) {
            $plans[(int)$i['plan_id']]['items'][] = ['label' => $i['label'], 'value' => $i['value']];
        }
        foreach (sptForProduct($pdo, $productId) as $t) {
            if ($t['plan_id'] !== null && isset($plans[$t['plan_id']])) $plans[$t['plan_id']]['terms'][] = $t;
        }
        return array_values($plans);
    }
}

if (!function_exists('spProductHasPlans')) {
    function spProductHasPlans(PDO $pdo, int $productId): bool
    {
        if (!sptPlanColumnReady($pdo)) return false;
        $st = $pdo->prepare("SELECT 1 FROM service_plans WHERE product_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$productId]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('spParse')) {
    /**
     * Validate the form's plans. null = not sent (leave plans untouched — older
     * clients, the mobile API). [] = the service has no plans.
     * Each plan's terms go through sptParse(); term names must be unique within a
     * plan; exactly one term across the whole service is the default.
     *
     * @return array<int, array{plan_id:?int, plan_name:string, tagline:?string, is_popular:int, items:array, terms:array}>|null
     * @throws InvalidArgumentException with a message fit for the user
     */
    function spParse($raw): ?array
    {
        if ($raw === null) return null;
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') return [];
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) throw new InvalidArgumentException('Plans could not be read. Please try again.');
        if (count($raw) > SP_MAX_PLANS) throw new InvalidArgumentException('A service can have at most ' . SP_MAX_PLANS . ' plans.');

        $out = []; $seen = []; $popular = 0; $defaults = 0; $termCount = 0; $defaultAt = null;
        foreach (array_values($raw) as $i => $p) {
            if (!is_array($p)) throw new InvalidArgumentException('Plans could not be read. Please try again.');
            $n = $i + 1;
            $name = trim((string)($p['plan_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException("Plan #$n needs a name (e.g. \"Starter\").");
            if (mb_strlen($name) > 100) throw new InvalidArgumentException("Plan #$n: the name is too long (100 characters max).");
            $key = mb_strtolower($name);
            if (isset($seen[$key])) throw new InvalidArgumentException("Two plans are both called \"$name\".");
            $seen[$key] = true;

            $tagline = trim((string)($p['tagline'] ?? ''));
            if (mb_strlen($tagline) > 255) throw new InvalidArgumentException("Plan \"$name\": the short description is too long (255 characters max).");
            $isPopular = !empty($p['is_popular']) ? 1 : 0;
            $popular += $isPopular;

            $items = [];
            $rawItems = $p['items'] ?? [];
            if (!is_array($rawItems)) throw new InvalidArgumentException("Plan \"$name\": what's included could not be read.");
            if (count($rawItems) > SP_MAX_ITEMS) throw new InvalidArgumentException("Plan \"$name\": at most " . SP_MAX_ITEMS . " lines in what's included.");
            foreach (array_values($rawItems) as $it) {
                if (!is_array($it)) throw new InvalidArgumentException("Plan \"$name\": what's included could not be read.");
                $label = trim((string)($it['label'] ?? ''));
                $value = trim((string)($it['value'] ?? ''));
                if ($label === '' && $value === '') continue;   // an empty row the user left
                if ($label === '') throw new InvalidArgumentException("Plan \"$name\": every included line needs a name (e.g. \"Users\").");
                if (mb_strlen($label) > 100 || mb_strlen($value) > 100) throw new InvalidArgumentException("Plan \"$name\": an included line is too long (100 characters max).");
                $items[] = ['label' => $label, 'value' => $value !== '' ? $value : null];
            }

            $rawTerms = $p['terms'] ?? null;
            if (!is_array($rawTerms) || !$rawTerms) throw new InvalidArgumentException("Plan \"$name\" needs at least one price (e.g. 1 Month).");
            $explicit = 0;
            foreach (array_values($rawTerms) as $j => $rt) {
                if (is_array($rt) && !empty($rt['is_default'])) { $explicit++; $defaultAt = [$i, $j]; }
            }
            $defaults += $explicit;
            try {
                $terms = sptParse(array_values($rawTerms));
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Plan \"$name\": " . $e->getMessage());
            }
            foreach ($terms as &$t) $t['is_default'] = 0;
            unset($t);
            $termCount += count($terms);

            $pid = isset($p['plan_id']) && (int)$p['plan_id'] > 0 ? (int)$p['plan_id'] : null;
            $out[] = ['plan_id' => $pid, 'plan_name' => $name, 'tagline' => $tagline !== '' ? $tagline : null,
                      'is_popular' => $isPopular, 'items' => $items, 'terms' => $terms];
        }
        if ($popular > 1) throw new InvalidArgumentException('Only one plan can be marked "Most popular".');
        if ($defaults > 1) throw new InvalidArgumentException('Only one price across all plans can be the default.');
        if ($termCount > SP_MAX_PLAN_TERMS) throw new InvalidArgumentException('A service can have at most ' . SP_MAX_PLAN_TERMS . ' prices across all its plans.');
        if ($out) {
            [$pi, $ti] = $defaultAt ?? [0, 0];
            $out[$pi]['terms'][$ti]['is_default'] = 1;
        }
        return $out;
    }
}

if (!function_exists('spTermsUsed')) {
    /** Has a document line recorded any term (active or not) of this plan? */
    function spTermsUsed(PDO $pdo, int $planId): bool
    {
        $st = $pdo->prepare("SELECT term_id FROM service_price_terms WHERE plan_id = ? AND status <> 'deleted'");
        $st->execute([$planId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) if (sptTermUsed($pdo, (int)$tid)) return true;
        return false;
    }
}

if (!function_exists('spRetirePlans')) {
    /** Retire every plan of the service not in $keepIds: inactive if a document used one of its terms, else deleted. */
    function spRetirePlans(PDO $pdo, int $productId, array $keepIds, int $userId): void
    {
        $st = $pdo->prepare("SELECT plan_id FROM service_plans WHERE product_id = ? AND status <> 'deleted'");
        $st->execute([$productId]);
        $upd = $pdo->prepare("UPDATE service_plans SET status = ?, is_popular = 0, updated_by = ? WHERE plan_id = ?");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            if (isset($keepIds[$id])) continue;
            $upd->execute([spTermsUsed($pdo, $id) ? 'inactive' : 'deleted', $userId, $id]);
        }
    }
}

if (!function_exists('spSave')) {
    /**
     * Make the service's plans match $plans (from spParse). Joins the caller's
     * transaction. With plans, the service's terms become exactly the plans'
     * terms (any plain terms are retired by sptSave). With [], only the plans are
     * retired — the caller then saves plain terms as before.
     *
     * @return array{count:int, default_price:?float, min_price:?float}
     * @throws InvalidArgumentException
     */
    function spSave(PDO $pdo, int $productId, array $plans, int $userId): array
    {
        if (!sptPlanColumnReady($pdo)) {
            if (!$plans) return ['count' => 0, 'default_price' => null, 'min_price' => null];
            throw new InvalidArgumentException('Plans are not available yet — the database update has not run. Try again shortly.');
        }
        if ($plans) sptAssertNotMapped($pdo, $productId);

        $existing = [];
        $st = $pdo->prepare("SELECT plan_id FROM service_plans WHERE product_id = ? AND status <> 'deleted'");
        $st->execute([$productId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $existing[(int)$id] = true;

        $upd = $pdo->prepare("UPDATE service_plans SET plan_name = ?, tagline = ?, is_popular = ?, sort_order = ?, status = 'active', updated_by = ?
                               WHERE plan_id = ? AND product_id = ?");
        $ins = $pdo->prepare("INSERT INTO service_plans (product_id, plan_name, tagline, is_popular, sort_order, status, created_by)
                              VALUES (?, ?, ?, ?, ?, 'active', ?)");
        $delItems = $pdo->prepare("DELETE FROM service_plan_items WHERE plan_id = ?");
        $insItem = $pdo->prepare("INSERT INTO service_plan_items (plan_id, label, value, sort_order) VALUES (?, ?, ?, ?)");

        $keep = []; $flat = [];
        foreach ($plans as $i => $p) {
            if ($p['plan_id'] !== null) {
                if (!isset($existing[$p['plan_id']])) {
                    throw new InvalidArgumentException('One of the plans no longer exists. Reload the page and try again.');
                }
                $upd->execute([$p['plan_name'], $p['tagline'], $p['is_popular'], $i, $userId, $p['plan_id'], $productId]);
                $planId = $p['plan_id'];
            } else {
                $ins->execute([$productId, $p['plan_name'], $p['tagline'], $p['is_popular'], $i, $userId]);
                $planId = (int)$pdo->lastInsertId();
            }
            $keep[$planId] = true;
            $delItems->execute([$planId]);
            foreach ($p['items'] as $k => $it) $insItem->execute([$planId, $it['label'], $it['value'], $k]);
            foreach ($p['terms'] as $t) {
                $t['plan_id'] = $planId;
                $t['sort_order'] = count($flat);
                $flat[] = $t;
            }
        }

        $sum = ['count' => 0, 'default_price' => null, 'min_price' => null];
        if ($plans) $sum = sptSave($pdo, $productId, $flat, $userId);
        spRetirePlans($pdo, $productId, $keep, $userId);
        return $sum;
    }
}

if (!function_exists('spListSummary')) {
    /** For the services list: [product_id => ['plans' => active plan count, 'platform_key' => ?string]]. */
    function spListSummary(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !sptPlanColumnReady($pdo)) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($pdo->query("SELECT product_id, COUNT(*) AS n FROM service_plans
                               WHERE status = 'active' AND product_id IN ($in) GROUP BY product_id") as $r) {
            $out[(int)$r['product_id']] = ['plans' => (int)$r['n'], 'platform_key' => null];
        }
        if (spMappingReady($pdo)) {
            foreach ($pdo->query("SELECT product_id, platform_product_key FROM vendor_subscription_services
                                   WHERE platform_product_key IS NOT NULL AND product_id IN ($in)") as $r) {
                $out[(int)$r['product_id']] = ['plans' => $out[(int)$r['product_id']]['plans'] ?? 0,
                                               'platform_key' => (string)$r['platform_product_key']];
            }
        }
        return $out;
    }
}

/* ── Keep platform subscriptions out of POS (service_plans_plan.md Phase 6) ── */

if (!function_exists('spPosExcludeSql')) {
    /**
     * A WHERE fragment that hides services sold as a platform product: a POS sale
     * would never extend the company's subscription — only a paid invoice does.
     * '' when the mapping does not exist on this install.
     */
    function spPosExcludeSql(PDO $pdo, string $alias = 'p'): string
    {
        if (!spMappingReady($pdo)) return '';
        return " AND NOT EXISTS (SELECT 1 FROM vendor_subscription_services vss_pos
                                  WHERE vss_pos.product_id = $alias.product_id AND vss_pos.platform_product_key IS NOT NULL)";
    }
}

if (!function_exists('spPlatformMappedNames')) {
    /** Of these product ids, the ones sold as a platform product: [product_id => product_name]. */
    function spPlatformMappedNames(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !spMappingReady($pdo)) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($pdo->query("SELECT v.product_id, COALESCE(p.product_name, CONCAT('#', v.product_id)) AS product_name
                                FROM vendor_subscription_services v LEFT JOIN products p ON p.product_id = v.product_id
                               WHERE v.platform_product_key IS NOT NULL AND v.product_id IN ($in)") as $r) {
            $out[(int)$r['product_id']] = (string)$r['product_name'];
        }
        return $out;
    }
}

/* ── Platform products (BJP's own install only) ───────────────────────────── */

if (!function_exists('spPlatformProducts')) {
    /** Active platform products from the control DB: [product_key => name]. Empty when unavailable. */
    function spPlatformProducts(): array
    {
        try {
            require_once __DIR__ . '/plans.php';
            $out = [];
            foreach (listPlatformProducts(true) as $p) $out[(string)$p['product_key']] = (string)$p['name'];
            return $out;
        } catch (Throwable $e) {
            error_log('spPlatformProducts: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spPlatformPlans')) {
    /**
     * The plans Superadmin sells under one platform product, read LIVE (never
     * copied): every active plan (public or not — BJP may sell a manual one)
     * that has at least one active price. Display shape for BJP's books.
     */
    function spPlatformPlans(string $productKey): array
    {
        if ($productKey === '') return [];
        try {
            require_once __DIR__ . '/plans.php';
            if (!planCatalogReady()) return [];
            $st = getControlPdo()->prepare("SELECT * FROM plans WHERE product_key = ? AND is_active = 1 AND plan_key <> 'blank'
                                             ORDER BY tier_rank, sort_order, name");
            $st->execute([$productKey]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $plan) {
                $shape = planOfferShape($plan);
                if (!$shape['prices']) continue;
                $shape['is_public'] = (int)$plan['is_public'] === 1;
                $out[] = $shape;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('spPlatformPlans: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spPlatformLineOptions')) {
    /**
     * The "term" choices a document line for a mapped service offers (Phase 5, D9):
     * one option per active plan price ("Pro — 1 year", token p{id}) and one per
     * active add-on price ("Extra shop — Pro — 1 year", token a{addon}-{price}).
     * Same keys line_price_terms.js already reads (term_id, display_label, price,
     * is_default), plus group/kind/plan_price_id/addon_key for grouping and
     * locking add-ons to their plan's term.
     *
     * @param bool        $publicOnly  the company's self-service catalogue: public plans only…
     * @param string|null $alsoPlanKey …plus the plan the company is on now, so it can renew it
     */
    function spPlatformLineOptions(string $productKey, bool $publicOnly = false, ?string $alsoPlanKey = null): array
    {
        if ($productKey === '') return [];
        try {
            require_once __DIR__ . '/plans.php';
            if (!planCatalogReady()) return [];
            $st = getControlPdo()->prepare("SELECT * FROM plans WHERE product_key = ? AND is_active = 1 AND plan_key <> 'blank'
                                             ORDER BY tier_rank, sort_order, name");
            $st->execute([$productKey]);
            $plans = []; $addons = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $plan) {
                if ($publicOnly && (int)$plan['is_public'] !== 1 && (string)$plan['plan_key'] !== (string)$alsoPlanKey) continue;
                $prices = planPrices((int)$plan['id'], true);
                $planAddons = planAddons((int)$plan['id'], true);
                foreach ($prices as $pp) {
                    $label = sptDisplayLabel((string)$plan['name'], (string)$pp['term_label']);
                    $plans[] = [
                        'term_id' => 'p' . (int)$pp['id'], 'kind' => 'plan', 'group' => (string)$plan['name'],
                        'display_label' => $label, 'term_label' => (string)$pp['term_label'],
                        'price' => (float)$pp['price_tzs'], 'is_default' => (int)$pp['is_default'] === 1,
                        'months' => (int)$pp['months'], 'plan_key' => (string)$plan['plan_key'],
                        'plan_name' => (string)$plan['name'], 'plan_price_id' => (int)$pp['id'],
                        'description' => null,
                    ];
                    foreach ($planAddons as $a) {
                        if (!isset($a['prices'][(int)$pp['id']])) continue;
                        $addons[] = [
                            'term_id' => 'a' . (int)$a['id'] . '-' . (int)$pp['id'], 'kind' => 'addon',
                            'group' => (string)$plan['name'] . ' — extras',
                            'display_label' => sptDisplayLabel((string)$a['name'], $label), 'term_label' => (string)$pp['term_label'],
                            'price' => (float)$a['prices'][(int)$pp['id']], 'is_default' => false,
                            'months' => (int)$pp['months'], 'plan_key' => (string)$plan['plan_key'],
                            'plan_name' => (string)$plan['name'], 'plan_price_id' => (int)$pp['id'],
                            'addon_key' => (string)$a['addon_key'], 'addon_name' => (string)$a['name'],
                            'quota' => (string)$a['quota'], 'units' => (int)$a['units'], 'description' => null,
                        ];
                    }
                }
            }
            // Only the first plan's default is the line default; plans first, extras after.
            $seen = false;
            foreach ($plans as &$o) { if ($o['is_default'] && !$seen) { $seen = true; } else { $o['is_default'] = false; } }
            unset($o);
            return array_merge($plans, $addons);
        } catch (Throwable $e) {
            error_log('spPlatformLineOptions: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spPlatformPriceSummary')) {
    /** selling_price / min_selling_price for a mapped service: the first plan's default price and the cheapest price. */
    function spPlatformPriceSummary(array $platformPlans): array
    {
        $default = null; $min = null;
        foreach ($platformPlans as $p) {
            foreach ($p['prices'] as $pr) {
                $v = (float)$pr['price_tzs'];
                $min = $min === null ? $v : min($min, $v);
                if ($default === null && $pr['is_default']) $default = $v;
            }
        }
        if ($default === null) $default = $min;
        return ['default_price' => $default, 'min_price' => $min];
    }
}

if (!function_exists('spSetPlatformProduct')) {
    /**
     * Map (or unmap, $key = null) a subscription service to a platform product.
     * Callers gate on isPlatformVendorContext() && isAdmin(). The service must
     * already be a subscription (vendor_subscription_services row). Mapping
     * retires the service's own plans and terms — from now on its prices come
     * from Superadmin. One platform product is sold by one service only.
     *
     * @throws InvalidArgumentException
     */
    function spSetPlatformProduct(PDO $pdo, int $productId, ?string $key, int $userId): void
    {
        if (!spMappingReady($pdo)) {
            if ($key === null) return;
            throw new InvalidArgumentException('Linking a service to a platform product is not available yet — the database update has not run.');
        }
        if ($key !== null) {
            $products = spPlatformProducts();
            if (!isset($products[$key])) throw new InvalidArgumentException('The chosen platform product is not available.');
            $st = $pdo->prepare("SELECT v.product_id, p.product_name FROM vendor_subscription_services v
                                   LEFT JOIN products p ON p.product_id = v.product_id
                                  WHERE v.platform_product_key = ? AND v.product_id <> ?");
            $st->execute([$key, $productId]);
            if ($other = $st->fetch(PDO::FETCH_ASSOC)) {
                throw new InvalidArgumentException('"' . $products[$key] . '" is already sold by the service "' . ($other['product_name'] ?? ('#' . $other['product_id'])) . '". Unlink it there first.');
            }
            if (!vssIsSubscription($pdo, $productId)) {
                throw new InvalidArgumentException('Only a system subscription service can sell a platform product.');
            }
        }
        $pdo->prepare("UPDATE vendor_subscription_services SET platform_product_key = ? WHERE product_id = ?")
            ->execute([$key, $productId]);
        if ($key !== null) {
            // Local prices would compete with Superadmin's: retire them (kept as inactive if a document used them).
            spRetirePlans($pdo, $productId, [], $userId);
            sptSave($pdo, $productId, [], $userId);
        }
    }
}

/* ── The service form's save (create_nip_product.php / update_nip_product.php) ── */

if (!function_exists('spSyncSellingPrice')) {
    /** A priced service keeps selling_price = its default price and min = its cheapest (POS and lists read one number). */
    function spSyncSellingPrice(PDO $pdo, int $productId, ?string $platformKey): void
    {
        if ($platformKey !== null) {
            $sum = spPlatformPriceSummary(spPlatformPlans($platformKey));
            $def = $sum['default_price']; $min = $sum['min_price'];
        } else {
            $active = sptForProduct($pdo, $productId);
            if (!$active) return;
            $def = (float)$active[0]['price']; $min = null;
            foreach ($active as $t) {
                if ((int)$t['is_default'] === 1) $def = (float)$t['price'];
                $min = $min === null ? (float)$t['price'] : min($min, (float)$t['price']);
            }
        }
        if ($def === null) return;   // control DB unreachable: leave the stored price
        $pdo->prepare("UPDATE products SET selling_price = ?, min_selling_price = ? WHERE product_id = ?")
            ->execute([$def, $min, $productId]);
    }
}

if (!function_exists('spSaveFromForm')) {
    /**
     * Everything price-related the service form sends, in one place, inside the
     * caller's transaction. $terms / $plans come from sptParse() / spParse()
     * (null = the form did not send that field).
     *
     * Rules: plain terms OR plans, never both; a mapped service takes neither
     * (its prices are Superadmin's); only an admin on BJP's install maps; a
     * mapped service is always a subscription.
     *
     * @throws InvalidArgumentException
     */
    function spSaveFromForm(PDO $pdo, int $productId, ?array $terms, ?array $plans, array $post, int $userId, bool $isNew): void
    {
        $vendor = isPlatformVendorContext();

        $mapSent = $vendor && !empty($post['platform_product_sent']);
        if ($mapSent && !isAdmin()) {
            throw new InvalidArgumentException('Only an admin can choose which platform product a service sells.');
        }
        $currentKey = $isNew ? null : vssPlatformProduct($pdo, $productId);
        $newKey = $currentKey;
        if ($mapSent) {
            $k = trim((string)($post['platform_product_key'] ?? ''));
            $newKey = $k !== '' ? $k : null;
        }

        // On create, a ticked box counts even without the marker (as create_nip_product.php always did).
        $subSent = $vendor && (!empty($post['subscription_flag_sent']) || ($isNew && !empty($post['is_subscription'])));
        $subOn = $subSent ? !empty($post['is_subscription']) : (!$isNew && vssIsSubscription($pdo, $productId));

        if ($plans && $terms) {
            throw new InvalidArgumentException('Use either price terms or plans for a service, not both. Put the terms inside a plan.');
        }
        if ($newKey !== null) {
            if ($plans || $terms) {
                throw new InvalidArgumentException("This service's plans and prices are managed in Superadmin → Plans. They cannot be changed here.");
            }
            if ($subSent && !$subOn) {
                throw new InvalidArgumentException('A service that sells a platform product is always a system subscription. Set "This service sells" to none first.');
            }
        } elseif ($plans === null && $terms !== null && !$isNew && spProductHasPlans($pdo, $productId)) {
            // A form that knows only plain terms would wipe the plans' prices.
            throw new InvalidArgumentException('This service is priced by plans. Reload the page and edit its plans.');
        }

        if ($newKey === null) {
            if ($currentKey !== null) spSetPlatformProduct($pdo, $productId, null, $userId);   // unlink first: the lock lifts
            if ($plans !== null) spSave($pdo, $productId, $plans, $userId);
            if (!$plans && $terms !== null && sptReady($pdo)) sptSave($pdo, $productId, $terms, $userId);
            spSyncSellingPrice($pdo, $productId, null);
            if ($subSent) {
                vssSet($pdo, $productId, $subOn, sptForProduct($pdo, $productId), $userId);
                if ($subOn) vssSetContractTemplate($pdo, $productId, (int)($post['contract_template_id'] ?? 0));
            }
            return;
        }

        if (!vssIsSubscription($pdo, $productId)) vssSet($pdo, $productId, true, [], $userId, true);
        if ($newKey !== $currentKey) spSetPlatformProduct($pdo, $productId, $newKey, $userId);
        spSyncSellingPrice($pdo, $productId, $newKey);
        if ($subSent) vssSetContractTemplate($pdo, $productId, (int)($post['contract_template_id'] ?? 0));
    }
}
