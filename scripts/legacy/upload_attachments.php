<?php
// actions/upload_attachments.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Auth check
if (!isAuthenticated()) {
    $response['message'] = 'Unauthorized';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

// Validate customer ID
$customer_id = intval($_POST['customer_id'] ?? 0);
if ($customer_id <= 0) {
    $response['message'] = 'Invalid customer ID.';
    echo json_encode($response);
    exit;
}

// Define upload directory
$uploadDir = ROOT_DIR . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Allowed file types
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
$maxSize = 5 * 1024 * 1024; // 5MB

// Process each file
$files = [
    'id_document'     => 'ID Document',
    'passport_photo'  => 'Passport Photo',
    'proof_of_address'=> 'Proof of Address',
    'income_proof'    => 'Income Proof'
];

foreach ($files as $fileInput => $fileType) {
    if (isset($_FILES[$fileInput]) && $_FILES[$fileInput]['error'] === UPLOAD_ERR_OK) {
        // Validate size
        if ($_FILES[$fileInput]['size'] > $maxSize) {
            $response['message'] = "$fileType exceeds maximum file size of 5MB.";
            echo json_encode($response);
            exit;
        }
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES[$fileInput]['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mimeType, $allowedTypes)) {
            $response['message'] = "$fileType has an invalid file type.";
            echo json_encode($response);
            exit;
        }

        $ext = pathinfo($_FILES[$fileInput]['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES[$fileInput]['tmp_name'], $filePath)) {
            $relativePath = 'uploads/' . $fileName;
            $stmt = $pdo->prepare("INSERT INTO customer_attachments (customer_id, file_type, file_path) VALUES (?, ?, ?)");
            if (!$stmt->execute([$customer_id, $fileType, $relativePath])) {
                $response['message'] = 'Failed to save file information in the database.';
                echo json_encode($response);
                exit;
            }
        } else {
            $response['message'] = "Failed to upload $fileType.";
            echo json_encode($response);
            exit;
        }
    }
}

$response['success'] = true;
$response['message'] = 'Files uploaded and saved successfully!';
echo json_encode($response);
?>