<?php
/**
 * app/bms/restaurant/menu_qr.php — printable QR cards: one per table, plus the
 * shop's menu link (restaurant_online_menu_plan.md Phase 4). Staff only.
 * GET warehouse_id. A print page (no app header), like the other print views.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/project_scope.php';
require_once __DIR__ . '/../../../core/restaurant_online.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);

if (!isAuthenticated()) { header('Location: ' . getUrl('login')); exit; }
if (!canView('restaurant_pos') || !canEdit('restaurant_pos')) { header('Location: ' . getUrl('unauthorized')); exit; }

$wid = (int)($_GET['warehouse_id'] ?? 0);
$ok  = $wid > 0 && userCan('warehouse', $wid) && restaurantModuleOn($pdo) && roSchemaReady($pdo) && restaurantShopActive($pdo, $wid);
$data = null;
if ($ok) {
    $data = roShopLinks($pdo, $wid);
    if ($data['enabled']) { roEnsureLinks($pdo, $wid); $data = roShopLinks($pdo, $wid); }
}
$company = (string)get_setting('company_name', '');
$logo    = (string)get_setting('company_logo', '');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cards = [];
if ($data && $data['enabled']) {
    foreach ($data['tables'] as $tb) if ($tb['link']) $cards[] = ['title' => sprintf(t('Table %s'), $tb['table_number']), 'sub' => $data['order_table'] ? t('Scan to see the menu and order') : t('Scan to see the menu'), 'link' => $tb['link']];
    if ($data['link']) $cards[] = ['title' => t('Our menu'), 'sub' => $data['order_pickup'] ? t('Scan to order for pickup') : t('Scan to see the menu'), 'link' => $data['link']];
}
?>
<!DOCTYPE html>
<html lang="<?= $e($_SESSION['user_lang'] ?? 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= $e(t('Table QR codes')) ?><?= $data ? ' · ' . $e($data['shop']) : '' ?></title>
<style>
  :root { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f3f4f6; color: var(--ink); font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
  .bar { position: sticky; top: 0; display: flex; gap: 12px; align-items: center; justify-content: space-between; padding: 12px 16px; background: #fff; border-bottom: 1px solid var(--line); }
  .bar b { font-size: 1rem; }
  .bar button { border: 0; border-radius: 999px; background: #0d6efd; color: #fff; padding: 10px 18px; font-weight: 600; cursor: pointer; }
  .sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; padding: 16px; max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 22px 18px; text-align: center; break-inside: avoid; page-break-inside: avoid; }
  .card .co { display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 700; font-size: .95rem; min-height: 32px; }
  .card .co img { width: 32px; height: 32px; object-fit: contain; border-radius: 8px; }
  .card .t { font-size: 1.6rem; font-weight: 800; letter-spacing: -.01em; margin: 10px 0 2px; }
  .card .s { color: var(--muted); font-size: .85rem; margin-bottom: 14px; }
  .card .qr { display: inline-block; padding: 10px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
  .card .qr img, .card .qr canvas { display: block; }
  .card .f { color: var(--muted); font-size: .7rem; margin-top: 12px; }
  .empty { max-width: 560px; margin: 60px auto; background: #fff; border-radius: 16px; padding: 24px; text-align: center; color: var(--muted); }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .sheet { grid-template-columns: repeat(2, 1fr); gap: 12mm; padding: 8mm; max-width: none; }
    .card { border: 1px dashed #9ca3af; }
  }
</style>
</head>
<body>
<div class="bar"><b><?= $e(t('Table QR codes')) ?><?= $data ? ' · ' . $e($data['shop']) : '' ?></b><?php if ($cards): ?><button type="button" id="printBtn"><?= $e(t('Print')) ?></button><?php endif; ?></div>
<?php if (!$ok): ?>
  <div class="empty"><?= $e(t('This shop is not set up as a restaurant.')) ?></div>
<?php elseif (!$cards): ?>
  <div class="empty"><?= $e($data && $data['enabled'] ? t('No tables yet — add tables to print table QR codes.') : t('Switch on "Show my menu online" first (Menu → Share menu).')) ?></div>
<?php else: ?>
  <div class="sheet">
  <?php foreach ($cards as $i => $c): ?>
    <div class="card">
      <div class="co"><?php if ($logo !== ''): ?><img src="<?= $e(getUrl($logo)) ?>" alt=""><?php endif; ?><span><?= $e($company) ?></span></div>
      <div class="t"><?= $e($c['title']) ?></div>
      <div class="s"><?= $e($c['sub']) ?></div>
      <div class="qr" data-link="<?= $e($c['link']) ?>"></div>
      <div class="f"><?= $e(t('Open your phone camera and point it at the code')) ?></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  document.querySelectorAll('.qr[data-link]').forEach(function (el) {
    try { new QRCode(el, { text: el.getAttribute('data-link'), width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M }); }
    catch (e) { el.textContent = el.getAttribute('data-link'); }
  });
  var pb = document.getElementById('printBtn'); if (pb) pb.addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
