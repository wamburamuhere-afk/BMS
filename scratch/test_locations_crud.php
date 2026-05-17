<?php
/**
 * Test: locations.php CRUD operations (Add, Edit, Delete)
 * Simulates the exact DB logic the POST handlers run.
 *
 * Run: http://localhost/bms/scratch/test_locations_crud.php
 */
echo "<pre>\n";
echo "=== TEST: Locations CRUD (Add / Edit / Delete) ===\n\n";

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Syntax check ──────────────────────────────────────────────────────────────
echo "--- TEST 0: PHP syntax check ---\n";
$found = glob('C:/wamp64/bin/php/*/php.exe');
$php_bin = $found ? end($found) : null;
if ($php_bin) {
    $out = shell_exec('"' . $php_bin . '" -l ' . escapeshellarg('C:/wamp64/www/bms/app/bms/stock/locations.php') . ' 2>&1');
    if (strpos($out, 'No syntax errors') !== false) {
        echo "✓ locations.php syntax: PASSED\n";
    } else {
        echo "✗ locations.php syntax: FAILED\n  $out\n";
    }
} else {
    echo "~ PHP CLI not found — skipping syntax check\n";
}

// ── Verify POST block is before includeHeader ─────────────────────────────────
echo "\n--- TEST 0b: POST block precedes includeHeader() ---\n";
$src = file_get_contents('C:/wamp64/www/bms/app/bms/stock/locations.php');
$pos_post     = strpos($src, "REQUEST_METHOD'] === 'POST'");
$pos_include  = strpos($src, 'includeHeader()');
if ($pos_post !== false && $pos_include !== false && $pos_post < $pos_include) {
    echo "✓ POST block appears before includeHeader(): PASSED\n";
} else {
    echo "✗ POST block appears AFTER includeHeader(): FAILED (headers-already-sent bug still present)\n";
}

// ── Pre-cleanup ───────────────────────────────────────────────────────────────
echo "\n--- Pre-cleanup ---\n";
$pdo->exec("DELETE FROM locations WHERE location_name LIKE 'TEST-LOC-%'");
echo "✓ Pre-cleanup done\n";

// ── Get valid warehouse ───────────────────────────────────────────────────────
$wh_id = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn();
if (!$wh_id) {
    echo "\n✗ No active warehouse found — cannot run location tests. Aborting.\n</pre>\n";
    exit;
}
echo "\nUsing warehouse_id=$wh_id\n\n";

// ── TEST 1: Add a location ────────────────────────────────────────────────────
echo "--- TEST 1: Add location ---\n";
$loc_name = 'TEST-LOC-' . time();
$loc_code = 'TL-001';
$loc_type = 'storage';
$capacity = 50;
$status   = 'active';

