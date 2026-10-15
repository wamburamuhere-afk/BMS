<?php
/**
 * Header top-nav group renderer — "show only what the role can open".
 *
 * Each header group (Core, Finance, Sales, ...) is built by buffering its
 * already-canView()-gated menu markup and handing it to bmsNavMenu(), which
 * counts the links actually present:
 *
 *   0 links  -> nothing is rendered
 *   1 link   -> that page is rendered as a direct header link (no dropdown)
 *   2+ links -> the normal "Group ▾" dropdown, minus any section header
 *               left with no links under it
 *
 * So a role granted only Quotations sees "Quotations" in the header, and a
 * role granted Quotations + Sales Orders sees "Sales ▾". A group appears as
 * soon as any one of its pages is granted — no "flagship" page_key needed.
 *
 * A link carrying data-nav-companion (e.g. Receive Payment beside Invoices,
 * both behind canView('invoices')) is shown inside the dropdown but not
 * counted, so one permission = one item.
 *
 * Simple POS mode (posSimpleModeEnabled()) is deliberately untouched: there
 * the caller passes its original gate and the group renders exactly as
 * before — full dropdown when the gate is true, nothing otherwise.
 */

if (!function_exists('bmsNavMenu')) {
    /**
     * @param string $menuHtml    The group's dropdown element, wrapper included
     *                            (<ul class="dropdown-menu">… or the Reports mega <div>).
     * @param string $id          Toggle element id (e.g. 'salesDropdown').
     * @param string $toggleInner Icon + label HTML for the group toggle.
     * @param bool   $legacyGate  The group's original visibility gate (Simple POS mode only).
     * @param string $liClass     Class of the dropdown <li> (Reports uses 'nav-item mega-dropdown').
     * @return bool               True when something was rendered.
     */
    function bmsNavMenu(string $menuHtml, string $id, string $toggleInner, bool $legacyGate, string $liClass = 'nav-item dropdown'): bool
    {
        $simple = function_exists('posSimpleModeEnabled') && posSimpleModeEnabled();

        if (!$simple) {
            $links = bmsNavMenuLinks($menuHtml);
            if (count($links) === 0) {
                return false;
            }
            if (count($links) === 1) {
                echo '<li class="nav-item"><a class="nav-link" href="' . $links[0]['href'] . '">'
                    . $links[0]['inner'] . '</a></li>';
                return true;
            }
            $menuHtml = bmsNavMenuStripEmptyHeaders($menuHtml);
        } elseif (!$legacyGate) {
            return false;
        }

        echo '<li class="' . $liClass . '">'
            . '<a class="nav-link dropdown-toggle" href="#" id="' . $id . '" role="button" data-bs-toggle="dropdown" aria-expanded="false">'
            . $toggleInner . '</a>' . $menuHtml . '</li>';
        return true;
    }

    /**
     * Counted links in a menu: [['href' => raw attr value, 'inner' => inner HTML], ...].
     * Companion links (data-nav-companion) are skipped.
     */
    function bmsNavMenuLinks(string $menuHtml): array
    {
        $out = [];
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $menuHtml, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $attrs = $a[1];
                if (!preg_match('/\bclass="[^"]*\bdropdown-item\b/', $attrs)) continue;
                if (strpos($attrs, 'data-nav-companion') !== false) continue;
                $href = preg_match('/\bhref="([^"]*)"/', $attrs, $h) ? $h[1] : '#';
                $out[] = ['href' => $href, 'inner' => trim($a[2])];
            }
        }
        return $out;
    }

    /** Drop section headers (and empty mega-menu columns) that have no link under them. */
    function bmsNavMenuStripEmptyHeaders(string $menuHtml): string
    {
        // (?:(?!<\/h6>).)* keeps each match inside ONE heading — a plain .*? could
        // stretch across a whole section of links to reach a later empty heading.
        $text   = '(?:(?!<\/h6>).)*';
        $header = '<li>\s*<h6 class="dropdown-header">' . $text . '<\/h6>\s*<\/li>';
        do {
            $before = $menuHtml;
            $menuHtml = preg_replace('/' . $header . '(\s*)(?=' . $header . '|<\/ul>)/s', '$1', $menuHtml);
        } while ($menuHtml !== $before);

        return preg_replace('/<div class="col-lg-3 mega-column">\s*<h6>' . $text . '<\/h6>\s*<\/div>/s', '', $menuHtml);
    }
}
