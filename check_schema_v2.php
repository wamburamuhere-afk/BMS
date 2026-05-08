<?php
require_once 'roots.php';
global $pdo;

function describe($table) {
    global $pdo;
    echo "--- $table ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        foreach($stmt as $row) {
            echo $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } catch(Exception $e) { echo "Table $table not found\n"; }
    echo "\n";
}

describe('purchase_order_items');
describe('sales_order_items');
describe('invoice_items');
describe('grn');
describe('grn_items');
describe('stock_movements');
describe('stock_adjustments');
describe('stock_transfers');
