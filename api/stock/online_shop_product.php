<?php
// scope-audit: skip — products.online_hidden is company-wide (one product list for every shop); gated by canEdit('warehouses')
/**
 * API: show / hide one product in the online shop (retail_online_shop_plan.md Phase 3).
 * POST product_id, hidden (1 = hide, 0 = show). Smart POS, canEdit('warehouses'), CSRF.
 * Hiding affects every shop's online shop; the till and stock are not touched.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/retail_online.php';

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())                            $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canEdit('warehouses'))                        $out(403, ['success' => false, 'message' => t('Permission denied')]);
if (!posSimpleModeEnabled() || !rsSchemaReady($pdo)) $out(403, ['success' => false, 'message' => t('The online shop is available in Smart POS.')]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(405, ['success' => false, 'message' => t('Method not allowed')]);
csrf_check();

$pid    = (int)($_POST['product_id'] ?? 0);
$hidden = !empty($_POST['hidden']) && $_POST['hidden'] !== '0' ? 1 : 0;
$st = $pdo->prepare("SELECT product_name, is_service FROM products WHERE product_id = ? AND status <> 'deleted'");
$st->execute([$pid]);
$p = $st->fetch(PDO::FETCH_ASSOC);
if (!$p) $out(404, ['success' => false, 'message' => t('Product not found.')]);
if ((int)$p['is_service'] === 1) $out(422, ['success' => false, 'message' => t('Services are never shown in the online shop.')]);

$pdo->prepare("UPDATE products SET online_hidden = ? WHERE product_id = ?")->execute([$hidden, $pid]);
logActivity($pdo, $_SESSION['user_id'], sprintf('%s "%s" %s the online shop', $hidden ? 'Hid' : 'Showed', $p['product_name'], $hidden ? 'from' : 'in'));
$out(200, ['success' => true, 'hidden' => (bool)$hidden,
           'message' => $hidden ? t('Hidden from the online shop.') : t('Shown in the online shop.')]);
