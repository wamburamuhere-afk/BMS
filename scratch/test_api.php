<?php
require_once __DIR__ . '/../roots.php';
$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM leaves WHERE leave_id = ?");
$stmt->execute([$id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($leave);
