<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
echo 'Chart of Accounts: ' . $pdo->query('SELECT COUNT(*) FROM chart_of_accounts')->fetchColumn() . PHP_EOL;
echo 'Accounts: ' . $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() . PHP_EOL;
echo 'Journal Entries: ' . $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() . PHP_EOL;
echo 'Books Transactions: ' . $pdo->query('SELECT COUNT(*) FROM books_transactions')->fetchColumn() . PHP_EOL;
echo 'Posted Journal Entries: ' . $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE status = 'posted'")->fetchColumn() . PHP_EOL;
