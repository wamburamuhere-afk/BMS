<?php
// GET phone, exclude_id — "this number was already visited". Staff see only THEIR
// earlier visits (never another staff member's); admins see anyone's.
require_once __DIR__ . '/_common.php';

$norm = frNormalizePhone((string)($_GET['phone'] ?? ''));
if (strlen($norm) < 9) frJson(['success' => true, 'match' => null]);

$sql = "SELECT v.visit_date, v.client_name, v.location, u.first_name, u.last_name, u.username
        FROM field_visits v LEFT JOIN users u ON u.user_id = v.user_id
        WHERE v.status = 'active' AND v.phone_normalized = ? AND v.visit_id <> ?";
$params = [$norm, (int)($_GET['exclude_id'] ?? 0)];
$scope = frScopeUserId(null);
if ($scope !== null) { $sql .= " AND v.user_id = ?"; $params[] = $scope; }
$sql .= " ORDER BY v.visit_date DESC, v.visit_id DESC LIMIT 1";

try {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $m = $s->fetch(PDO::FETCH_ASSOC);
    frJson(['success' => true, 'match' => $m ? [
        'visit_date' => $m['visit_date'],
        'client_name' => $m['client_name'],
        'location' => $m['location'],
        'staff_name' => isAdmin() ? frStaffName($m) : null,
    ] : null]);
} catch (PDOException $e) {
    error_log('field_reports/check_phone: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
