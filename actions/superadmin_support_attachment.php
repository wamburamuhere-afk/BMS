<?php
/**
 * actions/superadmin_support_attachment.php?id=N — a BJP agent downloads one attachment
 * on a request that reached BJP (internal-note files included). Superadmin host + session only.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/support_inbox.php';
require_once __DIR__ . '/../helpers.php';

assertSuperadminHost();
superadminSessionReady();
if (currentSuperadmin() === null) { http_response_code(401); exit('Your session has ended. Please sign in again.'); }

$id = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
$a = $id ? supportAttachmentForAgent($id) : null;
if (!$a) { http_response_code(404); exit('Not found'); }

$fname = preg_replace('/[^\w .()-]/u', '_', (string)$a['original_name']);
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($a['path']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode((string)$a['original_name']));
readfile($a['path']);
