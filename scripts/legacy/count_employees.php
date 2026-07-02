<?php
require 'roots.php';
global $pdo;

echo "Total employees: " . $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() . "\n";
