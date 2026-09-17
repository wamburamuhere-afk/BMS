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

if ($warehouse) {
    // available_quantity is product_stocks' own STORED GENERATED column
    // (stock_quantity - reserved_quantity) — the canonical on-hand-minus-
    // reserved figure, so this public page can never show a number that
    // disagrees with what staff see internally for the same product/warehouse.
    // Products only (is_service = 0) — a service has no meaningful quantity.
    $stmt = $pdo->prepare("
        SELECT p.product_name,
               p.selling_price,
               COALESCE(ps.available_quantity, 0) AS available_stock
        FROM products p
        JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id = ?
        WHERE p.status = 'active' AND p.is_service = 0
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$warehouse['warehouse_id']]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    body { background: #f4f6f9; font-family: 'Segoe UI', Arial, sans-serif; }
    .sc-wrap { max-width: 700px; margin: 0 auto; padding: 20px 14px 50px; }
    .sc-header { background: #0d6efd; color: #fff; padding: 18px 20px; border-radius: 12px 12px 0 0; }
    .sc-header h4 { margin: 0; font-weight: 700; }
    .sc-header small { opacity: .85; }
    .sc-body { background: #fff; border-radius: 0 0 12px 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 16px; }
    .sc-search { position: sticky; top: 0; background: #fff; padding-bottom: 10px; z-index: 5; }
    .sc-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 4px; border-bottom: 1px solid #f0f0f0; }
    .sc-item:last-child { border-bottom: none; }
    .sc-item-name { font-weight: 600; font-size: 0.92rem; }
    .sc-item-qty { font-size: 0.72rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }
    .sc-item-price { font-weight: 700; color: #0d6efd; white-space: nowrap; }
    .sc-empty-state { text-align: center; padding: 50px 20px; }
    .sc-count-badge { font-size: .72rem; }
</style>
</head>
<body>
<div class="sc-wrap">

<?php if (!$warehouse): ?>
    <div class="sc-header d-flex justify-content-between align-items-start">
        <h4><i class="bi bi-shop"></i> <?= htmlspecialchars($company_name) ?></h4>
        <a href="<?= htmlspecialchars($langToggleUrl) ?>" class="text-white small text-decoration-underline"><?= htmlspecialchars($otherLangLabel) ?></a>
    </div>
    <div class="sc-body">
        <div class="sc-empty-state">
            <i class="bi bi-x-circle text-danger" style="font-size:2.6rem;"></i>
            <h5 class="mt-3"><?= t('This link is invalid or is no longer available') ?></h5>
            <p class="text-muted small"><?= t('Please ask the shop for a current link.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="sc-header d-flex justify-content-between align-items-start">
        <div>
            <h4><i class="bi bi-shop"></i> <?= htmlspecialchars($warehouse['warehouse_name']) ?></h4>
            <small><?= htmlspecialchars($company_name) ?></small>
        </div>
        <a href="<?= htmlspecialchars($langToggleUrl) ?>" class="text-white small text-decoration-underline"><?= htmlspecialchars($otherLangLabel) ?></a>
    </div>
    <div class="sc-body">
        <div class="sc-search">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small"><?= t('Browse what this shop has in stock') ?></span>
                <span class="badge bg-light text-dark border sc-count-badge" id="scCount"><?= count($products) ?> <?= t('items') ?></span>
            </div>
            <input type="text" class="form-control" id="scSearchInput" placeholder="<?= t('Search products…') ?>" autocomplete="off">
        </div>
        <div id="scList">
            <?php if (empty($products)): ?>
                <div class="sc-empty-state">
                    <i class="bi bi-inbox" style="font-size:2.4rem;color:#ccc;"></i>
                    <p class="text-muted mt-2"><?= t('Nothing to show here yet.') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $p): ?>
                <div class="sc-item" data-name="<?= htmlspecialchars(mb_strtolower($p['product_name'])) ?>">
                    <div>
                        <div class="sc-item-name"><?= htmlspecialchars($p['product_name']) ?></div>
                        <div class="sc-item-qty"><?= (float)$p['available_stock'] > 0 ? t('In Stock') : t('Out of Stock') ?> &middot; <?= number_format((float)$p['available_stock'], 0) ?> <?= t('available') ?></div>
                    </div>
                    <div class="sc-item-price"><?= format_currency($p['selling_price']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div id="scNoMatch" class="sc-empty-state d-none">
            <i class="bi bi-search" style="font-size:2.2rem;color:#ccc;"></i>
            <p class="text-muted mt-2"><?= t('No products match your search.') ?></p>
        </div>
    </div>

    <script>
    (function () {
        var input = document.getElementById('scSearchInput');
        var items = Array.prototype.slice.call(document.querySelectorAll('#scList .sc-item'));
        var noMatch = document.getElementById('scNoMatch');
        var countBadge = document.getElementById('scCount');
        var totalLabel = <?= json_encode(t('items')) ?>;

        input.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var visible = 0;
            items.forEach(function (el) {
                var match = q === '' || el.getAttribute('data-name').indexOf(q) !== -1;
                el.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            noMatch.classList.toggle('d-none', visible !== 0 || items.length === 0);
            countBadge.textContent = visible + ' ' + totalLabel;
        });
    })();
    </script>
<?php endif; ?>

</div>
</body>
</html>
