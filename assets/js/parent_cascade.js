/*
 * parent_cascade.js — reusable cascading Parent Account selector.
 * ---------------------------------------------------------------
 * Renders level-by-level dropdowns: pick a top-level group, then drill into its
 * sub-accounts (marked ▸), then sub-of-sub, to any depth. Leaving the first level
 * "None" => top-level account; the deepest concrete choice becomes the parent.
 * The chosen id is mirrored into a hidden <input> the host form submits, so the
 * backend (save_account.php) and its guards are untouched.
 *
 * Usage:
 *   var c = initParentCascade({
 *     container: 'add_parentCascade',     // id or element for the cascade selects
 *     hidden:    'add_parent_account_id', // id or element of the hidden input
 *     accounts:  ACCT_ARRAY,              // [{id, code, name, parent, category}]
 *     category:  'asset',                 // class filter ('' = all classes)
 *     selected:  123,                     // pre-selected parent id ('' = none)
 *     excludeId: 45,                       // exclude self + descendants (edit mode)
 *     onChange:  function(parentId){ ... } // fires only on USER change
 *   });
 *   c.render(parentId);   // re-render pre-selected to a parent
 *   c.setCategory('liability');
 *   c.getParent();        // current chosen parent id (string, '' = none)
 */
(function () {
    function norm(v) { return (v === null || v === undefined) ? '' : String(v); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    function excludedSet(accounts, excludeId) {
        var excl = {};
        if (!excludeId) return excl;
        excl[String(excludeId)] = true;
        var changed = true;
        while (changed) {
            changed = false;
            accounts.forEach(function (a) {
                var p = norm(a.parent);
                if (p && excl[p] && !excl[String(a.id)]) { excl[String(a.id)] = true; changed = true; }
            });
        }
        return excl;
    }

    function childrenOf(accounts, parentId, category, excl) {
        var pid = norm(parentId);
        return accounts.filter(function (a) {
            var p = norm(a.parent);
            if (p === String(a.id)) p = '';                 // self-loop => root
            if (p !== pid) return false;
            if (category && a.category !== category) return false;
            if (excl[String(a.id)]) return false;
            return true;
        });
    }

    function hasChildren(accounts, id) {
        return accounts.some(function (c) { var p = norm(c.parent); return p === String(id) && p !== String(c.id); });
    }

    function ParentCascade(opts) {
        var container = typeof opts.container === 'string' ? document.getElementById(opts.container) : opts.container;
        var hidden    = typeof opts.hidden === 'string' ? document.getElementById(opts.hidden) : opts.hidden;
        var accounts  = opts.accounts || [];
        var category  = opts.category || '';
        var excl      = excludedSet(accounts, opts.excludeId || '');
        var onChange  = typeof opts.onChange === 'function' ? opts.onChange : function () {};

        function buildLevel(depth, pool, chosenId, isRoot) {
            var wrap = document.createElement('div');
            wrap.className = 'mb-2 pcasc-level';
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm';
            sel.setAttribute('data-depth', depth);
            var first = isRoot ? '— None (top-level account) —' : '— Use the account above as the parent —';
            var html = '<option value="">' + first + '</option>';
            pool.forEach(function (a) {
                var mark = hasChildren(accounts, a.id) ? '  ▸' : '';   // ▸
                html += '<option value="' + a.id + '"' + (String(a.id) === String(chosenId) ? ' selected' : '') + '>'
                      + esc(a.code + ' — ' + a.name) + mark + '</option>';
            });
            sel.innerHTML = html;
            sel.addEventListener('change', function () { onLevelChange(depth); });
            wrap.appendChild(sel);
            return wrap;
        }

        function renderChain(selectedParentId, fireChange) {
            container.innerHTML = '';
            var chain = [];
            if (selectedParentId) {
                var cur = accounts.find(function (a) { return String(a.id) === String(selectedParentId); });
                if (cur && (!category || cur.category === category) && !excl[String(cur.id)]) {
                    while (cur) {
                        chain.unshift(String(cur.id));
                        var p = norm(cur.parent);
                        cur = (p && p !== String(cur.id)) ? accounts.find(function (a) { return String(a.id) === p; }) : null;
                    }
                }
            }
            var parentForLevel = '', depth = 0;
            while (true) {
                var pool = childrenOf(accounts, parentForLevel, category, excl);
                if (pool.length === 0) break;
                var chosen = chain[depth] || '';
                container.appendChild(buildLevel(depth, pool, chosen, depth === 0));
                if (!chosen) break;
                parentForLevel = chosen; depth++;
            }
            sync(fireChange);
        }

        function onLevelChange(depth) {
            var levels = Array.prototype.slice.call(container.querySelectorAll('.pcasc-level'));
            levels.slice(depth + 1).forEach(function (el) { el.remove(); });
            var sel = levels[depth].querySelector('select');
            if (sel.value) {
                var kids = childrenOf(accounts, sel.value, category, excl);
                if (kids.length) container.appendChild(buildLevel(depth + 1, kids, '', false));
            }
            sync(true);
        }

        function sync(fireChange) {
            var parent = '';
            container.querySelectorAll('.pcasc-level select').forEach(function (s) { if (s.value) parent = s.value; });
            var changed = (hidden.value !== parent);
            hidden.value = parent;
            if (fireChange && changed) onChange(parent);
        }

        this.render      = function (id) { renderChain(id || '', false); };
        this.setCategory = function (cat) { category = cat || ''; renderChain(hidden.value, false); };
        this.getParent   = function () { return hidden.value; };

        renderChain(opts.selected || '', false);   // initial render: no onChange (programmatic)
    }

    window.initParentCascade = function (opts) { return new ParentCascade(opts); };
})();
