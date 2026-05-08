<?php
require 'includes/config.php';
echo 'REGIONS COUNT: ' . $pdo->query('SELECT COUNT(*) FROM regions')->fetchColumn() . PHP_EOL;
echo 'DISTRICTS COUNT: ' . $pdo->query('SELECT COUNT(*) FROM districts')->fetchColumn() . PHP_EOL;
echo "COUNCILS TABLE: ";
try {
    $pdo->query("DESCRIBE councils");
    echo "EXISTS";
} catch(Exception $e) {
    echo "MISSING";
}
echo PHP_EOL . "WARDS TABLE: ";
try {
    $pdo->query("DESCRIBE wards");
    echo "EXISTS";
} catch(Exception $e) {
    echo "MISSING";
}
echo PHP_EOL;