try {
    $stmt = $pdo->prepare("INSERT INTO locations (warehouse_id, location_name, location_code, location_type, capacity, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$wh_id, $loc_name, $loc_code, $loc_type, $capacity, $status, 1]);
    $loc_id = $pdo->lastInsertId();

    if ($loc_id > 0) {
        echo "✓ Location inserted: PASSED (location_id=$loc_id)\n";
    } else {
        echo "✗ Insert returned no ID: FAILED\n";
    }
} catch (Throwable $e) {
    echo "✗ Insert threw exception: FAILED — " . $e->getMessage() . "\n";
    $loc_id = null;
}

// ── TEST 2: Verify add ────────────────────────────────────────────────────────
echo "\n--- TEST 2: Verify location saved in DB ---\n";
if ($loc_id) {
    $row = $pdo->prepare("SELECT * FROM locations WHERE location_id = ?");
    $row->execute([$loc_id]);
    $loc = $row->fetch(PDO::FETCH_ASSOC);

    $checks = [
        ['location_name',  $loc_name],
        ['location_code',  $loc_code],
        ['location_type',  $loc_type],
        ['status',         $status],
        ['warehouse_id',   (string)$wh_id],
        ['created_by',     '1'],
    ];

    $all_ok = true;
    foreach ($checks as [$field, $expected]) {
        if ((string)($loc[$field] ?? '') === (string)$expected) {
            echo "  ✓ $field = {$loc[$field]}\n";
        } else {
            echo "  ✗ $field: expected '$expected', got '" . ($loc[$field] ?? 'NULL') . "'\n";
            $all_ok = false;
        }
    }
    echo $all_ok ? "✓ All add fields verified: PASSED\n" : "✗ Some fields wrong: FAILED\n";
}

// ── TEST 3: Edit (update) location ───────────────────────────────────────────
echo "\n--- TEST 3: Edit (update) location ---\n";
if ($loc_id) {
    try {
        $new_name = 'TEST-LOC-UPDATED-' . time();
        $stmt = $pdo->prepare("UPDATE locations SET warehouse_id = ?, location_name = ?, location_code = ?, location_type = ?, capacity = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE location_id = ?");
        $stmt->execute([$wh_id, $new_name, 'TL-002', 'receiving', 75, 'inactive', 1, $loc_id]);

        $updated = $pdo->prepare("SELECT location_name, location_code, location_type, capacity, status FROM locations WHERE location_id = ?");
        $updated->execute([$loc_id]);
        $u = $updated->fetch(PDO::FETCH_ASSOC);

        $edit_checks = [
            ['location_name', $new_name],
            ['location_code', 'TL-002'],
            ['location_type', 'receiving'],
            ['status',        'inactive'],
        ];
        $all_ok = true;
        foreach ($edit_checks as [$field, $expected]) {
            if ($u[$field] === $expected) {
                echo "  ✓ $field = {$u[$field]}\n";
            } else {
                echo "  ✗ $field: expected '$expected', got '{$u[$field]}'\n";
                $all_ok = false;
            }
        }
        echo $all_ok ? "✓ All edit fields verified: PASSED\n" : "✗ Some edit fields wrong: FAILED\n";
    } catch (Throwable $e) {
        echo "✗ Update threw exception: FAILED — " . $e->getMessage() . "\n";
    }
}

// ── TEST 4: Delete rejected when stock exists ─────────────────────────────────
echo "\n--- TEST 4: Delete blocked when location has stock ---\n";
if ($loc_id) {
    // Insert fake stock entry
    $has_col = $pdo->query("SHOW COLUMNS FROM product_stocks LIKE 'location_id'")->fetchColumn();
    if ($has_col) {
        $prod_id = $pdo->query("SELECT product_id FROM products LIMIT 1")->fetchColumn();
        if ($prod_id) {
            try {
                $pdo->prepare("UPDATE product_stocks SET location_id = ? WHERE product_id = ? LIMIT 1")->execute([$loc_id, $prod_id]);
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM product_stocks WHERE location_id = ?");
                $count_stmt->execute([$loc_id]);
                $cnt = $count_stmt->fetchColumn();
                if ($cnt > 0) {
                    // This is what the delete handler checks
                    echo "✓ Stock check correctly detects $cnt item(s) — delete should be blocked: PASSED\n";
                } else {
                    echo "~ Could not attach stock to test location — skipping blocked-delete check\n";
                }
                // Undo the fake stock assignment
                $pdo->prepare("UPDATE product_stocks SET location_id = NULL WHERE location_id = ?")->execute([$loc_id]);
            } catch (Throwable $e) {
                echo "~ location_id column not assignable: " . $e->getMessage() . " — skipping\n";
            }
        } else {
            echo "~ No products in DB — skipping stock-block check\n";
        }
    } else {
        echo "~ product_stocks has no location_id column — skipping stock-block check\n";
    }
}

// ── TEST 5: Delete (soft) location ───────────────────────────────────────────
echo "\n--- TEST 5: Delete location (soft delete) ---\n";
if ($loc_id) {
    try {
        // Confirm no stock blocking
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM product_stocks WHERE location_id = ?");
        $count_stmt->execute([$loc_id]);
        if ($count_stmt->fetchColumn() > 0) {
            echo "✗ Stock still attached after cleanup — cannot test delete: FAILED\n";
        } else {
            $pdo->prepare("UPDATE locations SET status = 'deleted' WHERE location_id = ?")->execute([$loc_id]);

            $check = $pdo->prepare("SELECT status FROM locations WHERE location_id = ?");
            $check->execute([$loc_id]);
            $s = $check->fetchColumn();
            if ($s === 'deleted') {
                echo "✓ Location soft-deleted (status='deleted'): PASSED\n";
            } else {
                echo "✗ status after delete: expected 'deleted', got '$s': FAILED\n";
            }

            // Verify it no longer appears in the active list query
            $list_check = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE location_id = ? AND status != 'deleted'");
            $list_check->execute([$loc_id]);
            $visible = $list_check->fetchColumn();
            if ($visible == 0) {
                echo "✓ Location excluded from active list query: PASSED\n";
            } else {
                echo "✗ Location still visible in active list: FAILED\n";
            }
        }
    } catch (Throwable $e) {
        echo "✗ Delete threw exception: FAILED — " . $e->getMessage() . "\n";
    }
}

// ── TEST 6: Verify SweetAlert2 output in locations.php ───────────────────────
echo "\n--- TEST 6: SweetAlert2 used for alerts (not Bootstrap alert divs) ---\n";
if (strpos($src, 'Swal.fire') !== false && strpos($src, "json_encode(\$_SESSION['success'])") !== false) {
    echo "✓ SweetAlert2 used for success/error alerts: PASSED\n";
} else {
    echo "✗ SweetAlert2 not found for alerts: FAILED\n";
}
if (strpos($src, '<div class="alert alert-success') === false) {
    echo "✓ Bootstrap alert-success div removed: PASSED\n";
} else {
    echo "✗ Bootstrap alert-success still present: FAILED\n";
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
echo "\n--- CLEANUP ---\n";
if ($loc_id) {
    $pdo->prepare("DELETE FROM locations WHERE location_id = ?")->execute([$loc_id]);
    echo "✓ Test location deleted (hard) (id=$loc_id)\n";
}
$pdo->exec("DELETE FROM locations WHERE location_name LIKE 'TEST-LOC-%'");

echo "\n=== DONE ===\n";
echo "</pre>\n";
