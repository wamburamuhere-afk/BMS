<?php
/**
 * One BJP document on the company's own address (Subscription → Billing details):
 *   /subscription/invoice/231 · /subscription/quotation/N   → BJP's print template (HTML)
 *   /subscription/payment-proof/N · /receipt/N · /contract/N → the file (PDF / image)
 *
 * Company admins only. The document is fetched from BJP over the signed link (plCall);
 * BJP checks it belongs to this company's customer and is approved. The browser never
 * sees a BJP address.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/platform_link.php';

$deny = function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $e = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e . '</title></head>'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;color:#334155;display:flex;align-items:center;justify-content:center;min-height:90vh;margin:0">'
       . '<div style="text-align:center;padding:24px"><p style="font-size:1.05rem">' . $e . '</p>'
       . '<a href="' . htmlspecialchars(getUrl('subscription') . '#billing', ENT_QUOTES, 'UTF-8') . '" style="color:#0d6efd">' . htmlspecialchars(t('Back to Billing details'), ENT_QUOTES, 'UTF-8') . '</a></div></body></html>';
    exit;
};

if (!isAuthenticated()) { header('Location: ' . getUrl('login')); exit; }
if (isset($_SESSION['user_lang']) && function_exists('loadLanguage')) loadLanguage($_SESSION['user_lang']);
if (!isAdmin()) $deny(403, t('Only a company admin can open billing documents.'));
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
$route = $GLOBALS['__sd_route'] ?? null;
if ($tenantId === null || !is_array($route)) $deny(404, t('Document not found.'));
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) $deny(405, t('Method not allowed'));

$kind = (string)$route['kind'];
$id = (int)$route['id'];
$transport = $GLOBALS['__pl_transport'] ?? null;

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

if ($kind === 'invoice' || $kind === 'quotation') {
    $r = plCall($tenantId, 'document', ['type' => $kind, 'id' => $id], $transport, 30);
    if (empty($r['success']) || !is_string($r['html'] ?? null)) $deny(404, t('Document not found.'));
    header('Content-Type: text/html; charset=utf-8');
    echo $r['html'];
    exit;
}

$r = plCall($tenantId, 'file', ['kind' => $kind, 'id' => $id], $transport, 60);
$mime = (string)($r['mime'] ?? '');
if (empty($r['success']) || !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) $deny(404, t('Document not found.'));
$data = base64_decode((string)($r['data'] ?? ''), true);
if ($data === false || $data === '') $deny(404, t('Document not found.'));
$name = preg_replace('/[^A-Za-z0-9 _.-]/', '_', (string)($r['name'] ?? 'document')) ?: 'document';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($data));
echo $data;
