<?php
/**
 * Shared 1–5 star rating widget (Tier 3, §8.4).
 * Used identically by the designation target matrix (Phase 3.2) and the
 * appraisal scorecard (Phase 3.3) so ratings render the same everywhere.
 *
 * starRatingAssets()  — emit the shared CSS + delegated JS ONCE per page.
 * starRatingWidget($name, $value = 0, $readonly = false, $expected = null)
 *                     — one interactive (or read-only) star row backed by a
 *                       hidden input named $name. $expected (optional) draws a
 *                       faint marker of the target rating for comparison.
 */

if (!function_exists('starRatingAssets')) {
    function starRatingAssets(): void
    {
        if (!empty($GLOBALS['__star_assets_done'])) return;
        $GLOBALS['__star_assets_done'] = true;
        ?>
        <style>
        .star-rating { display:inline-flex; gap:2px; align-items:center; }
        .star-rating .star { cursor:pointer; font-size:1.15rem; line-height:1; color:#ced4da; background:none; border:0; padding:0; }
        .star-rating.readonly .star { cursor:default; }
        .star-rating .star.filled { color:#f5b301; }
        .star-rating .star.expected-mark { position:relative; }
        .star-rating .star.expected-mark::after { content:''; position:absolute; left:50%; bottom:-4px; width:4px; height:4px; border-radius:50%; background:#0d6efd; transform:translateX(-50%); }
        .star-rating .star-clear { margin-left:6px; font-size:.7rem; color:#adb5bd; cursor:pointer; }
        .star-rating.readonly .star-clear { display:none; }
        </style>
        <script>
        // Delegated: works for widgets added after load (modals, ajax matrix).
        document.addEventListener('click', function (e) {
            const star = e.target.closest('.star-rating:not(.readonly) .star');
            if (star) {
                const wrap = star.closest('.star-rating');
                const val = parseInt(star.dataset.val, 10);
                setStarValue(wrap, val);
                return;
            }
            const clr = e.target.closest('.star-rating:not(.readonly) .star-clear');
            if (clr) { setStarValue(clr.closest('.star-rating'), 0); }
        });
        function setStarValue(wrap, val) {
            wrap.querySelector('input[type="hidden"]').value = val;
            wrap.querySelectorAll('.star').forEach(s => {
                s.classList.toggle('filled', parseInt(s.dataset.val, 10) <= val);
            });
        }
        </script>
        <?php
    }
}

if (!function_exists('starRatingWidget')) {
    function starRatingWidget(string $name, int $value = 0, bool $readonly = false, ?int $expected = null): string
    {
        $ro = $readonly ? ' readonly' : '';
        $html = '<span class="star-rating' . $ro . '" data-name="' . htmlspecialchars($name, ENT_QUOTES) . '">';
        $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . (int)$value . '">';
        for ($i = 1; $i <= 5; $i++) {
            $filled = $i <= $value ? ' filled' : '';
            $mark   = ($expected !== null && $i === $expected) ? ' expected-mark' : '';
            $html .= '<button type="button" class="star' . $filled . $mark . '" data-val="' . $i . '" title="' . $i . '">&#9733;</button>';
        }
        if (!$readonly) {
            $html .= '<span class="star-clear" title="Clear">clear</span>';
        }
        $html .= '</span>';
        return $html;
    }
}
