<?php
// File: api/import_leaves.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';

ob_clean();
header('Content-Type: application/json');

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canCreate('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to import leaves']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Please upload a valid CSV file");
    }

    $file = $_FILES['bulk_file']['tmp_name'];
    $handle = fopen($file, 'r');
    $header = fgetcsv($handle);
    
    // Expected headers: employee_id, leave_type, start_date, end_date, reason
    $results = [
        'total_rows' => 0,
        'successful' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        $results['total_rows']++;
        
        try {
            if (count($row) < 5) {
                throw new Exception("Insufficient columns at row " . ($results['total_rows'] + 1));
            }

            $employee_id = intval($row[0]);
            $leave_type = trim($row[1]);
            $start_date = trim($row[2]);
            $end_date = trim($row[3]);
            $reason = trim($row[4]);
            
            // Calculate total days (simple)
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $total_days = $end->diff($start)->days + 1;

            $results['total_rows']++;

            $query = "INSERT INTO leaves (
                employee_id, leave_type, start_date, end_date, 
                total_days, reason, status, created_by, applied_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())";

            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $employee_id,
                $leave_type,
                $start_date,
                $end_date,
                $total_days,
                $reason,
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ]);

            $results['successful']++;
        } catch (Exception $row_e) {
            $results['failed']++;
            $results['errors'][] = "Row " . ($results['total_rows'] + 1) . ": " . $row_e->getMessage();
        }
    }

    fclose($handle);
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Imported Leaves", "Successful: " . $results['successful'] . ", Failed: " . $results['failed']);

    echo json_encode([
        'success' => true,
        'message' => 'CSV file processed',
        'results' => $results
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
