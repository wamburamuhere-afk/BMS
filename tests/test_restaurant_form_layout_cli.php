<?php
/**
 * Restaurant forms — one professional layout (includes/restaurant/form_kit.php).
 *   php tests/test_restaurant_form_layout_cli.php
 *
 *  1. No field was removed: every input/select/textarea (name or id) that the
 *     forms had on origin/develop before this change is still there.
 *  2. Every form uses the layout: sections, the always-visible footer, full
 *     screen on a phone, Select2 dropdowns, money fields with the currency in
 *     front (company currency, TSh by default).
 *  3. Create and Edit share one form; Floors and Tables share one table form.
 *  4. The kit itself: two columns from 768px, one column on a phone.
 * Static (no database). The browser checks (phone 390px + desktop 1366px, real
 * saves) were done by hand — see the PR. Exit 0 = pass.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
$src = fn(string $f) => (string)@file_get_contents("$root/$f");

/** name/id of every form control in a chunk of HTML/PHP. */
function controls(string $html): array {
    preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        if (preg_match('/type="hidden"/', $x[2]) && !preg_match('/name="/', $x[2])) continue;
        if (preg_match('/\bname="([^"<]+)"/', $x[2], $n)) $out['name:' . $n[1]] = true;
        if (preg_match('/\bid="([^"<]+)"/', $x[2], $i)) $out['id:' . $i[1]] = true;
    }
    return $out;
}

// Each form → the file(s) it lives in now (a form may move into a shared include).
$forms = [
    'app/bms/restaurant/reservations.php'       => ['app/bms/restaurant/reservations.php'],
    'app/bms/restaurant/menu.php'               => ['app/bms/restaurant/menu.php'],
    'app/bms/restaurant/modifier_group.php'     => ['app/bms/restaurant/modifier_group.php'],
    'app/bms/restaurant/purchases.php'          => ['app/bms/restaurant/purchases.php'],
    'app/bms/restaurant/floors.php'             => ['app/bms/restaurant/floors.php', 'includes/restaurant/table_form_modal.php'],
    'app/bms/restaurant/tables.php'             => ['app/bms/restaurant/tables.php', 'includes/restaurant/table_form_modal.php'],
    'includes/restaurant/buy_ingredients_modal.php' => ['includes/restaurant/buy_ingredients_modal.php'],
    'includes/restaurant/getting_started.php'   => ['includes/restaurant/getting_started.php'],
    'includes/restaurant/online_menu_share.php' => ['includes/restaurant/online_menu_share.php'],
];

echo "\n── 1. No field removed (compared with origin/develop) ──\n";
$haveGit = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse --verify -q origin/develop 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'))) !== '';
$base = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git merge-base HEAD origin/develop 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
// The commit that introduced the layout: compare against its parent so the check
// keeps working after the merge (develop then already has the new layout).
$intro = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git log --format=%H --diff-filter=A -1 -- includes/restaurant/form_kit.php 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
$ref = $intro !== '' ? $intro . '^' : ($base !== '' ? $base : '');
if (!$haveGit && $ref === '') {
    echo "  (no git history — field comparison skipped)\n";
} else {
    foreach ($forms as $old => $nowFiles) {
        $before = (string)shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg(($ref !== '' ? $ref : 'origin/develop') . ':' . $old) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
        if ($before === '') { ok(true, "$old: (not in the reference version — new file)"); continue; }
        $now = '';
        foreach ($nowFiles as $f) $now .= $src($f);
        $missing = array_keys(array_diff_key(controls($before), controls($now)));
        ok(!$missing, "$old: every field still there" . ($missing ? ' — missing: ' . implode(', ', $missing) : ' (' . count(controls($before)) . ' names/ids)'));
    }
}

