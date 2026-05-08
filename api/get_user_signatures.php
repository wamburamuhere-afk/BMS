<?php
header('Content-Type: application/json');

echo json_encode([
    "draw" => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
    "recordsTotal" => 0,
    "recordsFiltered" => 0,
    "data" => []
]);
?>
