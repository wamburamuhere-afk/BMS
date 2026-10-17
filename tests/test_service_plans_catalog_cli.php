<?php
/**
 * tests/test_service_plans_catalog_cli.php — service_plans_plan.md Phase 1:
 * the sellable plan catalogue (products, prices per term, included lines,
 * add-ons, public offers).
 *
 *   php tests/test_service_plans_catalog_cli.php
 *
 * Proves:
 *   1. createPlan()/updatePlan() store prices, items and add-ons; every
 *      validation rule refuses bad input and leaves nothing half-written
 *   2. prices are retired, never deleted; a retired price still resolves via
 *      planPriceById(); re-adding a term revives its row; a term's length is
 *      immutable
 *   3. add-ons are locked to the plan's terms and to a limited quota
 *   4. listPublicOffers() hides inactive/hidden/price-less/blank plans and
 *      inactive products; planOfferPrice() refuses forged/retired keys
 *   5. applyPlanToTenant() carries max_shops to the tenant
 *   6. the endpoint with catalog=1 (including "every price removed") and the
 *      page renders through the real router, apostrophes and all
 *   7. legacy callers (no catalogue keys) are untouched
 *
 * CLI ONLY. Creates throwaway plans/products and one throwaway tenant; removes all.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker ────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = (string)$argv[4];
    $_SERVER['REQUEST_URI']    = '/' . $file;
    require_once __DIR__ . '/../core/superadmin_auth.php';
    require_once __DIR__ . '/../helpers.php';
    superadminSessionReady();
    require_once __DIR__ . '/../core/control_db.php';
    $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
    $post['_csrf'] = csrf_token();
    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}

// ─── Router worker ──────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';
    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function endpoint(string $file, array $post): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post))) . ' ' . escapeshellarg(SA_HOST);
    $out = []; exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}
function route(string $uri): string {
    $out = []; exec('php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg(SA_HOST) . ' ' . escapeshellarg($uri) . ' 2>&1', $out);
    return implode("\n", $out);
}
/** Fails a test with the error, so a refusal for the WRONG reason never passes. */
function refusedWith(array $r, string $needle): bool {
    return $r['ok'] === false && str_contains((string)$r['error'], $needle);
}

echo "\nBMS — Service plans Phase 1: sellable catalogue\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

if (!planCatalogReady()) { echo "  FAIL  catalogue tables missing — run php scripts/setup_control_db.php\n"; exit(1); }
ok('planCatalogReady() sees the new schema', true);

$tag = 'spt' . bin2hex(random_bytes(3));
$PROD = $tag . '_prod';
$PROD_OFF = $tag . '_off';
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")->execute([$PROD, "Test Shop $tag"]);
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,0,0,0)")->execute([$PROD_OFF, "Inactive $tag"]);

$createdPlanIds = [];
register_shutdown_function(function () use ($c, &$createdPlanIds, $PROD, $PROD_OFF) {
    foreach ($createdPlanIds as $id) {
        $id = (int)$id;
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $t) $c->exec("DELETE FROM $t WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key IN (?,?)")->execute([$PROD, $PROD_OFF]);
});

$basePrices = [
    ['duration_value' => 1,  'duration_unit' => 'month', 'price_tzs' => '10,000', 'is_default' => 0],
    ['duration_value' => 3,  'duration_unit' => 'month', 'price_tzs' => '25000',  'is_default' => 0],
    ['duration_value' => 6,  'duration_unit' => 'month', 'price_tzs' => '45 000', 'is_default' => 1],
    ['duration_value' => 1,  'duration_unit' => 'year',  'price_tzs' => 85000,    'is_default' => 0, 'term_label' => 'Yearly'],
];
$base = fn(array $extra = []) => array_merge([
    'name' => "Starter's Plan $tag", 'description' => 'For one "small" shop', 'max_users' => 2, 'max_storage_mb' => 500,
    'feature_keys' => ['pos'], 'product_key' => $PROD, 'tagline' => 'Start <b>selling</b> today',
    'max_shops' => 1, 'tier_rank' => 10, 'is_public' => true, 'is_popular' => true,
    'prices' => $basePrices,
    'items'  => [['label' => 'Free setup', 'value' => ''], ['label' => '', 'value' => ''], ['label' => 'Support', 'value' => 'WhatsApp']],
    'addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => '5,000', '6m' => 25000]],
                 ['name' => 'Extra user', 'quota' => 'max_users', 'units' => 1, 'prices' => ['1y' => 20000]]],
], $extra);

