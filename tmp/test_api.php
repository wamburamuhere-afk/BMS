<?php
$_GET['district_id'] = 100;
require 'api/get_councils.php';
echo PHP_EOL;
$_GET['district_id'] = 5; // Dodoma Bahi? 
require 'api/get_councils.php';
