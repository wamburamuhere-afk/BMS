<?php
// scope-audit: skip — delegates to api/create_category.php; categories are a global catalog
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('categories')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();
$_POST['type'] = in_array($_POST['type'] ?? 'product', ['product','service','expense','asset','other'], true) ? ($_POST['type'] ?? 'product') : 'product';
if (trim($_POST['category_name'] ?? '') === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'category_name is required']); exit; }
mobileRun($pdo, 'categories/create', $_POST['client_uuid'] ?? null, function () {
    global $pdo;
    require __DIR__ . '/../../create_category.php';
});
