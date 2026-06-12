<?php
require_once __DIR__ . '/../../roots.php';

// Auth + permission — redirect style since this is a download, not an AJAX call
if (!isAuthenticated()) {
    header('Location: ' . getUrl('login.php'));
    exit;
}
if (!canView('crm_leads')) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=leads_export_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');

// BOM for Excel compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [
    'Lead Code', 'First Name', 'Last Name', 'Company', 'Email', 'Phone', 'Mobile',
    'City', 'Country', 'Source', 'Stage', 'Lead Value', 'Probability (%)',
    'Expected Close', 'Assigned To', 'Status', 'Converted', 'Created By', 'Created At'
]);

try {
    $sql = "
        SELECT cl.lead_code, cl.first_name, cl.last_name, cl.company_name, cl.email,
               cl.phone, cl.mobile, cl.city, cl.country, cl.lead_source,
               ps.stage_name, cl.lead_value, cl.probability, cl.expected_close_date,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ua.first_name, ua.last_name)), ''), ua.username) AS assigned_name,
               cl.status, cl.converted, cl.created_at,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', uc.first_name, uc.last_name)), ''), uc.username) AS created_by_name
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        LEFT JOIN users ua ON cl.assigned_to = ua.user_id
        LEFT JOIN users uc ON cl.created_by  = uc.user_id
        WHERE cl.status != 'deleted'
    ";
    $params = [];

    if (!empty($_GET['stage_id'])) {
        $sql .= " AND cl.pipeline_stage_id = ?";
        $params[] = intval($_GET['stage_id']);
    }
    if (!empty($_GET['lead_source'])) {
        $sql .= " AND cl.lead_source = ?";
        $params[] = $_GET['lead_source'];
    }
    if (!empty($_GET['assigned_to'])) {
        $sql .= " AND cl.assigned_to = ?";
        $params[] = intval($_GET['assigned_to']);
    }
    if (!empty($_GET['date_from'])) {
        $sql .= " AND DATE(cl.created_at) >= ?";
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $sql .= " AND DATE(cl.created_at) <= ?";
        $params[] = $_GET['date_to'];
    }

    $sql .= scopeFilterSqlNullable('project', 'cl');
    $sql .= " ORDER BY cl.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['lead_code'],
            $row['first_name'],
            $row['last_name'],
            $row['company_name'],
            $row['email'],
            $row['phone'],
            $row['mobile'],
            $row['city'],
            $row['country'],
            ucwords(str_replace('_', ' ', $row['lead_source'])),
            $row['stage_name'],
            $row['lead_value'],
            $row['probability'],
            $row['expected_close_date'],
            $row['assigned_name'],
            ucfirst($row['status']),
            $row['converted'] ? 'Yes' : 'No',
            $row['created_by_name'],
            $row['created_at'],
        ]);
    }

} catch (PDOException $e) {
    error_log("export_leads error: " . $e->getMessage());
    fputcsv($output, ['Error exporting data']);
}

fclose($output);
exit;
