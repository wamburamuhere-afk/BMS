<?php
require_once 'roots.php';
global $pdo;
echo "Total accounts: " . $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn() . PHP_EOL;
echo "Total categories: " . $pdo->query("SELECT COUNT(*) FROM account_categories")->fetchColumn() . PHP_EOL;
echo "Total types: " . $pdo->query("SELECT COUNT(*) FROM account_types")->fetchColumn() . PHP_EOL;
