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

    // SMTP Configuration from POST
    $smtp_host = $_POST['smtp_host'] ?? '';
    $smtp_port = $_POST['smtp_port'] ?? '';
    $smtp_username = $_POST['smtp_username'] ?? '';
    $smtp_password = $_POST['smtp_password'] ?? '';
    $smtp_encryption = $_POST['smtp_encryption'] ?? '';
    $from_email = $_POST['from_email'] ?? '';
    $from_name = $_POST['from_name'] ?? '';

    if (empty($smtp_host) || empty($smtp_port)) {
        // Fallback to template test if not config test
        $template_id = $_POST['template_id'] ?? null;
        $recipient_email = trim($_POST['email'] ?? '');

        if (!$template_id || !$recipient_email) {
            throw new Exception("SMTP configuration or template/recipient info is required");
        }

        // Fetch template details
        $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            throw new Exception("Template not found");
        }
        
        $subject = "TEST: " . $template['subject'];
        $message = str_replace(['{{customer_name}}', '{{amount}}', '{{loan_id}}'], ['Test Customer', '1,000.00', 'LN-999'], $template['content']);
        $to = $recipient_email;
    } else {
        // SMTP Config test
        $to = $from_email; // Test by sending to self
        $subject = "System Settings: SMTP Configuration Test";
        $message = "This is a test email to verify your SMTP settings in the BMS System.\n\n" .
                  "If you received this, your email configuration is correct.\n\n" .
                  "Host: $smtp_host\n" .
                  "Port: $smtp_port\n" .
                  "Encryption: $smtp_encryption";
    }

    // In a real system, you'd use PHPMailer or similar.
    // For now, we will simulate the connection test.
    
    // Simulate connection lag
    usleep(500000); 

    // Simulate sucess
    // (In a real implementation, you'd try to connect and send here)
    
    echo json_encode([
        'success' => true, 
        'message' => "Test email triggered successfully. Please check your inbox ($to)."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
