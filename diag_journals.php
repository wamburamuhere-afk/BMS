<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
echo "--- Journal Entries ---\n";
print_r($pdo->query('SELECT * FROM journal_entries')->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- Journal Entry Items ---\n";
print_r($pdo->query('SELECT * FROM journal_entry_items')->fetchAll(PDO::FETCH_ASSOC));
