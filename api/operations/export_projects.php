<?php
// api/operations/export_projects.php
require_once __DIR__ . '/../../roots.php';

global $pdo;

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $where = ["1=1"];
    $params = [];
    if($status){ $where[] = "status = ?"; $params[] = $status; }
    if($search){ $where[] = "(project_name LIKE ? OR project_manager LIKE ?)"; $s = "%$search%"; $params[] = $s; $params[] = $s; }
    
    $where_clause = implode(" AND ", $where);
    $stmt = $pdo->prepare("SELECT project_name, project_manager, start_date, deadline, budget, progress_percent, status, priority FROM projects WHERE $where_clause ORDER BY start_date DESC");
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="projects_export_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Project Name', 'Manager', 'Start Date', 'Deadline', 'Budget', 'Progress (%)', 'Status', 'Priority']);
    foreach($projects as $p) fputcsv($output, $p);
    fclose($output);
    exit;
} catch (Exception $e) { die("Error: " . $e->getMessage()); }
