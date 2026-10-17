<?php
/**
 * core/plans.php
 * ----------------
 * Reusable pricing/feature bundles a superadmin can apply to a tenant in one
 * click, instead of hand-setting features and quotas individually every time
 * — the professional-SaaS-panel piece BMS's superadmin was missing (see
 * superadmin_workdo_gap_plan.md, Phase C).
 *
 * Deliberately NOT a new enforcement mechanism. applyPlanToTenant() is a thin
 * orchestrator over setTenantFeatures() and setTenantQuotas() — Phases 11/12,
 * already shipped, tested, and enforced exactly as they are today. A tenant
 * stays fully, independently editable after a plan is applied: there is no
 * `plan_id` column tying a tenant to a plan's live definition, only
 * `tenants.plan` — set to the plan's plan_key (a stable identifier, not the
 * display name, so renaming a plan later never orphans a tenant's record of
 * which one it's on) at the moment the plan was applied.
 *
 * Public API:
 *   listPlans(bool $activeOnly = false): array
 *   getPlan(int $id): ?array
 *   getPlanByKey(string $key): ?array
 *   planFeatureKeys(int $id): array
 *   createPlan(array $in): array{ok:bool, error:?string, id:?int}
 *   updatePlan(int $id, array $in): array{ok:bool, error:?string}
 *   setPlanActive(int $id, bool $active): array{ok:bool, error:?string}
 *   applyPlanToTenant(int $tenantId, int $planId): array{ok:bool, error:?string}
 *
 * Service plans (service_plans_plan.md Phase 1) — what BJP sells:
 *   planCatalogReady(): bool
 *   listPlatformProducts(bool $activeOnly = false): array
 *   planPrices(int $planId, bool $activeOnly = false): array
 *   planPriceById(int $id): ?array
 *   planItems(int $planId): array
 *   planAddons(int $planId, bool $activeOnly = false): array
 *   listPublicOffers(?string $productKey = null, bool $registrationOnly = false): array
 *   planOfferPrice(string $planKey, string $termKey, bool $publicOnly = true): ?array
 *
 * createPlan()/updatePlan() also take product_key, tagline, max_shops,
 * is_public, is_popular, tier_rank, prices[], items[], addons[] — each only
 * when present in $in, so older callers keep working unchanged.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/feature_registry.php';
require_once __DIR__ . '/tenant_admin.php';
require_once __DIR__ . '/tenant_lifecycle_policy.php';   // controlColumnExists

if (!function_exists('planTablesReady')) {
    /** Same pattern as featureTablesReady(): report "not set up here yet" distinctly from a real failure. */
    function planTablesReady(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            getControlPdo()->query('SELECT 1 FROM plans LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('planSlug')) {
    /** name -> a URL/identifier-safe lowercase-dash slug. Never empty. */
    function planSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'plan';
    }
}

if (!function_exists('listPlans')) {
    /**
     * Every plan, with how many LIVE tenants currently show it — matched by
     * plan_key (the stable id), not by name, so a rename never breaks the count.
     */
    function listPlans(bool $activeOnly = false): array
    {
        $sql = "SELECT p.*,
                       (SELECT COUNT(*) FROM tenants t
                         WHERE t.plan = p.plan_key AND t.status != 'deleted') AS tenants_using
                FROM plans p";
        if ($activeOnly) $sql .= " WHERE p.is_active = 1";
        $sql .= " ORDER BY p.sort_order, p.name";
        return getControlPdo()->query($sql)->fetchAll();
    }
}

