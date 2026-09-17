<?php
/**
 * shop_catalog.php — public, unauthenticated, read-only product list for
 * ONE shop (warehouse), reached only via the link a shop owner generates
 * from warehouse_view.php (Simple POS only). No session, no login, no
 * write capability anywhere on this page — a customer can look and search,
 * nothing else.
 *
 * Security model mirrors sign_document.php's external-signing links: the
 * raw token is shown to the admin exactly once (in generate_shop_catalog_link.php's
 * JSON response) and only its SHA-256 hash is ever stored
 * (warehouses.public_catalog_token_hash) — a database leak never yields a
 * usable link, and an invalid/unknown token renders the exact same generic
 * message as an intentionally-revoked one, so nothing here confirms or
 * denies whether a given token ever existed.
 *
 * Deliberately does NOT call includeHeader() (that forces a login redirect
 * on every other page) — same standalone-page pattern as login.php and
 * sign_document.php, via the full roots.php bootstrap so getUrl()/
 * format_currency()/get_setting() etc. are available (roots.php itself
 * enforces no authentication — that only happens inside header.php).
 */
require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/core/pos_nav.php';
require_once __DIR__ . '/core/warehouse_scope.php';
require_once __DIR__ . '/core/pos_price_groups.php';

// No login here to carry a saved language preference — a plain, explicit
// ?lang= switch instead (defaults to English, same as the rest of the app
// when nothing else is set). Whitelisted against SUPPORTED_LANGUAGES so an
// arbitrary query value can't do anything but silently fall back.
$currentLang = in_array($_GET['lang'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['lang'] : 'en';
loadLanguage($currentLang);
$otherLang = $currentLang === 'sw' ? 'en' : 'sw';
$otherLangLabel = $currentLang === 'sw' ? 'English' : 'Kiswahili';

$token = trim((string)($_GET['token'] ?? ''));
$langToggleUrl = '?' . http_build_query(array_filter(['token' => $token, 'lang' => $otherLang]));
$warehouse = null;
$products = [];

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT warehouse_id, warehouse_name, warehouse_code
        FROM warehouses
        WHERE public_catalog_token_hash = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Re-checked at VIEW time, not just at generation time — if a tenant's
    // Simple POS is later turned off, every link generated under it must
    // stop working immediately, not keep serving a feature that's no
    // longer meant to exist for that tenant. Same reasoning for the
    // warehouse itself: only an active, in-scope-of-nothing-special public
    // warehouse (no per-user scope applies here — there is no user) is
    // ever eligible.
    if ($warehouse && !posSimpleModeEnabled()) {
        $warehouse = null;
    }
}

$categories = [];
$hasWholesalePricing = false;

if ($warehouse) {
    // available_quantity is product_stocks' own STORED GENERATED column
    // (stock_quantity - reserved_quantity) — the canonical on-hand-minus-
    // reserved figure, so this public page can never show a number that
    // disagrees with what staff see internally for the same product/warehouse.
    // Products only (is_service = 0) — a service has no meaningful quantity.
    //
    // Wholesale price: products.wholesale_price is legacy/unread since Phase
    // 14 (pos_upgrade_plan.md §8, see api/pos/quick_restock.php's own
    // comment) — the real, live wholesale price a shop sets via Restock
    // lives in product_price_group_prices under the seeded "Wholesale"
    // price group. Falls back to plain selling_price when no override was
    // ever set for a product, same convention the POS terminal itself uses.
    $wholesaleGroupId = wholesalePriceGroupId($pdo) ?? 0;
    $stmt = $pdo->prepare("
        SELECT p.product_name,
               p.image_url,
               p.category_id,
               c.category_name,
               p.selling_price,
               COALESCE(pgp.price, p.selling_price) AS wholesale_price,
               COALESCE(ps.available_quantity, 0) AS available_stock
        FROM products p
        JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id = ?
        LEFT JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN product_price_group_prices pgp ON pgp.product_id = p.product_id AND pgp.price_group_id = ?
        WHERE p.status = 'active' AND p.is_service = 0
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$warehouse['warehouse_id'], $wholesaleGroupId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        if (!empty($p['category_id']) && !empty($p['category_name'])) {
            $categories[(int)$p['category_id']] = $p['category_name'];
        }
        // The Jumla/Reja Reja toggle only appears when it would actually
        // change something — a shop that never set a wholesale override
        // anywhere shouldn't see a switch that does nothing.
        if (abs((float)$p['wholesale_price'] - (float)$p['selling_price']) > 0.001) {
            $hasWholesalePricing = true;
        }
    }
    asort($categories, SORT_STRING);
}

$company_name = get_setting('company_name', 'Business Management System');
$page_title = $warehouse ? htmlspecialchars($warehouse['warehouse_name']) : t('Shop Catalog');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ?> | <?= htmlspecialchars($company_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
    :root { --sc-brand: #0d6efd; }
    * { -webkit-tap-highlight-color: transparent; }
    body { background: #f2f4f8; font-family: 'Segoe UI', Arial, sans-serif; }
    .sc-wrap { max-width: 1040px; margin: 0 auto; padding: 0 0 60px; }
    .sc-header { background: linear-gradient(135deg, var(--sc-brand), #0a58ca); color: #fff; padding: 22px 20px 60px; }
    .sc-header h4 { margin: 0; font-weight: 800; letter-spacing: -.01em; }
    .sc-header small { opacity: .85; }
    .sc-lang-link { color: #fff; opacity: .85; font-size: .8rem; text-decoration: underline; white-space: nowrap; }

    .sc-toolbar { margin: -42px 14px 0; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(16,24,40,.10); padding: 14px 16px; position: relative; z-index: 6; }
    .sc-toolbar-row { display: flex; align-items: center; gap: 10px; }
    .sc-search-box { position: relative; flex: 1 1 auto; }
    .sc-search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #98a2b3; font-size: 1rem; pointer-events: none; }
    .sc-search-box input { width: 100%; border: 1px solid #e4e7ec; background: #f9fafb; border-radius: 999px; padding: 11px 14px 11px 40px; font-size: .92rem; outline: none; transition: border-color .2s, background .2s, box-shadow .2s; }
    .sc-search-box input:focus { border-color: var(--sc-brand); background: #fff; box-shadow: 0 0 0 4px rgba(13,110,253,.12); }
    .sc-filter-btn { flex: 0 0 auto; border: 1px solid #e4e7ec; background: #f9fafb; color: #344054; border-radius: 999px; padding: 10px 14px; font-size: .85rem; font-weight: 600; display: flex; align-items: center; gap: 6px; transition: background .2s, border-color .2s; }
    .sc-filter-btn.active, .sc-filter-btn:hover { background: #eaf2ff; border-color: #b6d4fe; color: var(--sc-brand); }
    .sc-filter-btn i.bi-chevron-down { transition: transform .25s ease; font-size: .75rem; }
    .sc-filter-btn.open i.bi-chevron-down { transform: rotate(180deg); }

    /* Filter disclosure panel */
    .sc-filter-panel { max-height: 0; overflow: hidden; transition: max-height .3s ease, opacity .25s ease, margin-top .3s ease; opacity: 0; margin-top: 0; }
    .sc-filter-panel.open { max-height: 300px; opacity: 1; margin-top: 12px; }
    .sc-chip-row { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 10px; border-top: 1px solid #f0f2f5; }
    .sc-chip { border: 1px solid #e4e7ec; background: #fff; color: #475467; border-radius: 999px; padding: 6px 14px; font-size: .8rem; font-weight: 600; transition: all .2s; }
    .sc-chip.active { background: var(--sc-brand); border-color: var(--sc-brand); color: #fff; }

    /* Retail / Wholesale segmented toggle */
    .sc-price-toggle { position: relative; display: inline-flex; background: #eef1f5; border-radius: 999px; padding: 4px; margin-top: 12px; }
    .sc-toggle-btn { position: relative; z-index: 2; border: none; background: transparent; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: .82rem; color: #475467; transition: color .3s; cursor: pointer; }
    .sc-toggle-btn.active { color: #fff; }
    .sc-toggle-highlight { position: absolute; top: 4px; left: 4px; width: calc(50% - 4px); height: calc(100% - 8px); background: var(--sc-brand); border-radius: 999px; transition: transform .28s cubic-bezier(.4,0,.2,1); z-index: 1; }

    .sc-meta-row { display: flex; justify-content: space-between; align-items: center; margin: 14px 14px 10px; color: #667085; font-size: .8rem; }

    /* Product grid */
    .sc-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; padding: 0 14px; }
    @media (min-width: 560px) { .sc-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 860px) { .sc-grid { grid-template-columns: repeat(4, 1fr); } }
    .sc-card { background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.06); overflow: hidden; display: flex; flex-direction: column; animation: scFadeIn .35s ease both; transition: transform .15s ease, box-shadow .15s ease; }
    .sc-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(16,24,40,.10); }
    @keyframes scFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    .sc-card-img { position: relative; height: 108px; background: #f4f6f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .sc-card-img img { width: 100%; height: 100%; object-fit: cover; }
    .sc-card-img i { font-size: 2.3rem; color: #c7cdd6; }
    .sc-card-badge { position: absolute; top: 6px; right: 6px; font-size: .62rem; font-weight: 700; padding: 3px 7px; border-radius: 999px; }
    .sc-card-body { padding: 9px 10px 11px; }
    .sc-card-name { font-weight: 700; font-size: .82rem; line-height: 1.25; height: 2.1em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .sc-card-cat { font-size: .66rem; color: #98a2b3; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }
    .sc-card-price { font-weight: 800; color: var(--sc-brand); font-size: .93rem; margin-top: 6px; }
    .sc-card-qty { font-size: .68rem; color: #98a2b3; margin-top: 2px; }
    .sc-card-qty.low { color: #d92d20; font-weight: 700; }

    .sc-empty-state { text-align: center; padding: 60px 20px; }
    .sc-footer { text-align: center; color: #98a2b3; font-size: .72rem; margin-top: 30px; }
</style>
</head>
<body>
<div class="sc-wrap">

<?php if (!$warehouse): ?>
    <div class="sc-header d-flex justify-content-between align-items-start" style="padding-bottom:22px;">
        <h4><i class="bi bi-shop"></i> <?= htmlspecialchars($company_name) ?></h4>
        <a href="<?= htmlspecialchars($langToggleUrl) ?>" class="sc-lang-link"><?= htmlspecialchars($otherLangLabel) ?></a>
    </div>
    <div class="sc-empty-state">
        <i class="bi bi-x-circle text-danger" style="font-size:2.6rem;"></i>
        <h5 class="mt-3"><?= t('This link is invalid or is no longer available') ?></h5>
        <p class="text-muted small"><?= t('Please ask the shop for a current link.') ?></p>
    </div>
<?php else: ?>
    <div class="sc-header d-flex justify-content-between align-items-start">
        <div>
            <h4><i class="bi bi-shop"></i> <?= htmlspecialchars($warehouse['warehouse_name']) ?></h4>
            <small><?= htmlspecialchars($company_name) ?></small>
        </div>
        <a href="<?= htmlspecialchars($langToggleUrl) ?>" class="sc-lang-link"><?= htmlspecialchars($otherLangLabel) ?></a>
    </div>

    <div class="sc-toolbar">
        <div class="sc-toolbar-row">
            <div class="sc-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="scSearchInput" placeholder="<?= t('Search products…') ?>" autocomplete="off">
            </div>
            <?php if (!empty($categories)): ?>
            <button type="button" class="sc-filter-btn" id="scFilterToggle">
                <i class="bi bi-sliders"></i> <?= t('Filter') ?> <i class="bi bi-chevron-down"></i>
            </button>
            <?php endif; ?>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="sc-filter-panel" id="scFilterPanel">
            <div class="sc-chip-row" id="scCategoryChips">
                <button type="button" class="sc-chip active" data-category="all"><?= t('All') ?></button>
                <?php foreach ($categories as $catId => $catName): ?>
                <button type="button" class="sc-chip" data-category="<?= (int)$catId ?>"><?= htmlspecialchars($catName) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasWholesalePricing): ?>
        <div class="sc-price-toggle" id="scPriceToggle" role="tablist">
            <span class="sc-toggle-highlight" id="scToggleHighlight"></span>
            <button type="button" class="sc-toggle-btn active" data-mode="retail"><?= t('Retail') ?></button>
            <button type="button" class="sc-toggle-btn" data-mode="wholesale"><?= t('Wholesale') ?></button>
        </div>
        <?php endif; ?>
    </div>

    <div class="sc-meta-row">
        <span><?= t('Browse what this shop has in stock') ?></span>
        <span id="scCount"><?= count($products) ?> <?= t('items') ?></span>
    </div>

    <?php if (empty($products)): ?>
        <div class="sc-empty-state">
            <i class="bi bi-inbox" style="font-size:2.4rem;color:#ccc;"></i>
            <p class="text-muted mt-2"><?= t('Nothing to show here yet.') ?></p>
        </div>
    <?php else: ?>
        <div class="sc-grid" id="scGrid">
            <?php foreach ($products as $p):
                $inStock = (float)$p['available_stock'] > 0;
                $imgUrl  = !empty($p['image_url']) ? getUrl($p['image_url']) : '';
            ?>
            <div class="sc-card"
                 data-name="<?= htmlspecialchars(mb_strtolower($p['product_name'])) ?>"
                 data-category="<?= (int)($p['category_id'] ?? 0) ?>">
                <div class="sc-card-img">
                    <?php if ($imgUrl): ?>
                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>" loading="lazy"
                             onerror="this.replaceWith(Object.assign(document.createElement('i'),{className:'bi bi-box-seam'}))">
                    <?php else: ?>
                        <i class="bi bi-box-seam"></i>
                    <?php endif; ?>
                    <?php if (!$inStock): ?>
                        <span class="sc-card-badge bg-danger text-white"><?= t('Out of Stock') ?></span>
                    <?php endif; ?>
                </div>
                <div class="sc-card-body">
                    <div class="sc-card-name" title="<?= htmlspecialchars($p['product_name']) ?>"><?= htmlspecialchars($p['product_name']) ?></div>
                    <?php if (!empty($p['category_name'])): ?>
                        <div class="sc-card-cat"><?= htmlspecialchars($p['category_name']) ?></div>
                    <?php endif; ?>
                    <div class="sc-card-price"
                         data-retail="<?= htmlspecialchars(format_currency($p['selling_price'])) ?>"
                         data-wholesale="<?= htmlspecialchars(format_currency($p['wholesale_price'])) ?>"><?= format_currency($p['selling_price']) ?></div>
                    <div class="sc-card-qty <?= $inStock ? '' : 'low' ?>">
                        <?= $inStock ? t('In Stock') : t('Out of Stock') ?> &middot; <?= number_format((float)$p['available_stock'], 0) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="sc-empty-state d-none" id="scNoMatch">
        <i class="bi bi-search" style="font-size:2.2rem;color:#ccc;"></i>
        <p class="text-muted mt-2"><?= t('No products match your search.') ?></p>
    </div>

    <div class="sc-footer"><?= htmlspecialchars($company_name) ?></div>

    <script>
    (function () {
        var input       = document.getElementById('scSearchInput');
        var cards       = Array.prototype.slice.call(document.querySelectorAll('#scGrid .sc-card'));
        var noMatch     = document.getElementById('scNoMatch');
        var countBadge  = document.getElementById('scCount');
        var totalLabel  = <?= json_encode(t('items')) ?>;
        var activeCategory = 'all';

        function applyFilters() {
            var q = (input.value || '').trim().toLowerCase();
            var visible = 0;
            cards.forEach(function (el) {
                var nameMatch = q === '' || el.getAttribute('data-name').indexOf(q) !== -1;
                var catMatch = activeCategory === 'all' || el.getAttribute('data-category') === activeCategory;
                var match = nameMatch && catMatch;
                el.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            noMatch.classList.toggle('d-none', visible !== 0 || cards.length === 0);
            countBadge.textContent = visible + ' ' + totalLabel;
        }
        if (input) input.addEventListener('input', applyFilters);

        // Filter disclosure
        var filterToggle = document.getElementById('scFilterToggle');
        var filterPanel = document.getElementById('scFilterPanel');
        if (filterToggle && filterPanel) {
            filterToggle.addEventListener('click', function () {
                filterToggle.classList.toggle('open');
                filterToggle.classList.toggle('active');
                filterPanel.classList.toggle('open');
            });
        }
        var chips = Array.prototype.slice.call(document.querySelectorAll('#scCategoryChips .sc-chip'));
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                activeCategory = chip.getAttribute('data-category');
                applyFilters();
            });
        });

        // Reja Reja / Jumla segmented toggle — pre-rendered, formatted
        // strings swapped in directly (server is the one source of truth
        // for currency formatting, never re-derived in JS).
        var priceToggle = document.getElementById('scPriceToggle');
        if (priceToggle) {
            var highlight = document.getElementById('scToggleHighlight');
            var toggleBtns = Array.prototype.slice.call(priceToggle.querySelectorAll('.sc-toggle-btn'));
            var priceEls = Array.prototype.slice.call(document.querySelectorAll('.sc-card-price'));
            toggleBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var mode = btn.getAttribute('data-mode');
                    toggleBtns.forEach(function (b) { b.classList.toggle('active', b === btn); });
                    highlight.style.transform = mode === 'wholesale' ? 'translateX(100%)' : 'translateX(0)';
                    priceEls.forEach(function (el) {
                        el.textContent = el.getAttribute(mode === 'wholesale' ? 'data-wholesale' : 'data-retail');
                    });
                });
            });
        }
    })();
    </script>
<?php endif; ?>

</div>
</body>
</html>
