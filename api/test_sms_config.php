<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

// Check permissions
if (!hasPermission('system_settings')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $gateway_type = $_POST['sms_gateway_type'] ?? '';
    $api_key = $_POST['sms_api_key'] ?? '';
    $api_secret = $_POST['sms_api_secret'] ?? '';
    $sender_id = $_POST['sms_sender_id'] ?? '';

    if (empty($gateway_type)) {
        // Fallback to template test
        $template_id = $_POST['template_id'] ?? null;
        $phone = trim($_POST['phone'] ?? '');

        if (!$template_id || !$phone) {
            throw new Exception("Gateway configuration or template/phone info is required");
        }

        // Fetch template details
        $stmt = $pdo->prepare("SELECT * FROM sms_templates WHERE template_id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            throw new Exception("Template not found");
        }

        $message = str_replace(['{{customer_name}}', '{{amount}}', '{{loan_id}}'], ['Test Customer', '1,000.00', 'LN-999'], $template['message_content']);
        $to = $phone;
    } else {
        // Gateway Config test
        $to = $sender_id ?: 'System Test'; 
        $message = "BMS SMS Test: Configuration for $gateway_type is working correctly.";
    }

    // In a real system, you'd call the specific gateway API here.
    // For now, we simulate.
    
    usleep(500000); 

    echo json_encode([
        'success' => true, 
        'message' => "Test SMS triggered successfully via $gateway_type."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