if (!function_exists('getPlan')) {
    function getPlan(int $id): ?array
    {
        $st = getControlPdo()->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('getPlanByKey')) {
    function getPlanByKey(string $key): ?array
    {
        if ($key === '') return null;
        $st = getControlPdo()->prepare("SELECT * FROM plans WHERE plan_key = ? LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('planFeatureKeys')) {
    function planFeatureKeys(int $id): array
    {
        $st = getControlPdo()->prepare("SELECT feature_key FROM plan_features WHERE plan_id = ?");
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
}

/** Shared validation for createPlan()/updatePlan() — returns an error string, or null if valid. */
if (!function_exists('planInputError')) {
    function planInputError(string $name, ?int $maxUsers, ?int $maxStorageMb): ?string
    {
        if ($name === '' || mb_strlen($name) > 100) {
            return 'Enter a plan name (up to 100 characters).';
        }
        if ($maxUsers !== null && $maxUsers < 1) {
            return 'Max users must be at least 1, or left blank for unlimited.';
        }
        if ($maxStorageMb !== null && $maxStorageMb < 1) {
            return 'Max storage must be at least 1 MB, or left blank for unlimited.';
        }
        return null;
    }
}

// ── Service plans: products, prices, items, add-ons ─────────────────────────

if (!function_exists('planCatalogReady')) {
    /** The control DB has the service-plan tables (operator ran scripts/setup_control_db.php). */
    function planCatalogReady(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            getControlPdo()->query('SELECT product_key, is_public, tier_rank, max_shops FROM plans LIMIT 1');
            getControlPdo()->query('SELECT 1 FROM plan_prices LIMIT 1');
            getControlPdo()->query('SELECT 1 FROM plan_addon_prices LIMIT 1');
            getControlPdo()->query('SELECT 1 FROM platform_products LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('listPlatformProducts')) {
    function listPlatformProducts(bool $activeOnly = false): array
    {
        if (!planCatalogReady()) return [];
        $sql = "SELECT * FROM platform_products" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, name";
        return getControlPdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('planTermMonths')) {
    /** A term's length in months: 6 month → 6, 1 year → 12. */
    function planTermMonths(int $value, string $unit): int
    {
        return $unit === 'year' ? $value * 12 : $value;
    }
}

if (!function_exists('planTermKey')) {
    /** The public, id-free name of a term: "6m", "1y". */
    function planTermKey(int $value, string $unit): string
    {
        return $value . ($unit === 'year' ? 'y' : 'm');
    }
}

if (!function_exists('planDefaultTermLabel')) {
    function planDefaultTermLabel(int $value, string $unit): string
    {
        if ($unit === 'year') return $value === 1 ? '1 year' : "$value years";
        return $value === 1 ? '1 month' : "$value months";
    }
}

if (!function_exists('planPrices')) {
    /** A plan's prices, shortest term first. Each row gains `months` and `term_key`. */
    function planPrices(int $planId, bool $activeOnly = false): array
    {
        if (!planCatalogReady()) return [];
        $st = getControlPdo()->prepare("SELECT * FROM plan_prices WHERE plan_id = ?"
            . ($activeOnly ? " AND is_active = 1" : "")
            . " ORDER BY (CASE duration_unit WHEN 'year' THEN duration_value * 12 ELSE duration_value END), id");
        $st->execute([$planId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['months']   = planTermMonths((int)$r['duration_value'], (string)$r['duration_unit']);
            $r['term_key'] = planTermKey((int)$r['duration_value'], (string)$r['duration_unit']);
        }
        return $rows;
    }
}

if (!function_exists('planPriceById')) {
    /**
     * One price with its plan, active or not — issued documents keep pointing
     * at a price after it is retired, so this must still resolve it.
     */
    function planPriceById(int $id): ?array
    {
        if ($id <= 0 || !planCatalogReady()) return null;
        $st = getControlPdo()->prepare("
            SELECT pp.*, p.plan_key, p.name AS plan_name, p.product_key, p.tier_rank,
                   p.is_active AS plan_is_active, p.is_public AS plan_is_public
              FROM plan_prices pp JOIN plans p ON p.id = pp.plan_id
             WHERE pp.id = ? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['months']   = planTermMonths((int)$r['duration_value'], (string)$r['duration_unit']);
        $r['term_key'] = planTermKey((int)$r['duration_value'], (string)$r['duration_unit']);
        return $r;
    }
}

if (!function_exists('planItems')) {
    function planItems(int $planId): array
    {
        if (!planCatalogReady()) return [];
        $st = getControlPdo()->prepare("SELECT id, label, value, sort_order FROM plan_items WHERE plan_id = ? ORDER BY sort_order, id");
        $st->execute([$planId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('planAddons')) {
    /** Each add-on gains `prices` => [plan_price_id => price_tzs]. */
    function planAddons(int $planId, bool $activeOnly = false): array
    {
        if (!planCatalogReady()) return [];
        $pdo = getControlPdo();
        $st = $pdo->prepare("SELECT * FROM plan_addons WHERE plan_id = ?" . ($activeOnly ? " AND is_active = 1" : "") . " ORDER BY sort_order, id");
        $st->execute([$planId]);
        $addons = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$addons) return [];
        $pst = $pdo->prepare("SELECT plan_price_id, price_tzs FROM plan_addon_prices WHERE addon_id = ?");
        foreach ($addons as &$a) {
            $pst->execute([(int)$a['id']]);
            $a['prices'] = [];
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) $a['prices'][(int)$p['plan_price_id']] = (int)$p['price_tzs'];
        }
        return $addons;
    }
}

if (!function_exists('planCatalogNormalize')) {
    /**
     * Validate and normalise the catalogue part of a plan save. Everything the
     * browser sends is re-checked here; nothing it sends is trusted.
     *
     * @param array $plan     the existing plan row, or null on create
     * @return array{error:?string, fields:array, prices:?array, items:?array, addons:?array}
     */
    function planCatalogNormalize(array $in, ?array $plan): array
    {
        $out = ['error' => null, 'fields' => [], 'prices' => null, 'items' => null, 'addons' => null];
        $fail = function (string $m) use (&$out) { $out['error'] = $m; return $out; };
        $isBlank = $plan && $plan['plan_key'] === 'blank';
        $planId  = $plan ? (int)$plan['id'] : 0;

        if (array_key_exists('product_key', $in)) {
            $pk = trim((string)$in['product_key']);
            if ($pk === '') {
                $out['fields']['product_key'] = null;
            } else {
                $st = getControlPdo()->prepare("SELECT 1 FROM platform_products WHERE product_key = ?");
                $st->execute([$pk]);
                if (!$st->fetchColumn()) return $fail('Choose a product from the list.');
                $out['fields']['product_key'] = $pk;
            }
        }
        if (array_key_exists('tagline', $in)) {
            $tg = trim((string)$in['tagline']);
            if (mb_strlen($tg) > 150) return $fail('The tagline can be up to 150 characters.');
            $out['fields']['tagline'] = $tg !== '' ? $tg : null;
        }
        if (array_key_exists('max_shops', $in)) {
            $ms = $in['max_shops'];
            if ($ms !== null && (!is_int($ms) || $ms < 1)) return $fail('Max shops must be at least 1, or left blank for unlimited.');
            $out['fields']['max_shops'] = $ms;
        }
        if (array_key_exists('tier_rank', $in)) {
            $tr = (int)$in['tier_rank'];
            if ($tr < 0 || $tr > 1000) return $fail('Tier must be between 0 and 1000.');
            $out['fields']['tier_rank'] = $tr;
        }
        foreach (['is_public', 'is_popular'] as $flag) {
            if (array_key_exists($flag, $in)) $out['fields'][$flag] = !empty($in[$flag]) ? 1 : 0;
        }
        if ($isBlank && (!empty($out['fields']['is_public']) || !empty($out['fields']['product_key']))) {
            return $fail('The reserved blank plan can never be sold.');
        }

        // ── Prices ──
        $existingPrices = [];
        if ($planId) foreach (planPrices($planId) as $p) $existingPrices[(int)$p['id']] = $p;
        if (array_key_exists('prices', $in)) {
            $prices = []; $seenMonths = [];
            foreach ((array)$in['prices'] as $row) {
                $row   = (array)$row;
                $id    = (int)($row['id'] ?? 0);
                $value = (int)($row['duration_value'] ?? 0);
                $unit  = (string)($row['duration_unit'] ?? '');
                $price = $row['price_tzs'] ?? '';
                $price = is_int($price) ? $price : (ctype_digit(str_replace([',', ' '], '', (string)$price)) ? (int)str_replace([',', ' '], '', (string)$price) : -1);
                $active = !array_key_exists('is_active', $row) || !empty($row['is_active']);

                if ($id > 0) {
                    if (!isset($existingPrices[$id])) return $fail('A price does not belong to this plan. Reload the page and try again.');
                    $ex = $existingPrices[$id];
                    if ($value !== (int)$ex['duration_value'] || $unit !== (string)$ex['duration_unit']) {
                        return $fail('A term\'s length cannot be changed once saved. Remove it and add a new term instead.');
                    }
                }
                if (!in_array($unit, ['month', 'year'], true)) return $fail('Each price needs a term in months or years.');
                if ($unit === 'month' && ($value < 1 || $value > 120)) return $fail('A term in months must be 1 to 120.');
                if ($unit === 'year' && ($value < 1 || $value > 10)) return $fail('A term in years must be 1 to 10.');
                if ($price < 1 || $price > 1000000000) return $fail('Each price must be a whole amount in TZS greater than 0.');
                $label = trim((string)($row['term_label'] ?? ''));
                if ($label === '') $label = planDefaultTermLabel($value, $unit);
                if (mb_strlen($label) > 60) return $fail('A term name can be up to 60 characters.');
                $months = planTermMonths($value, $unit);
                if ($active) {
                    if (isset($seenMonths[$months])) return $fail('Two prices have the same length (' . $months . ' months). Keep one.');
                    $seenMonths[$months] = true;
                }
                $prices[] = ['id' => $id, 'term_label' => $label, 'duration_value' => $value, 'duration_unit' => $unit,
                             'price_tzs' => $price, 'is_default' => !empty($row['is_default']) && $active ? 1 : 0,
                             'is_active' => $active ? 1 : 0, 'months' => $months,
                             'term_key' => planTermKey($value, $unit)];
            }
            $active = array_filter($prices, fn($p) => $p['is_active']);
            if (count($active) === 1) {
                foreach ($prices as &$p) if ($p['is_active']) $p['is_default'] = 1;
                unset($p);
            } elseif ($active) {
                $defaults = count(array_filter($active, fn($p) => $p['is_default']));
                if ($defaults !== 1) return $fail('Mark exactly one price as the default.');
            }
            $out['prices'] = $prices;
        }
        $activePriceCount = $out['prices'] !== null
            ? count(array_filter($out['prices'], fn($p) => $p['is_active']))
            : count(array_filter($existingPrices, fn($p) => (int)$p['is_active'] === 1));

        $isPublic   = array_key_exists('is_public', $out['fields']) ? (bool)$out['fields']['is_public'] : (bool)($plan['is_public'] ?? false);
        $productKey = array_key_exists('product_key', $out['fields']) ? $out['fields']['product_key'] : ($plan['product_key'] ?? null);
        if ($isPublic && !$productKey) return $fail('A public plan must belong to a product.');
        if ($isPublic && $activePriceCount === 0) return $fail('A public plan needs at least one price.');
        if (!empty($out['fields']['is_popular']) && !$isPublic) $out['fields']['is_popular'] = 0;

        // ── What's included ──
        if (array_key_exists('items', $in)) {
            $items = [];
            foreach ((array)$in['items'] as $row) {
                $row = (array)$row;
                $label = trim((string)($row['label'] ?? ''));
                $value = trim((string)($row['value'] ?? ''));
                if ($label === '' && $value === '') continue;
                if ($label === '') return $fail('Each included line needs a name.');
                if (mb_strlen($label) > 100 || mb_strlen($value) > 100) return $fail('An included line can be up to 100 characters.');
                $items[] = ['label' => $label, 'value' => $value !== '' ? $value : null];
            }
            if (count($items) > 30) return $fail('A plan can list up to 30 included lines.');
            $out['items'] = $items;
        }

        // ── Add-ons ──
        if (array_key_exists('addons', $in)) {
            $existingAddons = [];
            if ($planId) foreach (planAddons($planId) as $a) $existingAddons[(int)$a['id']] = $a;
            $termKeys = [];
            $priceRows = $out['prices'] ?? array_values($existingPrices);
            foreach ($priceRows as $p) if ((int)$p['is_active'] === 1) $termKeys[$p['term_key']] = true;
            $maxUsers = array_key_exists('max_users', $in) ? $in['max_users'] : ($plan['max_users'] ?? null);
            $maxShops = array_key_exists('max_shops', $out['fields']) ? $out['fields']['max_shops'] : ($plan['max_shops'] ?? null);

            $addons = []; $seenKeys = [];
            foreach ((array)$in['addons'] as $row) {
                $row = (array)$row;
                $id = (int)($row['id'] ?? 0);
                if ($id > 0 && !isset($existingAddons[$id])) return $fail('An add-on does not belong to this plan. Reload the page and try again.');
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 100) return $fail('Each add-on needs a name (up to 100 characters).');
                $quota = (string)($row['quota'] ?? '');
                if (!in_array($quota, ['max_shops', 'max_users'], true)) return $fail('Each add-on must raise shops or users.');
                $units = (int)($row['units'] ?? 1);
                if ($units < 1 || $units > 1000) return $fail('An add-on must add 1 to 1000 units.');
                $active = !array_key_exists('is_active', $row) || !empty($row['is_active']);
                if ($active && $quota === 'max_shops' && $maxShops === null) return $fail('"' . $name . '": this plan already has unlimited shops.');
                if ($active && $quota === 'max_users' && $maxUsers === null) return $fail('"' . $name . '": this plan already has unlimited users.');
                $key = $id > 0 ? (string)$existingAddons[$id]['addon_key'] : planSlug($name);
                if (isset($seenKeys[$key])) return $fail('Two add-ons have the same name.');
                $seenKeys[$key] = true;

                $prices = [];
                foreach ((array)($row['prices'] ?? []) as $tk => $amount) {
                    $amount = str_replace([',', ' '], '', (string)$amount);
                    if ($amount === '') continue;
                    if (!isset($termKeys[(string)$tk])) return $fail('"' . $name . '" has a price for a term the plan does not offer.');
                    if (!ctype_digit($amount) || (int)$amount < 1 || (int)$amount > 1000000000) return $fail('"' . $name . '": each price must be a whole amount greater than 0.');
                    $prices[(string)$tk] = (int)$amount;
                }
                if ($active && !$prices) return $fail('"' . $name . '" needs a price for at least one term.');
                $addons[] = ['id' => $id, 'addon_key' => $key, 'name' => $name, 'quota' => $quota,
                             'units' => $units, 'is_active' => $active ? 1 : 0, 'prices' => $prices];
            }
            // A plan key from a new add-on must not collide with a retired one.
            foreach ($addons as &$a) {
                if ($a['id'] > 0) continue;
                foreach ($existingAddons as $ex) {
                    if ($ex['addon_key'] === $a['addon_key']) { $a['id'] = (int)$ex['id']; break; }
                }
            }
            unset($a);
            $out['addons'] = $addons;
        } elseif ($planId) {
            // Add-ons not resent: a limit made unlimited must still not leave an
            // active add-on raising it (it would be sold for nothing).
            $maxUsers = array_key_exists('max_users', $in) ? $in['max_users'] : ($plan['max_users'] ?? null);
            $maxShops = array_key_exists('max_shops', $out['fields']) ? $out['fields']['max_shops'] : ($plan['max_shops'] ?? null);
            foreach (planAddons($planId, true) as $a) {
                if (($a['quota'] === 'max_shops' && $maxShops === null) || ($a['quota'] === 'max_users' && $maxUsers === null)) {
                    return $fail('"' . $a['name'] . '" raises a limit you are making unlimited. Remove that add-on first.');
                }
            }
        }
        return $out;
    }
}

if (!function_exists('planCatalogSave')) {
    /** Write the normalised catalogue part. Runs inside the caller's transaction. */
    function planCatalogSave(PDO $pdo, int $planId, array $cat, ?string $productKeyAfter): void
    {
        if ($cat['fields']) {
            $sets = []; $vals = [];
            foreach ($cat['fields'] as $col => $v) { $sets[] = "`$col` = ?"; $vals[] = $v; }
            $vals[] = $planId;
            $pdo->prepare("UPDATE plans SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        }
        // Only one "Most popular" per product.
        if (!empty($cat['fields']['is_popular']) && $productKeyAfter) {
            $pdo->prepare("UPDATE plans SET is_popular = 0 WHERE product_key = ? AND id <> ?")->execute([$productKeyAfter, $planId]);
        }

        $termToPriceId = [];
        if ($cat['prices'] !== null) {
            $existing = [];
            foreach (planPrices($planId) as $p) $existing[(int)$p['id']] = $p;
            $kept = [];
            $upd = $pdo->prepare("UPDATE plan_prices SET term_label = ?, price_tzs = ?, is_default = ?, is_active = ?, sort_order = ? WHERE id = ? AND plan_id = ?");
            $ins = $pdo->prepare("INSERT INTO plan_prices (plan_id, term_label, duration_value, duration_unit, price_tzs, is_default, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($cat['prices'] as $i => $p) {
                $id = $p['id'];
                if ($id === 0) {
                    // Re-adding a term that was retired earlier revives that row (the unique key is per term).
                    foreach ($existing as $ex) {
                        if ((int)$ex['duration_value'] === $p['duration_value'] && $ex['duration_unit'] === $p['duration_unit']) { $id = (int)$ex['id']; break; }
                    }
                }
                if ($id > 0) {
                    $upd->execute([$p['term_label'], $p['price_tzs'], $p['is_default'], $p['is_active'], $i, $id, $planId]);
                } else {
                    $ins->execute([$planId, $p['term_label'], $p['duration_value'], $p['duration_unit'], $p['price_tzs'], $p['is_default'], $p['is_active'], $i]);
                    $id = (int)$pdo->lastInsertId();
                }
                $kept[$id] = true;
                if ($p['is_active']) $termToPriceId[$p['term_key']] = $id;
            }
            // A price left out is retired, never deleted (issued documents point at it).
            foreach ($existing as $id => $ex) {
                if (!isset($kept[$id])) $pdo->prepare("UPDATE plan_prices SET is_active = 0, is_default = 0 WHERE id = ?")->execute([$id]);
            }
        } else {
            foreach (planPrices($planId, true) as $p) $termToPriceId[$p['term_key']] = (int)$p['id'];
        }

        if ($cat['items'] !== null) {
            $pdo->prepare("DELETE FROM plan_items WHERE plan_id = ?")->execute([$planId]);
            $ins = $pdo->prepare("INSERT INTO plan_items (plan_id, label, value, sort_order) VALUES (?,?,?,?)");
            foreach ($cat['items'] as $i => $it) $ins->execute([$planId, $it['label'], $it['value'], $i]);
        }

        if ($cat['addons'] !== null) {
            $kept = [];
            $upd = $pdo->prepare("UPDATE plan_addons SET name = ?, quota = ?, units = ?, is_active = ?, sort_order = ? WHERE id = ? AND plan_id = ?");
            $ins = $pdo->prepare("INSERT INTO plan_addons (plan_id, addon_key, name, quota, units, is_active, sort_order) VALUES (?,?,?,?,?,?,?)");
            $insPrice = $pdo->prepare("INSERT INTO plan_addon_prices (addon_id, plan_price_id, price_tzs) VALUES (?,?,?)");
            foreach ($cat['addons'] as $i => $a) {
                if ($a['id'] > 0) {
                    $upd->execute([$a['name'], $a['quota'], $a['units'], $a['is_active'], $i, $a['id'], $planId]);
                    $id = $a['id'];
                } else {
                    $ins->execute([$planId, $a['addon_key'], $a['name'], $a['quota'], $a['units'], $a['is_active'], $i]);
                    $id = (int)$pdo->lastInsertId();
                }
                $kept[$id] = true;
                $pdo->prepare("DELETE FROM plan_addon_prices WHERE addon_id = ?")->execute([$id]);
                foreach ($a['prices'] as $tk => $amount) {
                    if (isset($termToPriceId[$tk])) $insPrice->execute([$id, $termToPriceId[$tk], $amount]);
                }
            }
            $pdo->prepare("UPDATE plan_addons SET is_active = 0 WHERE plan_id = ?" . ($kept ? " AND id NOT IN (" . implode(',', array_map('intval', array_keys($kept))) . ")" : ""))
                ->execute([$planId]);
        }
    }
}

if (!function_exists('planOfferShape')) {
    /** The public face of one plan: no internal ids, nothing an operator keeps private. */
    function planOfferShape(array $plan): array
    {
        $registry = bmsFeatureRegistry();
        $modules = [];
        foreach (planFeatureKeys((int)$plan['id']) as $fk) if (isset($registry[$fk])) $modules[] = (string)$registry[$fk]['label'];
        sort($modules);

        $prices = []; $idToTerm = [];
        foreach (planPrices((int)$plan['id'], true) as $p) {
            $idToTerm[(int)$p['id']] = $p['term_key'];
            $prices[] = [
                'term_key'   => $p['term_key'],
                'term_label' => (string)$p['term_label'],
                'months'     => (int)$p['months'],
                'price_tzs'  => (int)$p['price_tzs'],
                'per_month'  => (int)round((int)$p['price_tzs'] / max(1, (int)$p['months'])),
                'is_default' => (int)$p['is_default'] === 1,
            ];
        }
        $addons = [];
        foreach (planAddons((int)$plan['id'], true) as $a) {
            $ap = [];
            foreach ($a['prices'] as $ppId => $amount) if (isset($idToTerm[$ppId])) $ap[$idToTerm[$ppId]] = (int)$amount;
            if (!$ap) continue;
            $addons[] = ['addon_key' => (string)$a['addon_key'], 'name' => (string)$a['name'],
                         'quota' => (string)$a['quota'], 'units' => (int)$a['units'], 'prices' => $ap];
        }
        return [
            'plan_key'       => (string)$plan['plan_key'],
            'name'           => (string)$plan['name'],
            'tagline'        => $plan['tagline'] !== null ? (string)$plan['tagline'] : null,
            'description'    => $plan['description'] !== null ? (string)$plan['description'] : null,
            'is_popular'     => (int)$plan['is_popular'] === 1,
            'tier_rank'      => (int)$plan['tier_rank'],
            'max_users'      => $plan['max_users'] !== null ? (int)$plan['max_users'] : null,
            'max_shops'      => $plan['max_shops'] !== null ? (int)$plan['max_shops'] : null,
            'max_storage_mb' => $plan['max_storage_mb'] !== null ? (int)$plan['max_storage_mb'] : null,
            'modules'        => $modules,
            'items'          => array_map(fn($i) => ['label' => (string)$i['label'], 'value' => $i['value'] !== null ? (string)$i['value'] : null], planItems((int)$plan['id'])),
            'prices'         => $prices,
            'addons'         => $addons,
        ];
    }
}

if (!function_exists('listPublicOffers')) {
    /**
     * What the public may see: active products → their active, public plans
     * that have at least one active price. Never the blank plan.
     *
     * @return array<int, array{product_key:string, name:string, description:?string, plans:array}>
     */
    function listPublicOffers(?string $productKey = null, bool $registrationOnly = false): array
    {
        if (!planCatalogReady()) return [];
        $pdo = getControlPdo();
        $sql = "SELECT * FROM platform_products WHERE is_active = 1"
             . ($registrationOnly ? " AND show_on_registration = 1" : "")
             . ($productKey !== null ? " AND product_key = ?" : "")
             . " ORDER BY sort_order, name";
        $st = $pdo->prepare($sql);
        $st->execute($productKey !== null ? [$productKey] : []);
        $out = [];
        $pst = $pdo->prepare("SELECT * FROM plans WHERE product_key = ? AND is_active = 1 AND is_public = 1
                                 AND plan_key <> 'blank' ORDER BY tier_rank, sort_order, name");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $prod) {
            $pst->execute([$prod['product_key']]);
            $plans = [];
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $plan) {
                $shape = planOfferShape($plan);
                if ($shape['prices']) $plans[] = $shape;
            }
            if (!$plans) continue;
            $out[] = ['product_key' => (string)$prod['product_key'], 'name' => (string)$prod['name'],
                      'description' => $prod['description'] !== null ? (string)$prod['description'] : null,
                      'plans' => $plans];
        }
        return $out;
    }
}

if (!function_exists('planOfferPrice')) {
    /**
     * Resolve "plan_key + term_key" (what a browser or app sends) to a real,
     * currently sellable price — server-side, every time. Null = not on sale.
     */
    function planOfferPrice(string $planKey, string $termKey, bool $publicOnly = true): ?array
    {
        if (!planCatalogReady() || $planKey === '' || $planKey === 'blank') return null;
        if (!preg_match('/^(\d{1,3})([my])$/', $termKey, $m)) return null;
        $st = getControlPdo()->prepare("
            SELECT pp.id FROM plan_prices pp
              JOIN plans p ON p.id = pp.plan_id
              JOIN platform_products pr ON pr.product_key = p.product_key AND pr.is_active = 1
             WHERE p.plan_key = ? AND p.is_active = 1" . ($publicOnly ? " AND p.is_public = 1" : "") . "
               AND pp.is_active = 1 AND pp.duration_value = ? AND pp.duration_unit = ?
             LIMIT 1");
        $st->execute([$planKey, (int)$m[1], $m[2] === 'y' ? 'year' : 'month']);
        $id = (int)$st->fetchColumn();
        return $id ? planPriceById($id) : null;
    }
}

if (!function_exists('createPlan')) {
    /**
     * @param array $in name, description, max_users(?int), max_storage_mb(?int),
     *                  feature_keys(string[]), sort_order(?int) — plus the
     *                  optional service-plan fields listed in the file header
     */
    function createPlan(array $in): array
    {
        $name        = trim((string)($in['name'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);
        $maxUsers   = $in['max_users'] ?? null;
        $maxStorage = $in['max_storage_mb'] ?? null;

        if ($err = planInputError($name, $maxUsers, $maxStorage)) {
            return ['ok' => false, 'error' => $err, 'id' => null];
        }

        $cat = planCatalogReady() ? planCatalogNormalize($in, null) : null;
        if ($cat && $cat['error']) return ['ok' => false, 'error' => $cat['error'], 'id' => null];

        $pdo = getControlPdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            // Unique plan_key, appending -2/-3/... on collision — same discipline
            // as tenant subdomain allocation, just simpler (no reserved-word list needed).
            $slug = planSlug($name);
            $base = $slug; $n = 2;
            while (true) {
                $st = $pdo->prepare("SELECT 1 FROM plans WHERE plan_key = ?");
                $st->execute([$slug]);
                if (!$st->fetchColumn()) break;
                $slug = $base . '-' . $n++;
            }

            $pdo->prepare("
                INSERT INTO plans (plan_key, name, description, max_users, max_storage_mb, sort_order)
                VALUES (?,?,?,?,?,?)
            ")->execute([$slug, $name, $description !== '' ? $description : null, $maxUsers, $maxStorage, (int)($in['sort_order'] ?? 0)]);
            $id = (int)$pdo->lastInsertId();

            $validKeys = array_keys(bmsFeatureRegistry());
            $features  = array_values(array_intersect((array)($in['feature_keys'] ?? []), $validKeys));
            // Dependency-closed at save time (tenant_module_control_plan.md, Phase
            // A): a plan checking 'sales' alone silently gains 'warehouses' too —
            // matches applyPlanToTenant()/setTenantFeatures() auto-including the
            // same way, so a plan can never itself represent a broken combination.
            $features  = featureDependencyClosure($features);
            if ($features) {
                $ins = $pdo->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_key) VALUES (?,?)");
                foreach ($features as $fk) $ins->execute([$id, $fk]);
            }

            if ($cat) planCatalogSave($pdo, $id, $cat, $cat['fields']['product_key'] ?? null);
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            error_log('createPlan: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The plan could not be saved.', 'id' => null];
        }

        logTenantAdminAction(null, null, 'plan_create', $name . ' (' . count($features) . ' features'
            . ($cat && $cat['prices'] !== null ? ', ' . count(array_filter($cat['prices'], fn($p) => $p['is_active'])) . ' price(s)' : '') . ')');
        return ['ok' => true, 'error' => null, 'id' => $id];
    }
}

if (!function_exists('updatePlan')) {
    /** Same $in shape as createPlan(). plan_key never changes once created. */
    function updatePlan(int $id, array $in): array
    {
        $plan = getPlan($id);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.'];

        $name        = trim((string)($in['name'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);
        $maxUsers   = $in['max_users'] ?? null;
        $maxStorage = $in['max_storage_mb'] ?? null;

        if ($err = planInputError($name, $maxUsers, $maxStorage)) {
            return ['ok' => false, 'error' => $err];
        }

        $cat = planCatalogReady() ? planCatalogNormalize($in, $plan) : null;
        if ($cat && $cat['error']) return ['ok' => false, 'error' => $cat['error']];

        $pdo = getControlPdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE plans SET name=?, description=?, max_users=?, max_storage_mb=?, sort_order=?
                WHERE id=?
            ")->execute([
                $name, $description !== '' ? $description : null, $maxUsers, $maxStorage,
                (int)($in['sort_order'] ?? $plan['sort_order']), $id,
            ]);

            $validKeys = array_keys(bmsFeatureRegistry());
            $features  = array_values(array_intersect((array)($in['feature_keys'] ?? []), $validKeys));
            // Dependency-closed at save time (tenant_module_control_plan.md, Phase
            // A): a plan checking 'sales' alone silently gains 'warehouses' too —
            // matches applyPlanToTenant()/setTenantFeatures() auto-including the
            // same way, so a plan can never itself represent a broken combination.
            $features  = featureDependencyClosure($features);
            $pdo->prepare("DELETE FROM plan_features WHERE plan_id = ?")->execute([$id]);
            if ($features) {
                $ins = $pdo->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_key) VALUES (?,?)");
                foreach ($features as $fk) $ins->execute([$id, $fk]);
            }

            if ($cat) {
                $pkAfter = array_key_exists('product_key', $cat['fields']) ? $cat['fields']['product_key'] : ($plan['product_key'] ?? null);
                planCatalogSave($pdo, $id, $cat, $pkAfter);
            }
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            error_log('updatePlan: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The plan could not be saved.'];
        }

        $priceNote = '';
        if ($cat && $cat['prices'] !== null) {
            $priceNote = ', prices: ' . (implode(' · ', array_map(fn($p) => $p['term_label'] . ' ' . number_format($p['price_tzs']),
                array_filter($cat['prices'], fn($p) => $p['is_active']))) ?: 'none');
        }
        logTenantAdminAction(null, null, 'plan_update', $name . ' (' . count($features) . ' features' . $priceNote . ')');
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('setPlanActive')) {
    /**
     * Retire/restore a plan. Never a hard DELETE — same convention as
     * features.is_available: tenants already showing this plan_key must keep
     * resolving to a real row, and the audit trail must keep something to
     * point at.
     */
    function setPlanActive(int $id, bool $active): array
    {
        $plan = getPlan($id);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.'];
        getControlPdo()->prepare("UPDATE plans SET is_active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]);
        logTenantAdminAction(null, null, $active ? 'plan_activate' : 'plan_deactivate', $plan['name']);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('applyPlanToTenant')) {
    /**
     * Apply one plan's feature set and quotas to one tenant, right now.
     *
     * A COMPLETE feature-set replacement, not incremental: every registered
     * feature not in the plan is explicitly turned off, same semantics as a
     * tenant_features override row. Reuses setTenantFeatures()/
     * setTenantQuotas() verbatim — no new write path, no new enforcement.
     *
     * @return array{ok:bool, error:?string, features_changed:int, quotas_changed:bool}
     */
    function applyPlanToTenant(int $tenantId, int $planId): array
    {
        $plan = getPlan($planId);
        if (!$plan) return ['ok' => false, 'error' => 'No such plan.', 'features_changed' => 0, 'quotas_changed' => false];
        if (!$plan['is_active']) {
            return ['ok' => false, 'error' => 'This plan is retired and can no longer be applied.', 'features_changed' => 0, 'quotas_changed' => false];
        }

        $t = getTenant($tenantId);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.', 'features_changed' => 0, 'quotas_changed' => false];
        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has been deleted.', 'features_changed' => 0, 'quotas_changed' => false];
        }

        $planFeatures = planFeatureKeys($planId);
        $desired = [];
        foreach (array_keys(bmsFeatureRegistry()) as $key) {
            $desired[$key] = in_array($key, $planFeatures, true);
        }

        $fr = setTenantFeatures($tenantId, $desired);
        $qr = setTenantQuotas(
            $tenantId,
            $plan['max_users'] !== null ? (int)$plan['max_users'] : null,
            $plan['max_storage_mb'] !== null ? (int)$plan['max_storage_mb'] : null
        );

        if (!$fr['ok'])  return ['ok' => false, 'error' => $fr['error'], 'features_changed' => 0, 'quotas_changed' => false];
        if (!$qr['ok'])  return ['ok' => false, 'error' => $qr['error'], 'features_changed' => $fr['changed'], 'quotas_changed' => false];

        getControlPdo()->prepare("UPDATE tenants SET plan = ? WHERE id = ?")->execute([$plan['plan_key'], $tenantId]);
        // The shop limit travels with the plan too (NULL = unlimited, as before
        // this column existed). Guarded so an un-upgraded control DB still works.
        if (array_key_exists('max_shops', $plan) && controlColumnExists('tenants', 'max_shops')) {
            getControlPdo()->prepare("UPDATE tenants SET max_shops = ? WHERE id = ?")
                ->execute([$plan['max_shops'] !== null ? (int)$plan['max_shops'] : null, $tenantId]);
        }

        logTenantAdminAction($tenantId, $t['subdomain'], 'apply_plan',
            $plan['name'] . ' — ' . $fr['changed'] . ' feature change(s), quotas '
            . ($qr['changed'] ? 'updated' : 'unchanged'));

        return ['ok' => true, 'error' => null, 'features_changed' => $fr['changed'], 'quotas_changed' => $qr['changed']];
    }
}