// ─────────────────────────────────────────────────────────────────────────────
section('1. Validation — every rule refuses, and nothing is written');

$before = (int)$c->query("SELECT COUNT(*) FROM plans")->fetchColumn();
$cases = [
    'unknown product'                   => [['product_key' => 'nope_' . $tag], 'Choose a product'],
    'tagline > 150'                     => [['tagline' => str_repeat('x', 151)], 'tagline'],
    'max_shops 0'                       => [['max_shops' => 0], 'Max shops'],
    'tier 1001'                         => [['tier_rank' => 1001], 'Tier'],
    'public without product'            => [['product_key' => ''], 'must belong to a product'],
    'public without prices'             => [['prices' => [], 'addons' => []], 'needs at least one price'],
    'bad unit'                          => [['prices' => [['duration_value' => 1, 'duration_unit' => 'week', 'price_tzs' => 1]]], 'months or years'],
    '0 months'                          => [['prices' => [['duration_value' => 0, 'duration_unit' => 'month', 'price_tzs' => 1]]], '1 to 120'],
    '121 months'                        => [['prices' => [['duration_value' => 121, 'duration_unit' => 'month', 'price_tzs' => 1]]], '1 to 120'],
    '11 years'                          => [['prices' => [['duration_value' => 11, 'duration_unit' => 'year', 'price_tzs' => 1]]], '1 to 10'],
    'price 0'                           => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => '0']]], 'greater than 0'],
    'negative price'                    => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => '-5']]], 'greater than 0'],
    'decimal price'                     => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => '10.5']]], 'greater than 0'],
    'price > 1e9'                       => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => '1000000001']]], 'greater than 0'],
    'label > 60'                        => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 1, 'term_label' => str_repeat('y', 61)]]], '60 characters'],
    '12 months + 1 year (same length)'  => [['prices' => [['duration_value' => 12, 'duration_unit' => 'month', 'price_tzs' => 1, 'is_default' => 1],
                                                          ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 2]], 'addons' => []], 'same length'],
    'no default among two'              => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 1],
                                                          ['duration_value' => 2, 'duration_unit' => 'month', 'price_tzs' => 2]], 'addons' => []], 'exactly one'],
    'two defaults'                      => [['prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 1, 'is_default' => 1],
                                                          ['duration_value' => 2, 'duration_unit' => 'month', 'price_tzs' => 2, 'is_default' => 1]], 'addons' => []], 'exactly one'],
    'item without a name'               => [['items' => [['label' => '', 'value' => 'x']]], 'needs a name'],
    'item > 100'                        => [['items' => [['label' => str_repeat('z', 101)]]], '100 characters'],
    '31 items'                          => [['items' => array_fill(0, 31, ['label' => 'x'])], 'up to 30'],
    'add-on without a name'             => [['addons' => [['name' => '', 'quota' => 'max_shops', 'prices' => ['1m' => 1]]]], 'needs a name'],
    'add-on bad quota'                  => [['addons' => [['name' => 'A', 'quota' => 'max_storage_mb', 'prices' => ['1m' => 1]]]], 'shops or users'],
    'add-on 0 units'                    => [['addons' => [['name' => 'A', 'quota' => 'max_shops', 'units' => 0, 'prices' => ['1m' => 1]]]], '1 to 1000'],
    'add-on on unlimited shops'         => [['max_shops' => null], 'unlimited shops'],
    'add-on on unlimited users'         => [['max_users' => null], 'unlimited users'],
    'add-on for a term not offered'     => [['addons' => [['name' => 'A', 'quota' => 'max_shops', 'prices' => ['2m' => 1]]]], 'does not offer'],
    'add-on with no price'              => [['addons' => [['name' => 'A', 'quota' => 'max_shops', 'prices' => []]]], 'at least one term'],
    'add-on with a bad price'           => [['addons' => [['name' => 'A', 'quota' => 'max_shops', 'prices' => ['1m' => 'abc']]]], 'whole amount'],
    'two add-ons, same name'            => [['addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'prices' => ['1m' => 1]],
                                                          ['name' => 'extra  SHOP', 'quota' => 'max_shops', 'prices' => ['1m' => 1]]]], 'same name'],
];
foreach ($cases as $label => [$extra, $needle]) {
    $r = createPlan($base($extra));
    ok("refuses: $label", refusedWith($r, $needle), (string)($r['error'] ?? 'accepted!'));
    if (!empty($r['id'])) $createdPlanIds[] = (int)$r['id'];
}
ok('no refused create left a plan row behind', (int)$c->query("SELECT COUNT(*) FROM plans")->fetchColumn() === $before);

