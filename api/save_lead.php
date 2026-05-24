<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['lead_id'] ?? null;
    $firstName = $_POST['first_name'] ?? '';
    $lastName = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $source = $_POST['source'] ?? '';
    $score = intval($_POST['score'] ?? 50);
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'New';

    if (empty($firstName) || empty($lastName)) {
        throw new Exception("First and last names are required");
    }

    if (!empty($id)) {
        // Update
        $stmt = $pdo->prepare("UPDATE leads SET first_name = ?, last_name = ?, email = ?, phone = ?, source = ?, score = ?, notes = ?, status = ? WHERE lead_id = ?");
        $stmt->execute([$firstName, $lastName, $email, $phone, $source, $score, $notes, $status, $id]);
        $msg = "Lead updated successfully";
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Lead", "Lead: $firstName $lastName (ID: $id)");
    } else {
        // Create
        $stmt = $pdo->prepare("INSERT INTO leads (first_name, last_name, email, phone, source, score, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$firstName, $lastName, $email, $phone, $source, $score, $notes, $status]);
        $newId = $pdo->lastInsertId();
        $msg = "Lead captured successfully";
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Created Lead", "Lead: $firstName $lastName (ID: $newId)");
    }

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
