<?php
/**
 * BMS Migration Status Page
 * URL: https://bms.bjptechnologies.co.tz/migrations/status.php
 *
 * Shows all ran migrations, pending migrations, and deploy log history.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Auth guard ────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    die('<h3 style="font-family:sans-serif;text-align:center;margin-top:80px;color:#64748b">
         403 — Access denied. Please log in to BMS first.</h3>');
}

// ── Data ──────────────────────────────────────────────────────────────

// Ensure table exists (safe on first visit)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`       INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) UNIQUE NOT NULL,
        `ran_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Fetch ran migrations
$ran = $pdo->query("SELECT filename, ran_at FROM migrations ORDER BY ran_at ASC")
           ->fetchAll(PDO::FETCH_ASSOC);
$ranNames = array_column($ran, 'filename');

// Scan folder for all dated files
$files   = glob(__DIR__ . '/[0-9]*.php');
sort($files);
$allFiles = array_map('basename', $files);

// Build combined list
$rows    = [];
foreach ($allFiles as $name) {
    $ranRow = array_filter($ran, fn($r) => $r['filename'] === $name);
    $ranRow = array_values($ranRow);
    $rows[] = [
        'filename' => $name,
        'status'   => in_array($name, $ranNames) ? 'ran' : 'pending',
        'ran_at'   => $ranRow[0]['ran_at'] ?? null,
    ];
}

$totalCount   = count($rows);
$ranCount     = count(array_filter($rows, fn($r) => $r['status'] === 'ran'));
$pendingCount = $totalCount - $ranCount;
$lastDeploy   = $ran ? end($ran)['ran_at'] : null;

// Deploy log — last 100 lines
$logFile  = __DIR__ . '/deploy.log';
$logLines = [];
if (file_exists($logFile)) {
    $all      = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logLines = array_slice($all, -100);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BMS — Migration Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --navy:  #0f172a;
            --teal:  #14b8a6;
            --blue:  #1e40af;
            --slate: #64748b;
        }
        body        { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; }
        .top-bar    { background: var(--navy); color: #fff; padding: 14px 28px; display:flex; align-items:center; justify-content:space-between; }
        .top-bar h1 { font-size: 1rem; font-weight: 700; margin: 0; letter-spacing:.5px; }
        .top-bar small { color: #94a3b8; font-size:.78rem; }
        .card-stat  { border-radius: 10px; border: none; box-shadow: 0 1px 6px rgba(0,0,0,.08); }
        .accent     { height: 4px; border-radius: 10px 10px 0 0; }
        .stat-num   { font-size: 2rem; font-weight: 800; }
        .stat-lbl   { font-size: .7rem; color: var(--slate); text-transform: uppercase; letter-spacing:.6px; }
        .section-hd { background: var(--navy); color: #fff; font-size: .82rem; font-weight: 700;
                      padding: 8px 16px; border-radius: 6px 6px 0 0; letter-spacing:.4px; }
        .badge-ran     { background:#dcfce7; color:#15803d; font-weight:600; font-size:.75rem; padding:3px 10px; border-radius:20px; }
        .badge-pending { background:#fef9c3; color:#a16207; font-weight:600; font-size:.75rem; padding:3px 10px; border-radius:20px; }
        .log-box    { background: var(--navy); color: #86efac; font-family: 'Courier New', monospace;
                      font-size: .76rem; padding: 16px; border-radius: 0 0 8px 8px;
                      max-height: 360px; overflow-y: auto; white-space: pre-wrap; word-break:break-all; }
        .log-box .log-err  { color: #fca5a5; }
        .log-box .log-ok   { color: #86efac; }
        .log-box .log-sep  { color: #475569; }
        .log-box .log-info { color: #94a3b8; }
        table th   { background: #1e293b; color: #fff; font-size:.78rem; font-weight:600; }
        table td   { font-size: .82rem; vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        .refresh    { font-size: .75rem; color: var(--slate); }
    </style>
</head>
<body>

<!-- Top bar -->
<div class="top-bar">
    <h1>BJP TECHNOLOGIES &nbsp;|&nbsp; BMS Migration Status</h1>
    <small>Checked at: <?= date('d M Y  H:i:s') ?></small>
</div>

<div class="container-fluid px-4 py-4">

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['Total Migrations', $totalCount,   '#3b82f6'],
            ['Ran',              $ranCount,      '#22c55e'],
            ['Pending',          $pendingCount,  $pendingCount > 0 ? '#f59e0b' : '#22c55e'],
            ['Last Deploy',      $lastDeploy ? date('d M Y H:i', strtotime($lastDeploy)) : '—', '#14b8a6'],
        ];
        foreach ($cards as [$label, $value, $color]): ?>
        <div class="col-6 col-md-3">
            <div class="card card-stat">
                <div class="accent" style="background:<?= $color ?>"></div>
                <div class="card-body py-3 text-center">
                    <div class="stat-num" style="color:<?= $color ?>"><?= $value ?></div>
                    <div class="stat-lbl"><?= $label ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Migration table -->
    <div class="mb-4">
        <div class="section-hd">MIGRATION FILES</div>
        <div class="card border-0 shadow-sm" style="border-radius:0 0 8px 8px">
            <?php if (empty($rows)): ?>
                <div class="p-4 text-center text-muted">No migration files found in migrations/ folder.</div>
            <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Filename</th>
                        <th style="width:100px">Status</th>
                        <th style="width:180px">Ran At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><code style="font-size:.8rem"><?= htmlspecialchars($row['filename']) ?></code></td>
                        <td>
                            <?php if ($row['status'] === 'ran'): ?>
                                <span class="badge-ran">✓ RAN</span>
                            <?php else: ?>
                                <span class="badge-pending">⏳ PENDING</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:.78rem">
                            <?= $row['ran_at'] ? date('d M Y  H:i:s', strtotime($row['ran_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Deploy log -->
    <div>
        <div class="section-hd d-flex justify-content-between align-items-center">
            <span>DEPLOY LOG <span style="color:#94a3b8;font-weight:400">(last 100 lines)</span></span>
            <span class="refresh">Auto-refreshes on page reload &nbsp;·&nbsp; <?= file_exists($logFile) ? round(filesize($logFile)/1024, 1) . ' KB' : 'no log yet' ?></span>
        </div>
        <div class="log-box" id="logBox">
<?php if (empty($logLines)): ?>
<span class="log-info">No deploy log found yet. Log is written on first automated deploy.</span>
<?php else:
    foreach ($logLines as $line):
        $cls = 'log-info';
        if (str_contains($line, '✗') || str_contains($line, 'FAILED') || str_contains($line, 'failed')) $cls = 'log-err';
        elseif (str_contains($line, '✓') || str_contains($line, 'SUCCESS') || str_contains($line, 'Done')) $cls = 'log-ok';
        elseif (str_contains($line, '===')) $cls = 'log-sep';
        echo '<span class="' . $cls . '">' . htmlspecialchars($line) . '</span>' . "\n";
    endforeach;
endif; ?>
        </div>
    </div>

</div>

<script>
    // Auto-scroll log to bottom
    const lb = document.getElementById('logBox');
    if (lb) lb.scrollTop = lb.scrollHeight;
</script>
</body>
</html>
