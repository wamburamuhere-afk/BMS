<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }
    if (!canView('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied');
    }

// Get parameters from DataTables
$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$searchValue = $_GET['search']['value'] ?? '';

// Custom filters
$category_id = $_GET['category_id'] ?? '';
$file_type = $_GET['file_type'] ?? '';
$access_level = $_GET['access_level'] ?? '';
$uploaded_by = $_GET['uploaded_by'] ?? '';
$project_id = $_GET['project_id'] ?? '';
$expiry_status = $_GET['expiry_status'] ?? '';


// Get order parameters
$orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
$orderDirection = $_GET['order'][0]['dir'] ?? 'desc';

// Define column mapping
$columns = [
    'd.document_name',
    'c.category_name',
    'd.file_size',
    'd.download_count',
    'u.username',
    'd.uploaded_at',
    'd.access_level',
    ''
];

// Base query
$query = "SELECT d.*, 
                 u.username as uploaded_by_name,
                 c.category_name,
                 c.color as category_color,
                 dt.template_name
          FROM documents d
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          LEFT JOIN document_categories c ON d.category_id = c.id
          LEFT JOIN document_templates dt ON d.template_id = dt.id
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) FROM documents d
               LEFT JOIN document_categories c ON d.category_id = c.id
               WHERE 1=1";

$params = [];

// Visibility gap-fix: a non-admin only sees public documents, their own
// uploads, and private/restricted documents they've been explicitly
// assigned. Admins are unrestricted.
if (!isAdmin()) {
    $visibilitySql = " AND (d.access_level = 'public'
                         OR d.uploaded_by = :vis_user1
                         OR d.id IN (SELECT document_id FROM document_assignees WHERE user_id = :vis_user2))";
    $query .= $visibilitySql;
    $countQuery .= $visibilitySql;
    $params[':vis_user1'] = $_SESSION['user_id'];
    $params[':vis_user2'] = $_SESSION['user_id'];
}

// Apply custom filters
if (!empty($category_id)) {
    $query .= " AND d.category_id = :category_id";
    $countQuery .= " AND d.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

if (!empty($file_type)) {
    $query .= " AND d.file_type = :file_type";
    $countQuery .= " AND d.file_type = :file_type";
    $params[':file_type'] = $file_type;
}

if (!empty($access_level)) {
    $query .= " AND d.access_level = :access_level";
    $countQuery .= " AND d.access_level = :access_level";
    $params[':access_level'] = $access_level;
}

if (!empty($uploaded_by)) {
    $query .= " AND d.uploaded_by = :uploaded_by";
    $countQuery .= " AND d.uploaded_by = :uploaded_by";
    $params[':uploaded_by'] = $uploaded_by;
}

if (!empty($project_id)) {
    $query .= " AND d.project_id = :project_id";
    $countQuery .= " AND d.project_id = :project_id";
    $params[':project_id'] = $project_id;
}

// Expiry status filter (constant SQL — values come from a fixed whitelist)
$expiry_conditions = [
    'expiring' => " AND d.expire_date IS NOT NULL AND d.expire_date >= CURDATE() AND d.expire_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
    'expired'  => " AND d.expire_date IS NOT NULL AND d.expire_date < CURDATE()",
    'active'   => " AND d.expire_date IS NOT NULL AND d.expire_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
    'none'     => " AND d.expire_date IS NULL",
];
if (isset($expiry_conditions[$expiry_status])) {
    $query .= $expiry_conditions[$expiry_status];
    $countQuery .= $expiry_conditions[$expiry_status];
}


// Add search filter if specified
if (!empty($searchValue)) {
    $searchCond = " AND (d.document_name LIKE :search1 OR 
                    d.description LIKE :search2 OR 
                    d.tags LIKE :search3 OR
                    c.category_name LIKE :search4)";
    $query .= $searchCond;
    $countQuery .= $searchCond;
    $params[':search1'] = "%$searchValue%";
    $params[':search2'] = "%$searchValue%";
    $params[':search3'] = "%$searchValue%";
    $params[':search4'] = "%$searchValue%";
}

// Get total filtered records
$countStmt = $pdo->prepare($countQuery);
foreach ($params as $key => $value) {
    if ($key !== ':start' && $key !== ':length') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalFiltered = $countStmt->fetchColumn();
$countStmt->closeCursor();

// Add sorting
if (isset($columns[$orderColumnIndex]) && !empty($columns[$orderColumnIndex])) {
    $orderBy = $columns[$orderColumnIndex];
    $query .= " ORDER BY $orderBy $orderDirection";
} else {
    $query .= " ORDER BY d.uploaded_at DESC";
}

// Add pagination
$query .= " LIMIT :start, :length";
$params[':start'] = (int)$start;
$params[':length'] = (int)$length;

// Prepare and execute main query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':start' || $key === ':length') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Get total records without filters (still respects visibility for non-admins)
if (isAdmin()) {
    $totalRecords = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
} else {
    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) FROM documents d
        WHERE d.access_level = 'public'
           OR d.uploaded_by = :vis_user1
           OR d.id IN (SELECT document_id FROM document_assignees WHERE user_id = :vis_user2)
    ");
    $totalStmt->execute([':vis_user1' => $_SESSION['user_id'], ':vis_user2' => $_SESSION['user_id']]);
    $totalRecords = $totalStmt->fetchColumn();
}

// Get Stats
$statsQuery = "SELECT 
                COUNT(*) as total_documents,
                SUM(file_size) as total_size,
                (SELECT COUNT(*) FROM document_categories) as categories_count,
                (SELECT COUNT(*) FROM documents WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_uploads,
                (SELECT COUNT(*) FROM document_downloads WHERE downloaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_downloads,
                (SELECT COUNT(*) FROM documents WHERE expire_date IS NOT NULL AND expire_date >= CURDATE() AND expire_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) as expiring_soon
               FROM documents";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Prepare response
    $response = [
        'draw' => (int)$draw,
        'recordsTotal' => (int)$totalRecords,
        'recordsFiltered' => (int)$totalFiltered,
        'data' => $documents,
        'stats' => [
            'totalDocuments' => (int)($stats['total_documents'] ?? 0),
            'totalSize' => (float)($stats['total_size'] ?? 0),
            'categoriesCount' => (int)($stats['categories_count'] ?? 0),
            'recentUploads' => (int)($stats['recent_uploads'] ?? 0),
            'recentDownloads' => (int)($stats['recent_downloads'] ?? 0),
            'expiringSoon' => (int)($stats['expiring_soon'] ?? 0)
        ]
    ];

    echo json_encode($response);
} catch (Exception $e) {
    // Log error for debugging
    error_log("get_documents.php Error: " . $e->getMessage());
    
    // Return valid JSON error response
    echo json_encode([
        'draw' => (int)($_GET['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage(),
        'stats' => [
            'totalDocuments' => 0,
            'totalSize' => 0,
            'categoriesCount' => 0,
            'recentUploads' => 0,
            'recentDownloads' => 0,
            'expiringSoon' => 0
        ]
    ]);
}
