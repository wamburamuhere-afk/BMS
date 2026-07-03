<?php
/**
 * Data-quality fix for the Tanzania location frame (mainland).
 *
 * Two defects were confirmed against the authoritative HackEAC/NBS dataset:
 *
 *  1. PHANTOM WARDS "Sokon 2".."Sokon 6" (Arusha City, code 23115).
 *     The source has ONE ward there — "Sokon 1" — whose streets are
 *     Lolovono, Longdong, Murriet, Kanisani, Sainevuno, Madukani. A bad
 *     import promoted five of those streets into fake same-code wards, each
 *     holding a single village. This re-parents those villages back under the
 *     real "Sokon 1" and deactivates the five phantom wards so they vanish
 *     from every location dropdown (is_active = 0; reversible, non-destructive).
 *
 *  2. MALFORMED CODE on "Kilosampepo" (Malinyi District, Morogoro): stored as
 *     a 6-digit 678010 while every sibling ward is 67801..67809. Corrected to
 *     the proper 5-digit 67810 (the source itself carries the same typo).
 *
 * Idempotent + criteria-based (resolves rows by region/district/ward NAME and
 * CODE, never by local ids) so it is safe to re-run and correct on every site.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: fix phantom Sokon wards + malformed Kilosampepo code...\n";

$PHANTOM_NAMES = ['Sokon 2', 'Sokon 3', 'Sokon 4', 'Sokon 5', 'Sokon 6'];

try {
    $pdo->beginTransaction(); // DML only (no DDL) — transaction is safe here.

    // ── 1. Phantom Sokon wards ──────────────────────────────────────────
    // Resolve the real "Sokon 1" ward by criteria (Arusha / code 23115).
    $q = $pdo->prepare(
        "SELECT w.ward_id
         FROM wards w
         JOIN districts d ON d.district_id = w.district_id
         JOIN regions   r ON r.region_id   = d.region_id
         WHERE r.region_name = 'Arusha' AND w.ward_code = '23115'
           AND w.ward_name = 'Sokon 1'
         LIMIT 1"
    );
    $q->execute();
    $sokon1Id = $q->fetchColumn();

    if (!$sokon1Id) {
        echo "  - 'Sokon 1' not found under code 23115; skipping phantom fix (nothing to anchor to).\n";
    } else {
        // Phantom wards Sokon 2..6 sharing that code.
        $ph = $pdo->prepare(
            "SELECT w.ward_id, w.ward_name
             FROM wards w
             JOIN districts d ON d.district_id = w.district_id
             JOIN regions   r ON r.region_id   = d.region_id
             WHERE r.region_name = 'Arusha' AND w.ward_code = '23115'
               AND w.ward_name IN ('" . implode("','", $PHANTOM_NAMES) . "')"
        );
        $ph->execute();
        $phantoms = $ph->fetchAll(PDO::FETCH_KEY_PAIR); // [ward_id => name]

        if (!$phantoms) {
            echo "  - No phantom Sokon 2..6 wards present (already cleaned). \n";
        } else {
            // Names already present under Sokon 1 (avoid duplicate villages).
            $have = [];
            $hv = $pdo->prepare("SELECT LOWER(village_name) FROM villages WHERE ward_id = ?");
            $hv->execute([$sokon1Id]);
            foreach ($hv->fetchAll(PDO::FETCH_COLUMN) as $vn) { $have[$vn] = true; }

            $moveOne = $pdo->prepare("UPDATE villages SET ward_id = ? WHERE village_id = ?");
            $moved = 0;
            $ids   = array_keys($phantoms);
            $in    = implode(',', array_map('intval', $ids));

            foreach ($pdo->query("SELECT village_id, village_name FROM villages WHERE ward_id IN ($in)") as $v) {
                $key = strtolower($v['village_name']);
                if (!isset($have[$key])) {          // move the street back under Sokon 1
                    $moveOne->execute([$sokon1Id, $v['village_id']]);
                    $have[$key] = true;
                    $moved++;
                }
                // A same-named duplicate is left on the phantom ward, which is
                // deactivated below, so it disappears from the UI either way.
            }

            // Deactivate the phantom wards (reversible; read layer filters is_active=1).
            $deact = $pdo->prepare(
                "UPDATE wards SET is_active = 0
                 WHERE ward_id IN ($in) AND is_active = 1"
            );
            $deact->execute();

            echo "  + Sokon fix: moved $moved street(s) back to 'Sokon 1', deactivated "
                . $deact->rowCount() . " phantom ward(s).\n";
        }
    }

    // ── 2. Malformed Kilosampepo code 678010 → 67810 ────────────────────
    $taken = $pdo->query("SELECT COUNT(*) FROM wards WHERE ward_code = '67810'")->fetchColumn();
    if ((int)$taken > 0) {
        echo "  - Code 67810 already in use; skipping Kilosampepo recode (already corrected or conflict).\n";
    } else {
        $fix = $pdo->prepare(
            "UPDATE wards w
             JOIN districts d ON d.district_id = w.district_id
             SET w.ward_code = '67810'
             WHERE d.district_name LIKE 'Malinyi%'
               AND w.ward_name = 'Kilosampepo'
               AND w.ward_code = '678010'"
        );
        $fix->execute();
        echo "  + Kilosampepo code fix: " . $fix->rowCount() . " row(s) recoded 678010 -> 67810.\n";
    }

    $pdo->commit();
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
