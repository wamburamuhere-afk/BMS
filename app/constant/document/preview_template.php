<?php
require_once 'header.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

// Phase 5c — enforce view permission on template preview
autoEnforcePermission('document_templates');

$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;

if ($template_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT template_name, template_content, file_path 
            FROM document_templates 
            WHERE id = ?
        ");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            if ($template['template_content']) {
                echo $template['template_content'];
            } elseif ($template['file_path'] && file_exists($template['file_path'])) {
                $file_ext = strtolower(pathinfo($template['file_path'], PATHINFO_EXTENSION));
                
                if ($file_ext === 'pdf') {
                    header('Content-Type: application/pdf');
                    readfile($template['file_path']);
                } else {
                    readfile($template['file_path']);
                }
            } else {
                echo '<div class="alert alert-warning">Template content not available for preview.</div>';
            }
        } else {
            echo '<div class="alert alert-danger">Template not found.</div>';
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading template: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
} else {
    echo '<div class="alert alert-danger">Template ID required.</div>';
}
?>