// ─────────────────────────────────────────────────────────────────────────────
section('2. A valid create stores everything');

$r = createPlan($base());
ok('valid create succeeds', $r['ok'] === true, (string)($r['error'] ?? ''));
$planId = (int)$r['id'];
$createdPlanIds[] = $planId;
$p = getPlan($planId);
ok('product/tagline/tier/shops/public/popular stored',
   $p['product_key'] === $PROD && $p['tagline'] === 'Start <b>selling</b> today' && (int)$p['tier_rank'] === 10
   && (int)$p['max_shops'] === 1 && (int)$p['is_public'] === 1 && (int)$p['is_popular'] === 1);

$prices = planPrices($planId, true);
ok('4 prices, shortest first (1m,3m,6m,1y)', array_column($prices, 'term_key') === ['1m', '3m', '6m', '1y'], implode(',', array_column($prices, 'term_key')));
ok('commas/spaces stripped from prices', array_map('intval', array_column($prices, 'price_tzs')) === [10000, 25000, 45000, 85000]);
ok('labels auto-filled, custom kept', array_column($prices, 'term_label') === ['1 month', '3 months', '6 months', 'Yearly']);
ok('exactly one default (6m)', array_column(array_filter($prices, fn($x) => (int)$x['is_default'] === 1), 'term_key') === ['6m']);
ok('a year term is 12 months', (int)$prices[3]['months'] === 12);

$items = planItems($planId);
ok('blank item rows skipped; 2 kept in order', array_column($items, 'label') === ['Free setup', 'Support']);
ok('empty item value stored as NULL', $items[0]['value'] === null && $items[1]['value'] === 'WhatsApp');

$addons = planAddons($planId, true);
$byKey = []; foreach ($addons as $a) $byKey[$a['addon_key']] = $a;
ok('2 add-ons with slug keys', isset($byKey['extra-shop'], $byKey['extra-user']), implode(',', array_keys($byKey)));
$pid6m = (int)$prices[2]['id']; $pid1m = (int)$prices[0]['id']; $pid1y = (int)$prices[3]['id'];
ok('add-on prices keyed to the plan\'s price rows', $byKey['extra-shop']['prices'] === [$pid1m => 5000, $pid6m => 25000]);
ok('extra-user priced only for the year', $byKey['extra-user']['prices'] === [$pid1y => 20000]);

// Only one "Most popular" per product
$r2 = createPlan($base(['name' => "Business $tag", 'tier_rank' => 20, 'max_shops' => 3, 'addons' => [], 'is_popular' => true]));
$plan2 = (int)$r2['id']; $createdPlanIds[] = $plan2;
ok('second public plan created', $r2['ok'] === true, (string)($r2['error'] ?? ''));
ok('marking it popular un-marks the first (one per product)',
   (int)getPlan($plan2)['is_popular'] === 1 && (int)getPlan($planId)['is_popular'] === 0);

$r3 = createPlan($base(['name' => "Hidden $tag", 'is_public' => false, 'is_popular' => true, 'addons' => []]));
$plan3 = (int)$r3['id']; $createdPlanIds[] = $plan3;
ok('a hidden plan can never be "Most popular"', $r3['ok'] && (int)getPlan($plan3)['is_popular'] === 0 && (int)getPlan($plan2)['is_popular'] === 1);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Prices: retire-never-delete, immutable length, revive');

