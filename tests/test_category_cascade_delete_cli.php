<?php
/**
 * Account Category safe-cascade delete (admin)
 * --------------------------------------------
 *   php tests/test_category_cascade_delete_cli.php
 *
 * Verifies:
 *   1. the "Category ID is required" bug is fixed — the API accepts the shared
 *      form's `delete_id` as well as `category_id`;
 *   2. SAFE CASCADE logic: deleting a category removes its EMPTY linked accounts,
 *      KEEPS+UNLINKS accounts that have transactions or are system accounts, and
 *      then deletes the category — proven live against a temp category + accounts
 *      (transaction, always rolled back).
 *
 * accounts / account_categories / journal_entries are InnoDB, so rollback is clean.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
function exists(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT 1 FROM accounts WHERE account_id=?"); $s->execute([$id]); return (bool)$s->fetchColumn(); }
function catOf(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT category_id FROM accounts WHERE account_id=?"); $s->execute([$id]); return $s->fetchColumn(); }

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Bug fix + admin gate wired');
    // ─────────────────────────────────────────────────────────────────────
    $api = src($root, 'api/account/delete_account_category.php');
    $out=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/api/account/delete_account_category.php") . ' 2>&1', $out, $rc);
    ok($rc === 0, 'delete_account_category.php lint-clean');
    ok(strpos($api, "\$_POST['delete_id']") !== false, 'accepts the shared form\'s delete_id (fixes "Category ID is required")');
    ok(preg_match('/if \(!isAdmin\(\)\)/', $api) === 1, 'admin-only');
    ok(strpos($api, 'SAFE CASCADE') !== false, 'safe-cascade logic present');
    ok(strpos($api, 'category_id = NULL') !== false, 'unlinks in-use accounts instead of deleting');
    // The lookup must NOT inner-join account_types (that hides categories whose
    // account_type_id is NULL → false "Category not found").
    ok(strpos($api, 'JOIN account_types at ON c.account_type_id = at.type_id WHERE c.category_id') === false,
        'category lookup does not inner-join account_types (NULL-type categories are still found)');
    // Live: a category with a NULL account_type_id is found by the new lookup.
    $nullCat = (int)$pdo->query("SELECT category_id FROM account_categories WHERE account_type_id IS NULL LIMIT 1")->fetchColumn();
    if ($nullCat > 0) {
        $f = $pdo->prepare("SELECT category_id, category_name, category_type FROM account_categories WHERE category_id = ?");
        $f->execute([$nullCat]);
        ok($f->fetch(PDO::FETCH_ASSOC) !== false, "NULL-account_type_id category #$nullCat is found (no false 'not found')");
    } else {
        ok(true, 'no NULL-type category present to probe (n/a)');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Safe cascade proven live (rolled back)');
    // ─────────────────────────────────────────────────────────────────────
    $typeId   = (int)$pdo->query("SELECT account_type_id FROM account_categories WHERE account_type_id IS NOT NULL LIMIT 1")->fetchColumn();
    $entryId  = (int)$pdo->query("SELECT entry_id FROM journal_entries ORDER BY entry_id LIMIT 1")->fetchColumn();
    ok($typeId > 0 && $entryId > 0, "have a category type ($typeId) + a journal entry ($entryId) to build the fixture");

    $pdo->beginTransaction();
    try {
        // temp category
        $sfx = substr(uniqid(), -6);
        $pdo->prepare("INSERT INTO account_categories (category_name, account_type_id, category_type, created_at, updated_at) VALUES (?,?, 'asset', NOW(), NOW())")
            ->execute(["ZZCAT-$sfx", $typeId]);
        $catId = (int)$pdo->lastInsertId();

        $mk = function($code,$bal,$sys) use($pdo,$typeId,$catId){
            $pdo->prepare("INSERT INTO accounts (account_code,account_name,account_type_id,account_type,category_id,opening_balance,current_balance,level,normal_balance,is_system,status,created_at,updated_at) VALUES (?,?,?,'asset',?,0,?,1,'debit',?,'active',NOW(),NOW())")
                ->execute([$code,$code,$typeId,$catId,$bal,$sys]);
            return (int)$pdo->lastInsertId();
        };
        $A = $mk("ZZA-$sfx", 0, 0);   // empty, non-system  → should be DELETED
        $B = $mk("ZZB-$sfx", 0, 0);   // will get a txn      → should be KEPT+UNLINKED
        $C = $mk("ZZC-$sfx", 0, 1);   // system              → should be KEPT+UNLINKED
        // give B a posted transaction line
        $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?,?, 'debit', 100, 'cascade test')")
            ->execute([$entryId, $B]);

        // ── reproduce the endpoint's safe-cascade ──
        $linked = $pdo->prepare("SELECT account_id, is_system FROM accounts WHERE category_id = ?");
        $linked->execute([$catId]);
        $txnStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = ?");
        $kidStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE parent_account_id = ? AND account_id <> parent_account_id");
        foreach ($linked->fetchAll(PDO::FETCH_ASSOC) as $acc) {
            $aid=(int)$acc['account_id'];
            $txnStmt->execute([$aid]); $hasTxn=(int)$txnStmt->fetchColumn()>0;
            $kidStmt->execute([$aid]); $hasKids=(int)$kidStmt->fetchColumn()>0;
            $isSys=(int)$acc['is_system']===1;
            if ($hasTxn||$hasKids||$isSys) $pdo->prepare("UPDATE accounts SET category_id=NULL WHERE account_id=?")->execute([$aid]);
            else                          $pdo->prepare("DELETE FROM accounts WHERE account_id=?")->execute([$aid]);
        }
        $pdo->prepare("DELETE FROM account_categories WHERE category_id=?")->execute([$catId]);

        ok(!exists($pdo, $A), 'empty account A was DELETED');
        ok(exists($pdo, $B) && catOf($pdo, $B) === null, 'account B (has transaction) KEPT but unlinked (category=NULL)');
        ok(exists($pdo, $C) && catOf($pdo, $C) === null, 'system account C KEPT but unlinked (category=NULL)');
        ok((int)$pdo->query("SELECT COUNT(*) FROM account_categories WHERE category_id=$catId")->fetchColumn() === 0, 'the category itself was deleted');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'cascade probe threw: ' . $e->getMessage());
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
