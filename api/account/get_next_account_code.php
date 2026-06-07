<?php
/**
 * api/account/get_next_account_code.php
 * -------------------------------------
 * Suggests the next Account Code following the MYOB-style hierarchical scheme:
 *
 *   D-WXYZ   where D = class digit (Asset 1, Liability 2, Equity 3, Income 4,
 *            Cost of Sales 5, Expense 6, Finance Cost 8) and W/X/Y/Z are the
 *            group digits for levels 2..5.
 *
 *   - With a parent: take the parent code, fill the first free (zero) digit slot
 *     after its own, using max(existing sibling digit) + 1.
 *       parent 1-1000 (Current Assets) → next child 1-1100, then 1-1200 …
 *       parent 1-1100 (Cash On Hand)   → next child 1-1110, then 1-1120 …
 *   - Without a parent: the class root D-0000 if absent, else the next top-level
 *     group D-W000.
 *
 * Always returns a code that is not already taken (bumps until free), so it can
 * never collide with a manually-typed code. GET, read-only.
 */

require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated())                 throw new Exception('Unauthorized');
    if (!canView('chart_of_accounts'))      throw new Exception('Permission denied');

    $parentId    = isset($_GET['parent_account_id']) ? (int)$_GET['parent_account_id'] : 0;
    $accountType = trim($_GET['account_type'] ?? '');   // type_name

    // class category → leading digit
    $digitByCat = [
        'asset' => '1', 'liability' => '2', 'equity' => '3',
        'revenue' => '4', 'cogs' => '5', 'expense' => '6', 'finance_cost' => '8',
    ];

    // Resolve the class digit + (if any) the parent code/level.
    $parentCode = null; $parentLevel = 0; $classDigit = null;

    if ($parentId > 0) {
        $st = $pdo->prepare("SELECT a.account_code, a.level, at.category
                               FROM accounts a
                               LEFT JOIN account_types at ON a.account_type_id = at.type_id
                              WHERE a.account_id = ?");
        $st->execute([$parentId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            $parentCode  = (string)$p['account_code'];
            $parentLevel = (int)$p['level'];
            // class digit from the parent's code if it looks like D-..., else its category
            if (preg_match('/^(\d)-/', $parentCode, $m)) $classDigit = $m[1];
            elseif (!empty($p['category']))              $classDigit = $digitByCat[$p['category']] ?? null;
        }
    }

    if ($classDigit === null && $accountType !== '') {
        $st = $pdo->prepare("SELECT category FROM account_types WHERE type_name = ?");
        $st->execute([$accountType]);
        $cat = $st->fetchColumn();
        if ($cat) $classDigit = $digitByCat[$cat] ?? null;
    }

    if ($classDigit === null) {
        echo json_encode(['success' => true, 'code' => '']);   // not enough info yet
        exit;
    }

    // Build the candidate.
    $candidate = null;

    if ($parentCode !== null && preg_match('/^\d-(\d{4})$/', $parentCode, $m)) {
        // parentDigits = WXYZ; the child varies the slot at index (parentLevel-1)
        $parentDigits = $m[1];
        $pos = $parentLevel - 1;                 // 0..3
        if ($pos >= 0 && $pos <= 3) {
            $prefix = substr($parentDigits, 0, $pos);
            // collect used child digits at this slot, then take the FIRST FREE
            // 1..9 (fills gaps, and only overflows when the slot is full).
            $kids = $pdo->prepare("SELECT account_code FROM accounts WHERE parent_account_id = ?");
            $kids->execute([$parentId]);
            $used = [];
            foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $code) {
                if (preg_match('/^\d-(\d{4})$/', (string)$code, $mm)) {
                    $used[(int)substr($mm[1], $pos, 1)] = true;
                }
            }
            for ($d = 1; $d <= 9; $d++) {
                if (empty($used[$d])) {
                    $candidate = $classDigit . '-' . $prefix . $d . str_repeat('0', 3 - $pos);
                    break;
                }
            }
        }
    }

    if ($candidate === null) {
        // No parent (or parent at max depth / non-standard code): top-level group.
        $rootExists = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code = '{$classDigit}-0000'")->fetchColumn();
        if (!$rootExists) {
            $candidate = $classDigit . '-0000';
        } else {
            // first free top-level group digit W (1..9)
            $used = [];
            foreach ($pdo->query("SELECT account_code FROM accounts WHERE account_code REGEXP '^{$classDigit}-[0-9]{4}$'")->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $used[(int)substr($code, 2, 1)] = true;
            }
            $w = 1; while ($w < 9 && !empty($used[$w])) $w++;
            $candidate = $classDigit . '-' . $w . '000';
        }
    }

    // Guarantee uniqueness — bump the last varying digit until free (defensive).
    $chk = $pdo->prepare("SELECT 1 FROM accounts WHERE account_code = ?");
    $guard = 0;
    while ($guard++ < 100) {
        $chk->execute([$candidate]);
        if (!$chk->fetchColumn()) break;
        // increment the last non-zero-from-right digit group
        if (preg_match('/^(\d)-(\d{4})$/', $candidate, $m)) {
            $d = $m[2];
            // find right-most position that is the "active" one (last non-zero), bump it
            $pos = 3;
            for ($i = 3; $i >= 0; $i--) { if ($d[$i] !== '0') { $pos = $i; break; } }
            $n = (int)substr($d, $pos, 1) + 1;
            if ($n > 9) { $pos = max(0, $pos - 1); $n = (int)substr($d, $pos, 1) + 1; }
            $d = substr($d, 0, $pos) . min($n, 9) . substr($d, $pos + 1);
            $candidate = $m[1] . '-' . $d;
        } else break;
    }

    echo json_encode(['success' => true, 'code' => $candidate]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
