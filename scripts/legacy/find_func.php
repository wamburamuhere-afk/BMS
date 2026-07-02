<?php
$content = file_get_contents('app/bms/customer/customers.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'function editCustomer') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
