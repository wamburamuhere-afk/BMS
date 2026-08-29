<?php
// api/pos/save_holiday.php — create/update a public holiday.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('company_calendar')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage the company calendar']); exit; }

try {
    $id       = (int)($_POST['holiday_id'] ?? 0);
    $name     = trim($_POST['holiday_name'] ?? '');
    $date     = trim($_POST['holiday_date'] ?? '');
    $type     = $_POST['holiday_type'] ?? 'national';
    $recurring = !empty($_POST['recurring']) ? 1 : 0;
    $country  = trim($_POST['country'] ?? '') ?: 'Tanzania';
    $region   = trim($_POST['region'] ?? '');
    $desc     = trim($_POST['description'] ?? '');

    if ($name === '') throw new Exception('Name is required.');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) throw new Exception('A valid date is required.');
    if (!in_array($type, ['national', 'regional', 'religious', 'company'], true)) $type = 'national';

    if ($id > 0) {
        $pdo->prepare("UPDATE public_holidays
                          SET holiday_name = ?, holiday_date = ?, holiday_type = ?, recurring = ?,
                              country = ?, region = ?, description = ?
                        WHERE holiday_id = ?")
            ->execute([$name, $date, $type, $recurring, $country, ($region !== '' ? $region : null), ($desc !== '' ? $desc : null), $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated public holiday", "$name (ID: $id)");
        echo json_encode(['success' => true, 'message' => 'Holiday updated.', 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO public_holidays (holiday_name, holiday_date, holiday_type, recurring, country, region, description, status, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())")
            ->execute([$name, $date, $type, $recurring, $country, ($region !== '' ? $region : null), ($desc !== '' ? $desc : null), $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created public holiday", "$name (ID: $newId)");
        echo json_encode(['success' => true, 'message' => 'Holiday created.', 'id' => $newId]);
    }

} catch (Exception $e) {
    error_log('save_holiday error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
