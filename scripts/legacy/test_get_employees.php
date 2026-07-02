<?php
require_once 'roots.php';
$_SESSION['user_id'] = 1; // Simulate admin session
$_POST['draw'] = 1;
$_POST['start'] = 0;
$_POST['length'] = 10;
// Note: api/get_employees.php uses $_GET, but let's just include it and see.
$_GET = $_POST;
include 'api/get_employees.php';
