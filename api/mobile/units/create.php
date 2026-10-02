<?php
// scope-audit: skip — delegates to api/save_unit.php; units are a global catalog
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();
if (trim($_POST['unit_name'] ?? '') === '' || trim($_POST['unit_code'] ?? '') === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'unit_name and unit_code are required']); exit; }
mobileRun($pdo, 'units/create', $_POST['client_uuid'] ?? null, function () {
    global $pdo;
    require __DIR__ . '/../../save_unit.php';
});