echo "\n── 2. Every form uses the layout ──\n";
$modals = [
    ['app/bms/restaurant/reservations.php',           'reservationModal', false],
    ['app/bms/restaurant/menu.php',                   'itemModal',        true],
    ['app/bms/restaurant/menu.php',                   'recipeModal',      false],
    ['app/bms/restaurant/modifier_group.php',         'groupModal',       false],
    ['app/bms/restaurant/purchases.php',              'rpPayModal',       true],
    ['includes/restaurant/table_form_modal.php',      'tableModal',       false],
    ['includes/restaurant/buy_ingredients_modal.php', 'rbiModal',         true],
    ['includes/restaurant/getting_started.php',       'rgsMenuModal',     true],
    ['includes/restaurant/online_menu_share.php',     'shareMenuModal',   false],
];
foreach ($modals as [$f, $id, $hasMoney]) {
    $s = $src($f);
    $p = strpos($s, 'id="' . $id . '"');
    // The modal only: up to the next script, comment or modal.
    $chunk = $p === false ? '' : substr($s, $p, 20000);
    $ends = array_filter([strpos($chunk, '<script'), strpos($chunk, '<!-- '), strpos($chunk, 'class="modal fade', 10)]);
    if ($ends) $chunk = substr($chunk, 0, min($ends));
    ok($p !== false && preg_match('/class="modal fade rf-modal"[^>]*id="' . $id . '"|id="' . $id . '"[^>]*class="modal fade rf-modal"/', $s . '') + (int)str_contains(substr($s, max(0, $p - 80), 160), 'rf-modal') > 0, "$id: uses the restaurant form layout (rf-modal)");
    ok(str_contains($chunk, 'modal-fullscreen-sm-down') && str_contains($chunk, 'modal-dialog-scrollable'), "$id: full screen on a phone, body scrolls, buttons stay visible");
    ok(str_contains($chunk, 'rf-sec-title'), "$id: has section titles");
    ok(str_contains($chunk, 'rf-foot'), "$id: standard footer (Cancel / Save)");
    // Plain dropdowns use rf-select2; the three search-as-you-type ones are set up by their page as Select2 (AJAX).
    preg_match_all('/<select\b[^>]*>/', $chunk, $sm);
    $plain = array_filter($sm[0], fn($x) => !str_contains($x, 'rf-select2') && !preg_match('/id="(f-res-customer|f-existing|rcpPick)"/', $x));
    ok(!$plain, "$id: every dropdown is a searchable Select2" . ($plain ? ' — ' . implode(' ', $plain) : ''));
    if ($hasMoney) ok(str_contains($chunk, 'rf-money') && str_contains($chunk, 'rf-cur'), "$id: money fields show the currency in front");
}
$gs = $src('includes/restaurant/getting_started.php');
ok(str_contains($gs, 'rgs-row') && str_contains($gs, 'rgs-pick') && str_contains($gs, 'rgs-name') && str_contains($gs, 'rgs-price') && str_contains($gs, 'rgs-stock'), 'Quick start: every row keeps its tick, name, price and stock fields');
$pur = $src('app/bms/restaurant/purchases.php');
ok(substr_count($pur, 'class="input-group rf-money"') >= 6, 'Purchases page form: price, total, selling price, other amount, paid now and pay amount all show the currency in front');
ok(substr_count($pur, 'class="rf-sec') >= 5, 'Purchases page form: sections (What did you buy? / Quantity & price / Payment) + Pay supplier sections');
ok(str_contains($pur, "\$('#rpStockFields, #rpQtySec').toggle(m === 'stock')"), 'Purchases: the quantity & price section hides with "Something else" (as before)');
ok(str_contains($pur, "cur: <?= json_encode(rfMoneyPrefix()) ?>"), 'Purchases: amounts in the page text use the same currency as the fields');

echo "\n── 3. Create = Edit, one table form ──\n";
foreach (['app/bms/restaurant/floors.php', 'app/bms/restaurant/tables.php'] as $f) {
    $s = $src($f);
    ok(str_contains($s, "includes/restaurant/table_form_modal.php") && substr_count($s, 'id="tableModal"') === 0, "$f: uses the one shared Add / Edit Table form");
}
foreach ([['app/bms/restaurant/menu.php', 'itemModal', 'editItem'], ['app/bms/restaurant/modifier_group.php', 'groupModal', 'Edit Group'], ['app/bms/restaurant/floors.php', 'tableModal', 'Edit Table']] as [$f, $id, $edit]) {
    $s = $src($f);
    ok(substr_count($s, 'id="' . $id . '"') <= 1 && str_contains($s, $edit), "$f: create and edit open the same #$id (only the title changes)");
}
ok(!preg_match('/new bootstrap\.Modal\(document\.getElementById\(\'(tableModal|groupModal|recipeModal|reservationModal|rbiModal)\'\)\)/', implode('', array_map($src, ['app/bms/restaurant/floors.php', 'app/bms/restaurant/tables.php', 'app/bms/restaurant/modifier_group.php', 'app/bms/restaurant/menu.php', 'app/bms/restaurant/reservations.php', 'includes/restaurant/buy_ingredients_modal.php']))),
   'forms reopen their one modal instance (no stacked duplicates)');

echo "\n── 4. The kit ──\n";
$kit = $src('includes/restaurant/form_kit.php');
ok(preg_match('/@media \(min-width: 768px\) \{ \.rf-grid \{ grid-template-columns: repeat\(2/', $kit) === 1, 'two columns from 768px');
ok(str_contains($kit, '.rf-grid { display: grid; grid-template-columns: minmax(0, 1fr);'), 'one column on a phone');
ok(str_contains($kit, "dropdownParent: \$modal.length ? \$modal") && str_contains($kit, 'minimumResultsForSearch: 0'), 'Select2 stays inside its modal and always offers a search box');
ok(str_contains($kit, "function rfMoneyPrefix") && str_contains($kit, "bmsCurrencySymbol()") && str_contains($kit, "'TSh'"), 'money prefix = company currency, TSh by default');
ok(str_contains($kit, '.rf-foot .btn { flex: 1 1 0;'), 'phone: Cancel and Save share the bottom row');
ok(str_contains($src('app/bms/restaurant/menu.php'), 'minimumInputLength: 0') && !preg_match('/rcpPick[\s\S]{0,300}minimumInputLength: 1/', $src('app/bms/restaurant/menu.php')), 'search dropdowns show options on click (no typing needed first)');
foreach (array_unique(array_merge(...array_values($forms))) as $f) {
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    ok($rc === 0, "php -l $f");
}

echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