$keepPrices = fn(array $only) => array_values(array_map(fn($x) => [
    'id' => (int)$x['id'], 'duration_value' => (int)$x['duration_value'], 'duration_unit' => $x['duration_unit'],
    'price_tzs' => (int)$x['price_tzs'], 'term_label' => $x['term_label'], 'is_default' => (int)$x['is_default']],
    array_filter(planPrices($planId, true), fn($x) => in_array($x['term_key'], $only, true))));

$upd = $base(['prices' => $keepPrices(['1m', '6m', '1y']), 'addons' => [
    ['id' => (int)$byKey['extra-shop']['id'], 'name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => 6000, '6m' => 26000]],
]]);
$ur = updatePlan($planId, $upd);
ok('update dropping 3m and extra-user succeeds', $ur['ok'] === true, (string)($ur['error'] ?? ''));
$all = planPrices($planId);
$p3m = array_values(array_filter($all, fn($x) => $x['term_key'] === '3m'))[0] ?? null;
ok('3m row still exists (retired, not deleted)', $p3m !== null && (int)$p3m['is_active'] === 0 && (int)$p3m['is_default'] === 0);
ok('active prices are now 1m,6m,1y', array_column(planPrices($planId, true), 'term_key') === ['1m', '6m', '1y']);
$resolved = planPriceById((int)$p3m['id']);
ok('planPriceById() still resolves the retired price, with its plan', $resolved && (int)$resolved['price_tzs'] === 25000
   && $resolved['plan_key'] === $p['plan_key'] && (int)$resolved['plan_is_public'] === 1 && $resolved['term_key'] === '3m');
ok('extra-user add-on retired, not deleted', (int)$c->query("SELECT is_active FROM plan_addons WHERE id = " . (int)$byKey['extra-user']['id'])->fetchColumn() === 0);
ok('extra-shop prices rewritten', planAddons($planId, true)[0]['prices'] === [$pid1m => 6000, $pid6m => 26000]);

$bad = $upd; $bad['prices'][0]['duration_value'] = 2;
ok('refuses changing a saved term\'s length', refusedWith(updatePlan($planId, $bad), 'cannot be changed'));
$bad = $upd; $bad['prices'][0]['id'] = (int)planPrices($plan2)[0]['id'];
ok('refuses a price id from ANOTHER plan', refusedWith(updatePlan($planId, $bad), 'does not belong'));
$bad = $upd; $bad['addons'][0]['id'] = 999999999;
ok('refuses an add-on id from another plan', refusedWith(updatePlan($planId, $bad), 'does not belong'));
ok('refused updates changed nothing', array_column(planPrices($planId, true), 'term_key') === ['1m', '6m', '1y']);

$revive = $upd; $revive['prices'][] = ['duration_value' => 3, 'duration_unit' => 'month', 'price_tzs' => 27000];
$ur = updatePlan($planId, $revive);
ok('re-adding the 3m term succeeds', $ur['ok'] === true, (string)($ur['error'] ?? ''));
$p3mAgain = array_values(array_filter(planPrices($planId, true), fn($x) => $x['term_key'] === '3m'))[0] ?? null;
ok('...and REVIVES the same row (same id, new price)', $p3mAgain && (int)$p3mAgain['id'] === (int)$p3m['id'] && (int)$p3mAgain['price_tzs'] === 27000);

$readd = $upd; $readd['addons'][] = ['name' => 'Extra user', 'quota' => 'max_users', 'units' => 2, 'prices' => ['1y' => 30000]];
$ur = updatePlan($planId, $readd);
ok('re-adding a retired add-on name reuses its row', $ur['ok'] && (int)$c->query("SELECT COUNT(*) FROM plan_addons WHERE plan_id = $planId AND addon_key = 'extra-user'")->fetchColumn() === 1
   && (int)$c->query("SELECT is_active FROM plan_addons WHERE id = " . (int)$byKey['extra-user']['id'])->fetchColumn() === 1, (string)($ur['error'] ?? ''));

// Unlimited users while an active user add-on exists — via the legacy path (no addons key)
$legacyUnl = ['name' => getPlan($planId)['name'], 'max_users' => null, 'max_storage_mb' => 500, 'feature_keys' => ['pos']];
ok('legacy save making users unlimited is refused while a user add-on is active',
   refusedWith(updatePlan($planId, $legacyUnl), 'Remove that add-on'));

// ─────────────────────────────────────────────────────────────────────────────
section('4. Public offers and server-side price resolution');

$offers = listPublicOffers($PROD);
$keys = $offers ? array_column($offers[0]['plans'], 'plan_key') : [];
ok('the product is offered', count($offers) === 1 && $offers[0]['product_key'] === $PROD);
ok('public plans listed by tier (Starter, Business); hidden plan absent',
   $keys === [$p['plan_key'], getPlan($plan2)['plan_key']], implode(',', $keys));
$o = $offers[0]['plans'][0];
ok('offer shape has no internal ids', !array_key_exists('id', $o) && !array_key_exists('id', $o['prices'][0]) && !str_contains(json_encode($o), '"plan_price_id"'));
ok('offer per_month computed (6m 45,000 → 7,500)', (array_values(array_filter($o['prices'], fn($x) => $x['term_key'] === '6m'))[0]['per_month'] ?? 0) === 7500);
ok('offer add-on prices keyed by term_key', ($o['addons'][0]['prices'] ?? null) === ['1m' => 6000, '6m' => 26000], json_encode($o['addons'] ?? null));
ok('offer modules are labels', in_array(bmsFeatureRegistry()['pos']['label'], $o['modules'], true));
ok('registration-only list includes the product (show_on_registration=1)', (bool)array_filter(listPublicOffers(null, true), fn($x) => $x['product_key'] === $PROD));

ok('planOfferPrice resolves 6m', ($op = planOfferPrice($p['plan_key'], '6m')) && (int)$op['price_tzs'] === 45000);
ok('planOfferPrice refuses a forged term key', planOfferPrice($p['plan_key'], '6m; DROP') === null && planOfferPrice($p['plan_key'], '7m') === null
   && planOfferPrice($p['plan_key'], '') === null && planOfferPrice($p['plan_key'], '1w') === null);
ok('planOfferPrice refuses the blank plan and unknown plans', planOfferPrice('blank', '1m') === null && planOfferPrice('nope-' . $tag, '1m') === null);
ok('planOfferPrice refuses a hidden plan (public only) but allows it for internal use',
   planOfferPrice(getPlan($plan3)['plan_key'], '6m') === null && planOfferPrice(getPlan($plan3)['plan_key'], '6m', false) !== null);

// Retire a price → gone from offers
updatePlan($plan2, array_merge($base(['name' => "Business $tag", 'tier_rank' => 20, 'max_shops' => 3, 'addons' => []]),
    ['prices' => array_values(array_map(fn($x) => ['id' => (int)$x['id'], 'duration_value' => (int)$x['duration_value'], 'duration_unit' => $x['duration_unit'], 'price_tzs' => (int)$x['price_tzs'], 'is_default' => $x['term_key'] === '6m' ? 1 : 0],
        array_filter(planPrices($plan2, true), fn($x) => $x['term_key'] !== '1m')))]));
ok('planOfferPrice refuses a retired term', planOfferPrice(getPlan($plan2)['plan_key'], '1m') === null);

setPlanActive($plan2, false);
ok('a retired plan disappears from offers', !in_array(getPlan($plan2)['plan_key'], array_column(listPublicOffers($PROD)[0]['plans'] ?? [], 'plan_key'), true));
ok('...and its prices cannot be bought', planOfferPrice(getPlan($plan2)['plan_key'], '6m', false) === null);
setPlanActive($plan2, true);

// A public plan whose prices were all retired directly (not via save) is not offered
$c->exec("UPDATE plan_prices SET is_active = 0 WHERE plan_id = $plan2");
ok('a plan with no active price is not offered', !in_array(getPlan($plan2)['plan_key'], array_column(listPublicOffers($PROD)[0]['plans'] ?? [], 'plan_key'), true));

// Inactive product
$r4 = createPlan($base(['name' => "Off product $tag", 'product_key' => $PROD_OFF, 'is_popular' => false, 'addons' => []]));
$createdPlanIds[] = (int)$r4['id'];
ok('a plan can belong to an inactive product', $r4['ok'] === true, (string)($r4['error'] ?? ''));
ok('...but an inactive product is never offered', listPublicOffers($PROD_OFF) === [] && planOfferPrice(getPlan((int)$r4['id'])['plan_key'], '6m') === null);

// Blank plan can never be sold
$blank = getPlanByKey('blank');
if ($blank) {
    $br = updatePlan((int)$blank['id'], ['name' => $blank['name'], 'max_users' => $blank['max_users'] !== null ? (int)$blank['max_users'] : null,
        'max_storage_mb' => $blank['max_storage_mb'] !== null ? (int)$blank['max_storage_mb'] : null,
        'feature_keys' => planFeatureKeys((int)$blank['id']), 'is_public' => true, 'product_key' => $PROD, 'prices' => $basePrices]);
    ok('the reserved blank plan can never be made public/sold', refusedWith($br, 'blank plan'));
} else {
    ok('the reserved blank plan can never be made public/sold (no blank plan here — logic checked)',
       planCatalogNormalize(['is_public' => true, 'product_key' => $PROD], ['id' => 0, 'plan_key' => 'blank'])['error'] !== null);
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. applyPlanToTenant() carries max_shops');

$sub = 'spt' . bin2hex(random_bytes(3));
$pr = provisionTenant('Service Plans Test Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $pr['ok'] === true, (string)($pr['error'] ?? ''));
$tenantId = (int)$pr['tenant_id'];
register_shutdown_function(function () use ($tenantId) {
    try { $t = getTenant($tenantId); if ($t) deleteTenant($tenantId, $t['company_name']); }
    catch (Throwable $e) { error_log('service plans test cleanup: ' . $e->getMessage()); }
});
$ar = applyPlanToTenant($tenantId, $planId);
ok('apply succeeds', $ar['ok'] === true, (string)($ar['error'] ?? ''));
$t = $c->query("SELECT plan, max_users, max_shops FROM tenants WHERE id = $tenantId")->fetch(PDO::FETCH_ASSOC);
ok('tenant gets max_shops 1, max_users 2', (int)$t['max_shops'] === 1 && (int)$t['max_users'] === 2 && $t['plan'] === $p['plan_key']);
$r5 = createPlan(['name' => "Unlimited shops $tag", 'max_users' => null, 'max_storage_mb' => null, 'feature_keys' => ['pos']]);
$createdPlanIds[] = (int)$r5['id'];
applyPlanToTenant($tenantId, (int)$r5['id']);
ok('an unlimited-shop plan sets max_shops back to NULL', $c->query("SELECT max_shops FROM tenants WHERE id = $tenantId")->fetchColumn() === null);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Legacy callers are untouched');

$r6 = createPlan(['name' => "Legacy $tag", 'max_users' => 3, 'max_storage_mb' => null, 'feature_keys' => ['pos']]);
$legacyId = (int)$r6['id']; $createdPlanIds[] = $legacyId;
$lp = getPlan($legacyId);
ok('a legacy create still works', $r6['ok'] === true);
ok('...and is not for sale (hidden, no product, no prices)', (int)$lp['is_public'] === 0 && $lp['product_key'] === null && planPrices($legacyId) === []);
$c->exec("UPDATE plans SET product_key = " . $c->quote($PROD) . " WHERE id = $legacyId");
updatePlan($plan3, ['name' => "Hidden $tag", 'max_users' => 2, 'max_storage_mb' => 500, 'feature_keys' => ['pos']]);
ok('a legacy update keeps existing prices/items/add-ons', count(planPrices($plan3, true)) === 4 && count(planItems($plan3)) === 2);
ok('a legacy update keeps product/tagline/tier', getPlan($plan3)['product_key'] === $PROD && getPlan($plan3)['tagline'] === 'Start <b>selling</b> today');

// ─────────────────────────────────────────────────────────────────────────────
section('7. Endpoint (catalog=1) and the page');

$ep = [
    'action' => 'create', 'name' => "Endpoint O'Brien $tag", 'description' => '', 'max_users' => '2', 'max_storage_mb' => '',
    'sort_order' => '0', 'feature_keys' => ['pos'], 'catalog' => '1', 'product_key' => $PROD, 'tagline' => "Mama's shop",
    'max_shops' => '1', 'tier_rank' => '5', 'is_public' => '1', 'is_popular' => '0',
    'prices' => [['id' => '0', 'duration_value' => '1', 'duration_unit' => 'month', 'term_label' => '', 'price_tzs' => '10000', 'is_default' => '1', 'is_active' => '1']],
    'items'  => [['label' => "Owner's training", 'value' => '']],
    'addons' => [['id' => '0', 'name' => 'Extra shop', 'quota' => 'max_shops', 'units' => '1', 'is_active' => '1', 'prices' => ['1m' => '4000']]],
];
$out = endpoint('actions/superadmin_plans.php', $ep);
ok('endpoint create with catalogue succeeds', str_contains($out, '"success":true'), substr($out, 0, 300));
$epPlan = (int)$c->query("SELECT id FROM plans WHERE name = " . $c->quote("Endpoint O'Brien $tag"))->fetchColumn();
if ($epPlan) $createdPlanIds[] = $epPlan;
ok('...prices/items/add-ons persisted', $epPlan && count(planPrices($epPlan, true)) === 1 && count(planItems($epPlan)) === 1 && count(planAddons($epPlan, true)) === 1);

// "every price removed" — jQuery would drop prices[] entirely; the marker makes it [].
$noPrices = ['action' => 'update', 'id' => (string)$epPlan, 'name' => "Endpoint O'Brien $tag", 'max_users' => '2', 'max_storage_mb' => '',
             'feature_keys' => ['pos'], 'catalog' => '1', 'product_key' => $PROD, 'max_shops' => '1', 'tier_rank' => '5',
             'is_public' => '0', 'is_popular' => '0'];
$out = endpoint('actions/superadmin_plans.php', $noPrices);
ok('endpoint: removing every price (no prices[] posted) succeeds on a hidden plan', str_contains($out, '"success":true'), substr($out, 0, 300));
ok('...and really retires all prices and add-ons', $epPlan && planPrices($epPlan, true) === [] && planAddons($epPlan, true) === [] && planItems($epPlan) === []);
$out = endpoint('actions/superadmin_plans.php', array_merge($noPrices, ['is_public' => '1']));
ok('endpoint: a public plan with no prices is refused (422)', str_contains($out, 'needs at least one price') && !str_contains($out, '"success":true'), substr($out, 0, 300));
$out = endpoint('actions/superadmin_plans.php', array_merge($ep, ['prices' => [['duration_value' => '1', 'duration_unit' => 'month', 'price_tzs' => 'abc']]]));
ok('endpoint: a junk price is refused', str_contains($out, 'greater than 0'), substr($out, 0, 300));

$html = route('/plans');
ok('plans page renders with no PHP fatal/warning', !str_contains($html, 'Fatal error') && !str_contains($html, 'Warning:') && !str_contains($html, 'Notice:'), substr($html, 0, 300));
ok('has the catalogue form (prices, add-ons, product select2)',
   str_contains($html, 'id="priceRows"') && str_contains($html, 'id="addonRows"') && str_contains($html, 'id="f_product"') && str_contains($html, 'select2'));
ok('cards show Public / Hidden badges and prices', str_contains($html, '>Public<') && str_contains($html, '>Hidden<') && str_contains($html, 'TZS 45,000'));
ok('apostrophe names are escaped in HTML', str_contains($html, 'Endpoint O&#039;Brien') && !str_contains($html, "Endpoint O'Brien"));
ok('tagline HTML is escaped, never rendered', str_contains($html, 'Start &lt;b&gt;selling') && !str_contains($html, 'Start <b>selling'));
ok('Edit buttons pass only the id (no JSON in attributes)', (bool)preg_match('/openEdit\(\d+\)/', $html) && !str_contains($html, 'openEdit({'));
if (preg_match('/const PLANS = (.*?);\n/', $html, $m)) {
    $data = json_decode($m[1], true);
    ok('the PLANS JSON parses and holds the plan with its prices', is_array($data) && isset($data[$planId]) && count($data[$planId]['prices']) === count(planPrices($planId, true)));
    ok('PLANS JSON is hex-escaped (no raw < or \')', !str_contains($m[1], '<') && !str_contains($m[1], "'"));
} else {
    ok('PLANS JSON present', false);
}

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
