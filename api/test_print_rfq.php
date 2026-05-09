<?php
// File: api/test_print_rfq.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'bms_test_2024' && !isAuthenticated()) die("Unauthorized");

global $pdo;

try {
    echo "Testing RFQ Print Logic...\n";

    // 1. Find an RFQ
    $rfq_id = $pdo->query("SELECT rfq_id FROM rfq LIMIT 1")->fetchColumn();
    if (!$rfq_id) {
        // Create a dummy RFQ if none exists
        $pdo->exec("INSERT INTO rfq (rfq_number, rfq_date, status) VALUES ('TEST-RFQ-999', NOW(), 'draft')");
        $rfq_id = $pdo->lastInsertId();
        $created_dummy = true;
    }

    echo "Testing with RFQ ID: $rfq_id\n";

    // Mock session
    $_SESSION['user_id'] = 1;
    $_GET['id'] = $rfq_id;

    // Capture output
    ob_start();
    include __DIR__ . '/account/print_rfq.php';
    $html = ob_get_clean();

    if (strpos($html, 'REQUEST FOR QUOTATION') !== false) {
        echo "🏆 RFQ Print Test PASSED!\n";
    } else {
        echo "❌ RFQ Print Test FAILED (Content mismatch).\n";
        echo "HTML length: " . strlen($html) . "\n";
    }

    if (isset($created_dummy)) {
        $pdo->prepare("DELETE FROM rfq WHERE rfq_id = ?")->execute([$rfq_id]);
    }

} catch (Exception $e) {
    echo "❌ RFQ Print Test FAILED: " . $e->getMessage() . "\n";
}
