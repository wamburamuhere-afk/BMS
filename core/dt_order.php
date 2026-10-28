<?php
// core/dt_order.php — ORDER BY clause for a server-side DataTables request.
//
// The clicked column is looked up by its data name (columns[i][data], or
// columns[i][name] for render-only columns) in a whitelist, so nothing from
// the request reaches the SQL. The S/No column (assets/js/bms-serial.js) sorts
// by the list's normal order, reversed when descending; anything unknown
// falls back to the normal order.

/**
 * @param array  $req      the DataTables request ($_GET or $_POST)
 * @param array  $sortable data name => SQL expression
 * @param string $normal   the list's normal ORDER BY (S/No ascending)
 * @param string $reversed the same order reversed (S/No descending)
 * @param string $tie      unique column, so equal values page deterministically
 * @param int    $snCol    index of the S/No column
 */
function dtOrderSql(array $req, array $sortable, string $normal, string $reversed, string $tie, int $snCol = 0): string
{
    $col = (int)($req['order'][0]['column'] ?? -1);
    $dir = strtolower((string)($req['order'][0]['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
    if ($col === $snCol) {
        return $dir === 'DESC' ? $reversed : $normal;
    }
    $key = $req['columns'][$col]['data'] ?? '';
    if (!is_string($key) || $key === '') {
        $key = $req['columns'][$col]['name'] ?? '';
    }
    if (is_string($key) && isset($sortable[$key])) {
        return $sortable[$key] . " $dir, $tie $dir";
    }
    return $normal;
}
