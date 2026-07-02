<?php
require 'roots.php';
global $pdo;

echo "Checking for duplicates in employees table...\n\n";

// Check email duplicates
$stmt = $pdo->query("SELECT email, COUNT(*) as count FROM employees GROUP BY email HAVING count > 1");
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($emails) {
    echo "Duplicate Emails found:\n";
    print_r($emails);
} else {
    echo "No duplicate emails.\n";
}

// Check employee_number duplicates
$stmt = $pdo->query("SELECT employee_number, COUNT(*) as count FROM employees GROUP BY employee_number HAVING count > 1");
$numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($numbers) {
    echo "Duplicate Employee Numbers found:\n";
    print_r($numbers);
} else {
    echo "No duplicate employee numbers.\n";
}

// Check employee_code duplicates
$stmt = $pdo->query("SELECT employee_code, COUNT(*) as count FROM employees GROUP BY employee_code HAVING count > 1");
$codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($codes) {
    echo "Duplicate Employee Codes found:\n";
    print_r($codes);
} else {
    echo "No duplicate employee codes.\n";
}
