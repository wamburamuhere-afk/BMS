<?php
/**
 * API: Dashboard Updates
 * Provides real-time statistics for the dashboard
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

try {
    $response = [
        'success' => true,
        'data' => [
            'last_sync' => date('Y-m-d H:i:s'),
            'notifications' => 0,
            'recent_activities' => []
        ]
    ];
    
    echo "data: " . json_encode($response) . "\n\n";
    flush();
} catch (Exception $e) {
    echo "data: " . json_encode(['success' => false, 'message' => $e->getMessage()]) . "\n\n";
    flush();
}
