<?php
// File: api/search_projects.php
// This endpoint used to claim "returns only user-accessible projects via
// scopeFilterSql already in get_projects.php" via a scope-audit skip marker,
// but it is its own independent query — a different file's filter never
// applied here. A non-admin using this picker would see and could select
// every project in the company, not just their assigned ones. Currently
// unused by any page/JS in this codebase (checked), but fixed now before
// anything wires into it. Uses the strict variant (matches security.md
// §23's dropdown rule): a project picker shows only in-scope projects,
// with no "untagged" leniency (a project row's own id is never NULL).
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');
$scope = scopeFilterSql('project', 'projects');

try {
    if ($q === '') {
        $stmt = $pdo->prepare("SELECT project_id AS id, project_name AS text FROM projects WHERE status = 'active' $scope ORDER BY project_name ASC LIMIT 30");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT project_id AS id, project_name AS text FROM projects WHERE status = 'active' AND project_name LIKE ? $scope ORDER BY project_name ASC LIMIT 30");
        $stmt->execute(['%' . $q . '%']);
    }
    echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    echo json_encode(['results' => []]);
}
