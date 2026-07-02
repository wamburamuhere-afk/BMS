<?php
require_once 'roots.php';
$categories = [
    ['Legal & Contracts', 'All legal agreements and contracts', '#dc3545'],
    ['Financial Reports', 'Financial statements and reports', '#198754'],
    ['HR & Employment', 'Employee documents and records', '#0d6efd'],
    ['Compliance & KYC', 'Regulatory compliance and identification', '#ffc107'],
    ['General Documents', 'Miscellaneous documents', '#6c757d'],
    ['Identification Docs', 'IDs, Passports, and Licenses', '#6610f2']
];

$stmt = $pdo->prepare("INSERT INTO document_categories (category_name, description, color) VALUES (?, ?, ?)");
foreach ($categories as $cat) {
    $stmt->execute($cat);
}
echo "Default categories inserted successfully.\n";
?>
