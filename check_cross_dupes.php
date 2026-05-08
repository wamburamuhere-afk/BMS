<?php
require 'roots.php';
global $pdo;

echo "Checking for cross-duplicates...\n";

$stmt = $pdo->query("
    SELECT e1.employee_id as id1, e1.first_name as name1, e1.employee_code as code1, e1.employee_number as num1,
           e2.employee_id as id2, e2.first_name as name2, e2.employee_code as code2, e2.employee_number as num2
    FROM employees e1
    JOIN employees e2 ON e1.employee_id != e2.employee_id
    WHERE e1.employee_code = e2.employee_number
       OR e1.employee_number = e2.employee_code
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($results) {
    print_r($results);
} else {
    echo "No cross-duplicates found.\n";
}
