<?php
// GET phone, exclude_id — "this number was already visited", plus what the form can
// fill in for a returning client (name, business, place, how many visits).
// Staff see only THEIR earlier visits (never another staff member's); admins see anyone's.
require_once __DIR__ . '/_common.php';

$norm = frNormalizePhone((string)($_GET['phone'] ?? ''));
if (strlen($norm) < 9) frJson(['success' => true, 'match' => null]);

$where = "v.status = 'active' AND v.phone_normalized = ? AND v.visit_id <> ?";
$params = [$norm, (int)($_GET['exclude_id'] ?? 0)];
$scope = frScopeUserId(null);
if ($scope !== null) { $where .= " AND v.user_id = ?"; $params[] = $scope; }
$where .= frSubmittedOnlySql('v', $params);   // admin: another staff member's day only once submitted

try {
    $s = $pdo->prepare("SELECT v.visit_date, v.client_name, v.location, v.business_type, v.business_other,
                               u.first_name, u.last_name, u.username
                          FROM field_visits v LEFT JOIN users u ON u.user_id = v.user_id
                         WHERE $where
                         ORDER BY v.visit_date DESC, v.visit_id DESC LIMIT 1");
    $s->execute($params);
    $m = $s->fetch(PDO::FETCH_ASSOC);
    $n = 0;
    if ($m) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM field_visits v WHERE $where");
        $c->execute($params);
        $n = (int)$c->fetchColumn();
    }
    frJson(['success' => true, 'match' => $m ? [
        'visit_date'     => $m['visit_date'],
        'client_name'    => $m['client_name'],
        'location'       => $m['location'],
        'business_type'  => $m['business_type'],
        'business_other' => $m['business_other'] ?? '',
        'visits'         => $n,                 // earlier visits this caller may see
        'staff_name'     => isAdmin() ? frStaffName($m) : null,
    ] : null]);
} catch (PDOException $e) {
    error_log('field_reports/check_phone: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
