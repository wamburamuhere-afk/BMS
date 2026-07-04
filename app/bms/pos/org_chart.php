<?php
// Organisation Chart — Tier 2, Phase 2.4.
// Pure-CSS collapsible tree (<details>/<summary>) built client-side from
// reporting_to_id. Employees with no manager, or whose manager isn't in the
// (scope-filtered) result set, render as roots. Depth-capped so a stray
// cycle can never hang the page. Data from api/get_org_chart.php.
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('org_chart');

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View org chart', 'User viewed the Organisation Chart');
?>

<style>
@media print {
    .d-print-none { display: none !important; }
    details { }
}
.org-node { margin-left: 1.5rem; border-left: 2px solid #dee2e6; padding-left: .85rem; }
.org-node-root { margin-left: 0; border-left: none; padding-left: 0; }
.org-card {
    display: inline-flex; align-items: center; gap: .6rem;
    padding: .4rem .7rem; margin: .3rem 0; background: #f8f9fa; border-radius: .5rem;
    border: 1px solid #e9ecef; min-width: 220px;
}
.org-card:hover { background: #e7f0ff; border-color: #b6ccfe; }
.org-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    background: #0d6efd; color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: .8rem;
}
details > summary { cursor: pointer; list-style: none; }
details > summary::-webkit-details-marker { display: none; }
details > summary::before { content: "\25B6"; font-size: .65rem; margin-right: .35rem; color: #6c757d; }
details[open] > summary::before { content: "\25BC"; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Organisation Chart</h4>
        <button onclick="window.print()" class="btn btn-outline-secondary d-print-none">
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body" id="chartRoot">
            <div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span> Loading...</div>
        </div>
    </div>
</div>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const MAX_DEPTH = 20;

function initials(first, last) {
    return ((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?';
}

function buildTree(rows) {
    const byId = {};
    rows.forEach(r => { byId[r.employee_id] = Object.assign({ children: [] }, r); });
    const roots = [];
    rows.forEach(r => {
        const node = byId[r.employee_id];
        const pid = r.reporting_to_id ? Number(r.reporting_to_id) : null;
        if (pid && byId[pid] && pid !== Number(r.employee_id)) {
            byId[pid].children.push(node);
        } else {
            roots.push(node); // no manager, or manager outside this scope-filtered set
        }
    });
    return roots;
}

function nodeCard(n) {
    const name = safeOutput((n.first_name || '') + ' ' + (n.last_name || ''));
    const sub = [n.designation_name, n.department_name].filter(Boolean).map(safeOutput).join(' &middot; ') || '&mdash;';
    return `<a href="<?= getUrl('employee_details') ?>?id=${n.employee_id}" class="text-decoration-none text-dark">
        <div class="org-card">
            <div class="org-avatar">${initials(n.first_name, n.last_name)}</div>
            <div>
                <div class="fw-semibold">${name}</div>
                <div class="small text-muted">${sub}</div>
            </div>
        </div>
    </a>`;
}

function renderNode(n, depth, isRoot) {
    const card = nodeCard(n);
    const wrap = isRoot ? 'org-node org-node-root' : 'org-node';
    if (!n.children.length) {
        return `<div class="${wrap}">${card}</div>`;
    }
    if (depth >= MAX_DEPTH) {
        return `<div class="${wrap}">${card}<div class="small text-muted ms-4">&hellip; (more levels not shown)</div></div>`;
    }
    const childrenHtml = n.children.map(c => renderNode(c, depth + 1, false)).join('');
    return `<div class="${wrap}"><details open><summary>${card}</summary>${childrenHtml}</details></div>`;
}

$(document).ready(function () {
    $.getJSON('<?= buildUrl('api/get_org_chart.php') ?>', function (res) {
        if (!res.success) {
            $('#chartRoot').html('<div class="text-danger text-center py-4">' + safeOutput(res.message || 'Could not load the org chart.') + '</div>');
            return;
        }
        if (!res.data.length) {
            $('#chartRoot').html('<div class="text-muted text-center py-5">No employees to display.</div>');
            return;
        }
        const roots = buildTree(res.data);
        $('#chartRoot').html(roots.map(r => renderNode(r, 0, true)).join(''));
    }).fail(function () {
        $('#chartRoot').html('<div class="text-danger text-center py-4">Server error loading the org chart.</div>');
    });
});
</script>

<?php includeFooter(); ?>
