<?php
/**
 * api/support_attachment.php?id=N — download one support attachment after an access check
 * (same company, and the requester or a company admin; never a BJP internal note's file).
 * Files live in uploads/support/<company>/ under a deny-all .htaccess with random names.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/support_requests.php';

if (!isAuthenticated()) { http_response_code(401); exit('Unauthorized'); }
if (!canView('help')) { http_response_code(403); exit('Permission denied'); }

$id = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
$a = $id ? supportAttachmentForUser($id, supportCurrentUser()) : null;
if (!$a) { http_response_code(404); exit('Not found'); }

$inline = in_array($a['mime'], ['image/jpeg', 'image/png'], true) && ($_GET['view'] ?? '') === '1';
$fname = preg_replace('/[^\w .()-]/u', '_', (string)$a['original_name']);
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($a['path']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode((string)$a['original_name']));
readfile($a['path']);
