<?php
$c = file_get_contents('c:/wamp64/www/bms/app/bms/operations/project_view.php');
// Simple count ignoring strings/comments (very rough)
$open = substr_count($c, '{');
$close = substr_count($c, '}');
echo "Open: $open, Close: $close\n";
