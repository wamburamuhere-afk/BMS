<?php
// API: Get performance indicators + categories, and (optionally) a designation's
// current targets (Tier 3, Phase 3.2). Read endpoint for the Indicators tab.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    $categories = $pdo->query("
        SELECT category_id, category_name, sort_order
        FROM performance_indicator_categories
        WHERE status = 'active'
        ORDER BY sort_order, category_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $indicators = $pdo->query("
        SELECT indicator_id, category_id, indicator_name, description
        FROM performance_indicators
        WHERE status = 'active'
        ORDER BY indicator_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Optional: current target ratings for a designation
    $targets = [];
    $designation_id = intval($_GET['designation_id'] ?? 0);
    if ($designation_id) {
        $stmt = $pdo->prepare("SELECT indicator_id, expected_rating FROM designation_indicator_targets WHERE designation_id = ?");
        $stmt->execute([$designation_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $targets[(int)$t['indicator_id']] = (int)$t['expected_rating'];
        }
    }

    echo json_encode([
        'success'    => true,
        'categories' => $categories,
        'indicators' => $indicators,
        'targets'    => $targets,
    ]);

} catch (Exception $e) {
    error_log("get_indicators error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
