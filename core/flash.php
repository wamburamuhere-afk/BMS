<?php
/**
 * One-time messages ("flash") kept in the session, not in the address bar.
 *
 * Before: header("Location: invoices?error=Invoice Not Found") put the text in
 * the URL — ugly, shareable, forgeable (anyone could craft ?error=<text>), back
 * again on every refresh — and nothing on the list page ever displayed it, so
 * the user just landed on the list with no explanation.
 *
 * Now: bmsFlashRedirect(getUrl('invoices'), 'error', t('Invoice Not Found'))
 * stores the text in $_SESSION, redirects to a clean address, and the next page
 * header.php renders shows it once as a toast and forgets it.
 *
 * Session note: roots.php releases the session lock early (core/session_guard.php),
 * after which $_SESSION is a plain array whose changes are saved at shutdown —
 * even after exit(). So these helpers only touch $_SESSION; they never call
 * session_start().
 */

if (!function_exists('bmsFlash')) {
    /** Queue a message for the next page. $type: error | success | warning | info. */
    function bmsFlash(string $type, string $message): void
    {
        if (!in_array($type, ['error', 'success', 'warning', 'info'], true)) $type = 'info';
        $message = trim($message);
        if ($message === '') return;
        $q = $_SESSION['bms_flash'] ?? [];
        $q[] = ['type' => $type, 'message' => mb_substr($message, 0, 500)];
        $_SESSION['bms_flash'] = array_slice($q, -5);   // never let a loop grow the session
    }
}

if (!function_exists('bmsFlashRedirect')) {
    /** Queue a message, send the browser to a clean address, stop. */
    function bmsFlashRedirect(string $to, string $type, string $message): void
    {
        bmsFlash($type, $message);
        if (!headers_sent()) {
            header('Location: ' . $to);
            exit();
        }
        // Some pages print the site header before they validate the id, so the
        // Location header can no longer be sent (PHP drops it silently and the
        // user is left on a half-built page). Redirect from the browser instead.
        $u = json_encode($to, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        echo '<script>location.replace(' . $u . ');</script>'
           . '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to, ENT_QUOTES) . '"></noscript>';
        exit();
    }
}

if (!function_exists('bmsFlashDrain')) {
    /** Return every queued message and clear the queue (called by header.php). */
    function bmsFlashDrain(): array
    {
        if (empty($_SESSION['bms_flash'])) return [];
        $q = $_SESSION['bms_flash'];
        unset($_SESSION['bms_flash']);
        return is_array($q) ? $q : [];
    }
}

if (!function_exists('bmsFlashScript')) {
    /**
     * The <script> that shows queued messages as toasts. Needs SweetAlert2 and
     * must be printed after it loads. Text goes through json_encode, so it is
     * data to the page, never markup.
     */
    function bmsFlashScript(): string
    {
        $q = bmsFlashDrain();
        if (!$q) return '';
        $json = json_encode($q, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return "<script>(function(){var q=$json;function go(){q.forEach(function(m,i){setTimeout(function(){"
             . "Swal.fire({toast:true,position:'top-end',icon:m.type,title:m.message,showConfirmButton:false,timer:5000,timerProgressBar:true});"
             . "},i*400);});}"
             . "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',go);}else{go();}})();</script>";
    }
}
