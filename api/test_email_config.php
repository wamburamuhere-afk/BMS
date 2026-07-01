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
        $bodyHtml = $message;   // template content is already HTML
        $override = [];          // use saved SMTP settings
    } else {
        // SMTP Config test
        // If the password field was left blank, fall back to the saved one.
        if ($smtp_password === '') {
            $smtp_password = (string) get_setting('smtp_password');
        }
        $to = $from_email !== '' ? $from_email : $smtp_username; // Test by sending to self
        $subject = "System Settings: SMTP Configuration Test";
        $message = "This is a test email to verify your SMTP settings in the BMS System.\n\n" .
                  "If you received this, your email configuration is correct.\n\n" .
                  "Host: $smtp_host\n" .
                  "Port: $smtp_port\n" .
                  "Encryption: $smtp_encryption";
        $bodyHtml = nl2br(htmlspecialchars($message));
        $override = [
            'host'       => $smtp_host,
            'port'       => $smtp_port,
            'username'   => $smtp_username,
            'password'   => $smtp_password,
            'encryption' => $smtp_encryption,
            'from_email' => $from_email,
            'from_name'  => $from_name,
        ];
    }

    if (empty($to)) {
        throw new Exception("No recipient address. Provide a From Email (config test) or a recipient (template test).");
    }

    // Send for real via the central mailer (core/mailer.php -> PHPMailer/SMTP).
    require_once __DIR__ . '/../core/mailer.php';
    $opts = ['wrap' => true];
    if (!empty($override)) {
        $opts['smtp'] = $override;
    }
    $ok = sendEmail($to, $subject, $bodyHtml, $opts);

    if ($ok) {
        echo json_encode([
            'success' => true,
            'message' => "Test email sent successfully to $to. Please check the inbox (and spam folder)."
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Send failed: ' . mailer_last_error()
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
