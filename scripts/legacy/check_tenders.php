<?php
require_once 'includes/config.php';
global $pdo;
$stmt = $pdo->prepare("DESCRIBE tenders");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
