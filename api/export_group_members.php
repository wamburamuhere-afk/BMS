<?php
// api/export_group_members.php — download a customer group's members as CSV
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_groups.php';
global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}
if (!canView('customer_groups')) {
    http_response_code(403);
    exit('Permission denied');
}

$id = (int)($_GET['group_id'] ?? 0);
$g = $id > 0 ? customerGroupFind($pdo, $id) : null;
if (!$g) {
    http_response_code(404);
    exit('Group not found');
}

$st = $pdo->prepare("
    SELECT c.customer_code, c.customer_name, c.company_name, c.customer_type, c.phone, c.mobile, c.email,
           c.occupation_business, c.city, c.country, cgc.added_at, u.username AS added_by
      FROM customer_group_customers cgc
      JOIN customers c ON c.customer_id = cgc.customer_id AND c.status != 'deleted'
      LEFT JOIN users u ON u.user_id = cgc.added_by
     WHERE cgc.group_id = ?
     ORDER BY c.customer_name
");
$st->execute([$id]);

// A cell starting with = + - @ is read as a formula by spreadsheet apps — prefix it.
$cell = static fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;

logActivity($pdo, $_SESSION['user_id'], "Exported members of customer group #$id: {$g['group_name']}");

$filename = 'group_members_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $g['group_name']) . '_' . date('Y-m-d') . '.csv';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads names correctly
fputcsv($out, ['Code', 'Customer', 'Company', 'Type', 'Phone', 'Mobile', 'Email', 'Occupation / Business', 'City', 'Country', 'Added On', 'Added By']);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    fputcsv($out, array_map($cell, array_values($r)));
}
fclose($out);
