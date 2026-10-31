<?php
/**
 * Kitchen slip (restaurant_plan.md Phase 3, "Printer" kitchen mode).
 * GET: warehouse_id + hold_id (table order) | sale_id (paid take-away/delivery)
 * A narrow page for the shop's receipt printer (Selling Settings → paper width):
 * order label, time, who took it, and only the items that need preparing (they have
 * a Kitchen/Bar area), each with its add-ons/note. Opens the print dialog itself.
 * Gate: Restaurant POS + Smart POS on, Restaurant/Hybrid shop, canCreate('pos').
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated() || !canView('restaurant_pos') || !canCreate('pos')) { http_response_code(403); exit(t('Permission denied')); }

global $pdo;
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$hold_id      = (int)($_GET['hold_id'] ?? 0);
$sale_id      = (int)($_GET['sale_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id) || !restaurantShopActive($pdo, $warehouse_id)) {
    http_response_code(403); exit(t('Permission denied'));
}

$items = []; $label = ''; $by = '';
if ($hold_id > 0) {
    $st = $pdo->prepare("SELECT h.items_data, h.hold_reference, rt.table_number, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS by_name
                           FROM pos_held_sales h LEFT JOIN restaurant_tables rt ON rt.table_id = h.table_id LEFT JOIN users u ON u.user_id = h.user_id
                          WHERE h.hold_id = ? AND h.warehouse_id = ?");
    $st->execute([$hold_id, $warehouse_id]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) { http_response_code(404); exit(t('Held order not found for this table.')); }
    foreach (json_decode((string)$h['items_data'], true) ?: [] as $it) {
        $mods = is_array($it['modifiers'] ?? null) ? array_filter(array_map(fn($m) => (string)($m['option_name'] ?? ''), $it['modifiers'])) : [];
        if (trim((string)($it['note'] ?? '')) !== '') $mods[] = trim((string)$it['note']);
        $items[] = ['product_id' => (int)($it['product_id'] ?? 0), 'qty' => (float)($it['quantity'] ?? 1), 'extra' => implode(', ', $mods)];
    }
    $label = $h['table_number'] !== null ? t('Table') . ' ' . $h['table_number'] : (string)$h['hold_reference'];
    $by = $h['by_name'];
} elseif ($sale_id > 0) {
    $st = $pdo->prepare("SELECT s.receipt_number, s.sale_type, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS by_name
                           FROM pos_sales s LEFT JOIN users u ON u.user_id = s.user_id WHERE s.sale_id = ? AND s.warehouse_id = ?");
    $st->execute([$sale_id, $warehouse_id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) { http_response_code(404); exit(t('Sale not found.')); }
    $ln = $pdo->prepare("SELECT sale_item_id, product_id, quantity, description FROM pos_sale_items WHERE sale_id = ?");
    $ln->execute([$sale_id]);
    $md = $pdo->prepare("SELECT option_name FROM pos_sale_item_modifiers WHERE sale_item_id = ?");
    foreach ($ln->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $md->execute([(int)$l['sale_item_id']]);
        $mods = $md->fetchAll(PDO::FETCH_COLUMN);
        if (trim((string)$l['description']) !== '') $mods[] = trim((string)$l['description']);
        $items[] = ['product_id' => (int)$l['product_id'], 'qty' => (float)$l['quantity'], 'extra' => implode(', ', $mods)];
    }
    $types = ['take_away' => t('Take away'), 'delivery' => t('Delivery'), 'dine_in' => t('Dine in')];
    $label = ($types[(string)$s['sale_type']] ?? t('Order')) . ' · ' . $s['receipt_number'];
    $by = $s['by_name'];
} else {
    http_response_code(400); exit(t('Nothing to print.'));
}

// Only items that need preparing (they have a Kitchen/Bar area), grouped by area.
$byArea = [];
if ($items) {
    $ids = array_values(array_unique(array_column($items, 'product_id')));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pr = $pdo->prepare("SELECT p.product_id, p.product_name, ks.name AS area FROM products p JOIN kitchen_stations ks ON ks.station_id = p.kitchen_station_id WHERE p.product_id IN ($ph)");
    $pr->execute($ids);
    $info = [];
    foreach ($pr->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(int)$r['product_id']] = $r;
    foreach ($items as $it) {
        if (!isset($info[$it['product_id']])) continue;
        $area = in_array($info[$it['product_id']]['area'], ['Kitchen', 'Bar'], true) ? t($info[$it['product_id']]['area']) : $info[$it['product_id']]['area'];
        $byArea[$area][] = $it + ['name' => $info[$it['product_id']]['product_name']];
    }
}
$width = get_setting('pos_receipt_width', '80') === '58' ? 58 : 80;
$fmtQty = fn($q) => rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
?><!doctype html>
<html lang="<?= htmlspecialchars($_SESSION['user_lang'] ?? 'en') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('Kitchen slip')) ?></title>
<style>
  @page { size: <?= $width ?>mm auto; margin: 2mm; }
  body { font-family: "Courier New", monospace; width: <?= $width - 6 ?>mm; margin: 0 auto; color:#000; background:#fff; font-size: <?= $width === 58 ? 12 : 14 ?>px; }
  h1 { font-size: 1.6em; margin: 4px 0; text-align:center; }
  .meta { text-align:center; font-size:.9em; border-bottom:1px dashed #000; padding-bottom:4px; margin-bottom:6px; }
  .area { font-weight:bold; text-transform:uppercase; border-bottom:1px solid #000; margin-top:8px; }
  .line { display:flex; gap:6px; margin:4px 0; font-size:1.15em; }
  .qty { font-weight:bold; min-width:2.5em; }
  .extra { font-size:.85em; padding-left:3em; margin-top:-2px; }
  .none { text-align:center; margin:12px 0; }
  .noprint { text-align:center; margin:10px 0; font-family: sans-serif; }
  @media print { .noprint { display:none; } }
</style>
</head>
<body>
  <h1><?= htmlspecialchars($label) ?></h1>
  <div class="meta"><?= date('d/m/Y H:i') ?><?= $by !== '' ? ' · ' . htmlspecialchars($by) : '' ?></div>
  <?php if (!$byArea): ?>
    <div class="none"><?= htmlspecialchars(t('Nothing on this order needs preparing.')) ?></div>
  <?php endif; ?>
  <?php foreach ($byArea as $area => $lines): ?>
    <div class="area"><?= htmlspecialchars($area) ?></div>
    <?php foreach ($lines as $l): ?>
      <div class="line"><span class="qty"><?= $fmtQty($l['qty']) ?> ×</span><span><?= htmlspecialchars($l['name']) ?></span></div>
      <?php if ($l['extra'] !== ''): ?><div class="extra">» <?= htmlspecialchars($l['extra']) ?></div><?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="noprint"><button onclick="window.print()"><?= htmlspecialchars(t('Print')) ?></button> <button onclick="window.close()"><?= htmlspecialchars(t('Close')) ?></button></div>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
