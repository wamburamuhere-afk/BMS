<?php
$content = file_get_contents('C:\wamp64\www\bms\app\bms\pos\attendance.php');

// Simple regex to find the table block and count cells
if (preg_match('/<table id="attendanceTable".*?<\/table>/s', $content, $matches)) {
    $table = $matches[0];
    
    echo "Analysing attendanceTable...\n";
    
    // Count headers
    preg_match_all('/<th/i', $table, $th);
    echo "Total TH tags: " . count($th[0]) . "\n";
    
    // Check Day View headers
    if (preg_match('/<thead>.*?<\/thead>/s', $table, $head_match)) {
        $head = $head_match[0];
        $day_count = substr_count($head, '<th'); // Total in header
        // Since SN, ID, Name, Dept are shared, and others are in PHP IF/ELSE,
        // we need to simulate the PHP logic.
        
        // This is hard with regex, so let's just count them manualy by reading.
    }
} else {
    echo "Table not found!\n";
}
?>
