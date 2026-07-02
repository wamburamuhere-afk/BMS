<?php
require 'includes/config.php';
echo "Tenders: " . $pdo->query('SELECT COUNT(*) FROM tenders')->fetchColumn() . "\n";
