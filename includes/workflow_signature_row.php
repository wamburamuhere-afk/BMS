<?php
/**
 * Print-page signature row — Created By / Reviewed By / Approved By.
 * ------------------------------------------------------------------
 * This is the ONE canonical signature block used by every BMS print page.
 * Invoice and Quotation print pages previously had this duplicated inline;
 * they now include this file so there is a single source of truth.
 *
 * Expects $wf array in scope (any key may be null/absent):
 *
 *   EXISTING keys (unchanged — backward-compatible):
 *     created_by_name,  created_by_role
 *     reviewed_by_name, reviewed_by_role
 *     approved_by_name, approved_by_role
 *     __include_css  — if true, outputs the .signature-box CSS block
 *
 *   NEW optional keys (added for e-signature integration):
 *     created_sig_path,  created_signed_at
 *     reviewed_sig_path, reviewed_signed_at
 *     approved_sig_path, approved_signed_at
 *
 *   When *_sig_path is absent or null the column renders exactly as before.
 */

if (!isset($wf) || !is_array($wf)) $wf = [];

if (!empty($wf['__include_css'])):
?>
<style>
    /* ── SIGNATURE ROW ── canonical source — do not duplicate in host pages */
    .signature-box {
        margin-top: 46px;
        display: flex;
        justify-content: space-around;
        gap: 40px;
    }
    .signature-line {
        width: 210px;
        padding-top: 7px;
        text-align: center;
        border-top: 1.5px solid #1a252f;
        font-size: 11px;
        color: #1a252f;
        font-weight: 600;
    }
    .signature-line small {
        display: block;
        margin-top: 4px;
        font-size: 10px;
        font-weight: 400;
        color: #495057;
    }
    .sig-img-wrap {
        min-height: 48px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        margin-bottom: 4px;
    }
    .sig-img-wrap img {
        max-height: 45px;
        max-width: 150px;
        object-fit: contain;
    }
    .sig-protocol {
        display: block;
        font-size: 7.5px;
        font-weight: 600;
        color: #0a6efd;
        letter-spacing: 0.02em;
        margin-top: 2px;
    }
    .sig-timestamp {
        display: block;
        font-size: 7px;
        font-weight: 400;
        color: #6c757d;
        margin-top: 1px;
    }
</style>
<?php endif; ?>

<?php
/**
 * Render one signature column.
 * $name, $role   — existing text fields
 * $sigPath       — file_path from workflow_signatures (may be null)
 * $signedAt      — TIMESTAMP string from workflow_signatures (may be null)
 * $label         — column heading: "Created By" / "Reviewed By" / "Approved By"
 */
$_renderSigCol = function(string $label, string $name, string $role,
                           ?string $sigPath, ?string $signedAt) use ($wf): void {
    echo '<div class="signature-line">';

    if ($sigPath) {
        // Build a root-relative URL from the stored file_path
        $sigUrl = rtrim(getUrl(''), '/') . '/' . ltrim($sigPath, '/');
        $ts     = '';
        if ($signedAt) {
            $dt = new DateTime($signedAt);
            $ts = $dt->format('d M Y  H:i:s');
        }
        echo '<div class="sig-img-wrap">';
        echo '<img src="' . htmlspecialchars($sigUrl) . '" alt="e-signature">';
        if ($ts) {
            echo '<span class="sig-timestamp">' . htmlspecialchars($ts) . '</span>';
        }
        echo '</div>';
    }

    echo htmlspecialchars($label) . '<br>';
    echo '<small>';
    echo htmlspecialchars($name) . ($role ? ' &mdash; ' . htmlspecialchars($role) : '');
    echo '</small>';
    echo '</div>';
};

echo '<div class="signature-box">';
$_renderSigCol(
    'Created By',
    $wf['created_by_name']  ?? '',
    $wf['created_by_role']  ?? '',
    $wf['created_sig_path']  ?? null,
    $wf['created_signed_at'] ?? null
);
$_renderSigCol(
    'Reviewed By',
    $wf['reviewed_by_name']  ?? '',
    $wf['reviewed_by_role']  ?? '',
    $wf['reviewed_sig_path']  ?? null,
    $wf['reviewed_signed_at'] ?? null
);
$_renderSigCol(
    'Approved By',
    $wf['approved_by_name']  ?? '',
    $wf['approved_by_role']  ?? '',
    $wf['approved_sig_path']  ?? null,
    $wf['approved_signed_at'] ?? null
);
echo '</div>';
?>
