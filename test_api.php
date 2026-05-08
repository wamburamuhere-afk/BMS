<?php
error_reporting(0);
require_once 'includes/config.php';
$id = $pdo->query('SELECT project_id FROM projects LIMIT 1')->fetchColumn();
echo "ID=" . $id . "\n";
$_GET['id'] = $id;
ob_start();
require 'api/operations/get_project.php';
$json = ob_get_clean();
$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON ERROR: " . json_last_error_msg() . "\n";
    echo "CONTENT: " . $json . "\n";
} else {
    echo "SUCCESS: JSON is valid\n";
}
