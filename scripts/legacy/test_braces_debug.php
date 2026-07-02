<?php
$lines = file('c:/wamp64/www/bms/app/bms/operations/project_view.php');
$stack = [];
foreach ($lines as $i => $line) {
    // Very simple matcher
    for ($j = 0; $j < strlen($line); $j++) {
        $char = $line[$j];
        if ($char == '{') {
            $stack[] = $i + 1;
        } else if ($char == '}') {
            if (empty($stack)) {
                echo "Extra closing brace at line " . ($i + 1) . "\n";
            } else {
                array_pop($stack);
            }
        }
    }
}
if (!empty($stack)) {
    echo "Unclosed braces starting at lines: " . implode(", ", $stack) . "\n";
} else {
    echo "Braces are balanced (ignoring strings/comments)\n";
}
