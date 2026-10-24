<?php
/**
 * includes/help_panel.php — the ? Help button and the Help panel (support_center_plan.md D1/D2/D7).
 *
 * header.php calls helpPanelButton() where the button belongs (desktop: beside EN;
 * Smart POS phone: top bar beside EN and ☰; Smart BMS phone: beside ☰) and
 * helpPanelRender() once. Articles load from api/support_help.php when the panel opens;
 * requests are sent to api/support_requests.php. The only page-load work is one indexed
 * count of requests waiting on this user (red dot on the button).
 */
require_once __DIR__ . '/../core/help_nav.php';
require_once __DIR__ . '/../core/support_requests.php';

if (!function_exists('helpPanelWaiting')) {
    function helpPanelWaiting(): int
    {
        static $n = null;
        if ($n === null) {
            $n = 0;
            if (!empty($_SESSION['user_id'])) {
                try { $n = supportRequestWaitingCount(supportCurrentUser()); } catch (Throwable $e) { $n = 0; }
            }
        }
        return $n;
    }
}

if (!function_exists('helpPanelButton')) {
    /** @param string $variant 'desktop' (pill with label) | 'mobile' (round icon) */
    function helpPanelButton(string $variant, string $extraClass = ''): void
    {
        $label = htmlspecialchars(t('Help'), ENT_QUOTES, 'UTF-8');
        $cls = $variant === 'desktop'
            ? 'btn btn-sm btn-outline-light bms-help-btn d-inline-flex align-items-center'
            : 'btn btn-outline-light bms-help-btn bms-help-btn-round d-inline-flex align-items-center justify-content-center';
        echo '<button type="button" class="' . $cls . ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '"'
           . ' data-bs-toggle="offcanvas" data-bs-target="#bmsHelpPanel" aria-controls="bmsHelpPanel" title="' . $label . '" aria-label="' . $label . '">'
           . '<i class="bi bi-question-circle"></i>' . ($variant === 'desktop' ? '<span class="ms-1">' . $label . '</span>' : '')
           . '<span class="bms-help-dot' . (helpPanelWaiting() > 0 ? '' : ' d-none') . '" aria-hidden="true"></span></button>';
    }
}

if (!function_exists('helpPanelRender')) {
    function helpPanelRender(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pageKey = helpCurrentPageKey();
        $dark = (($_SESSION['theme'] ?? 'light') === 'dark');
        $isAdmin = function_exists('isAdmin') && isAdmin();
        $types = [];
        foreach (SUPPORT_CATEGORIES as $k => $lbl) if ($k !== 'billing' || $isAdmin) if ($k !== 'access' || !$isAdmin) $types[$k] = t($lbl);
        $prios = [];
        foreach (SUPPORT_PRIORITIES as $k => $lbl) $prios[$k] = t($lbl);
        $s = [
            'help' => t('Help'), 'search' => t('Search help'), 'here' => t('Help for this page'), 'popular' => t('Popular'),
            'more' => t('More help'), 'none' => t('No articles found.'), 'noneHint' => t('Try other words, or open Help & Support.'),
            'empty' => t('Help articles are being prepared. Please check back soon.'), 'back' => t('Back'),
            'helpful' => t('Was this helpful?'), 'yes' => t('Yes'), 'no' => t('No'), 'thanks' => t('Thanks for your feedback.'),
            'take' => t('Take me there'), 'video' => t('Watch the video'), 'error' => t('Help is not available right now. Please try again.'),
            'onPage' => t('You are on'), 'results' => t('results'), 'all' => t('Help & Support'),
            'still' => t('Still need help?'), 'stillHint' => t("We'll include this article and the page you're on."),
            'contact' => t('Contact support'), 'subject' => t('Subject'), 'type' => t('Type'), 'urgency' => t('How urgent?'),
            'describe' => t('Describe the problem'), 'describePh' => t('What were you trying to do, and what happened?'),
            'attach' => t('Attach screenshots or PDF (optional)'), 'attachHint' => t('JPG, PNG or PDF. Up to 3 files.'),
            'added' => t('Added for you: the page you are on, your name and role, and your browser.'),
            'send' => t('Send'), 'cancel' => t('Cancel'), 'sending' => t('Sending...'), 'sent' => t('Request sent'),
            'sentHint' => t("You'll see the reply here and by email."), 'view' => t('View request'),
            'myRequests' => t('My requests'), 'about' => t('About:'),
        ];
        $J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        ?>
<style>
    .bms-help-btn { position: relative; font-weight: 700; border-radius: 20px; font-size: .75rem; padding: 2px 10px; line-height: 1.6; }
    .bms-help-btn-round { width: 32px; height: 32px; padding: 0; border-radius: 50%; font-size: 1.05rem; }
    .bms-help-dot { position: absolute; top: -3px; right: -3px; width: 10px; height: 10px; border-radius: 50%; background: #dc3545; border: 2px solid #0d6efd; }
    #bmsHelpPanel { width: 420px; }
    @media (max-width: 575.98px) { #bmsHelpPanel { width: 100%; } }
    #bmsHelpPanel .offcanvas-header { background: #0d6efd; color: #fff; }
    #bmsHelpPanel .bh-ctx { font-size: .72rem; background: rgba(255,255,255,.18); border-radius: 20px; padding: 2px 8px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #bmsHelpPanel .bh-lbl { font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6c757d; margin: 14px 0 6px; }
    #bmsHelpPanel .bh-list { border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
    #bmsHelpPanel .bh-item { display: flex; gap: 10px; align-items: center; width: 100%; border: 0; border-bottom: 1px solid #dee2e6; background: transparent; padding: 9px 10px; text-align: left; color: inherit; }
    #bmsHelpPanel .bh-item:last-child { border-bottom: 0; }
    #bmsHelpPanel .bh-item:hover, #bmsHelpPanel .bh-item:focus-visible { background: rgba(13,110,253,.06); }
    #bmsHelpPanel .bh-ic { width: 28px; height: 28px; border-radius: 7px; background: #e7f0ff; color: #0d6efd; display: grid; place-items: center; flex: 0 0 auto; }
    #bmsHelpPanel .bh-t { font-weight: 600; font-size: .88rem; display: block; }
    #bmsHelpPanel .bh-c { font-size: .74rem; color: #6c757d; }
    #bmsHelpPanel .bh-art h6 { font-size: 1.05rem; font-weight: 700; margin-bottom: 2px; }
    #bmsHelpPanel .bh-art ol, #bmsHelpPanel .bh-art ul { padding-left: 1.2rem; }
    #bmsHelpPanel .bh-art li { margin-bottom: 4px; }
    #bmsHelpPanel .help-path { background: #e7f0ff; color: #084298; border-radius: 4px; padding: 0 5px; font-weight: 600; }
    #bmsHelpPanel .help-tip { background: rgba(13,110,253,.06); border-left: 3px solid #0d6efd; padding: 8px 10px; border-radius: 4px; margin: 8px 0; font-size: .88rem; }
    #bmsHelpPanel .bh-note { background: rgba(13,110,253,.06); border-radius: 6px; padding: 6px 10px; font-size: .82rem; margin-top: 10px; }
    #bmsHelpPanel .bh-still { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; padding: 10px; margin-top: 12px; }
    #bmsHelpPanel .bh-count { background: #0d6efd; color: #fff; border-radius: 10px; font-size: .7rem; padding: 0 6px; margin-left: 4px; }
    <?php if ($dark): ?>
    #bmsHelpPanel { background: #24282d; color: #e1e7ec; }
    #bmsHelpPanel .bh-list, #bmsHelpPanel .bh-item { border-color: #3a3f45; }
    #bmsHelpPanel .bh-ic, #bmsHelpPanel .help-path, #bmsHelpPanel .bh-still { background: #1a2a47; color: #8ab8ff; border-color: #2c4677; }
    <?php endif; ?>
</style>
<div class="offcanvas offcanvas-end d-print-none" tabindex="-1" id="bmsHelpPanel" aria-labelledby="bmsHelpTitle" data-page-key="<?= htmlspecialchars((string)$pageKey, ENT_QUOTES, 'UTF-8') ?>">
    <div class="offcanvas-header py-2 gap-2">
        <button type="button" class="btn btn-sm btn-link text-white text-decoration-none p-0 d-none" id="bhBack"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($s['back'], ENT_QUOTES, 'UTF-8') ?></button>
        <h5 class="offcanvas-title fw-bold mb-0 d-flex align-items-center gap-2 flex-grow-1" id="bmsHelpTitle"><i class="bi bi-question-circle"></i><span id="bhTitleText"><?= htmlspecialchars($s['help'], ENT_QUOTES, 'UTF-8') ?></span></h5>
        <span class="bh-ctx d-none" id="bhCtx"></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-3" id="bhBody">
        <div class="input-group mb-1" id="bhSearchWrap">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input type="search" class="form-control" id="bhQ" placeholder="<?= htmlspecialchars($s['search'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($s['search'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" autocomplete="off">
        </div>
        <div id="bhView" aria-live="polite"></div>
        <form id="bhForm" class="d-none" enctype="multipart/form-data" autocomplete="off" novalidate>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="page_key" value="<?= htmlspecialchars((string)$pageKey, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="article_id" id="bhArticleId" value="">
            <div class="mb-2"><label class="form-label small fw-semibold" for="bhSubject"><?= htmlspecialchars($s['subject'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                <input class="form-control" id="bhSubject" name="subject" maxlength="255" required></div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small fw-semibold" for="bhType"><?= htmlspecialchars($s['type'], ENT_QUOTES, 'UTF-8') ?></label>
                    <select class="form-select" id="bhType" name="category"><?php foreach ($types as $k => $l): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="bhPrio"><?= htmlspecialchars($s['urgency'], ENT_QUOTES, 'UTF-8') ?></label>
                    <select class="form-select" id="bhPrio" name="priority"><?php foreach ($prios as $k => $l): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"<?= $k === 'normal' ? ' selected' : '' ?>><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="mb-2"><label class="form-label small fw-semibold" for="bhDesc"><?= htmlspecialchars($s['describe'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                <textarea class="form-control" id="bhDesc" name="body" rows="5" maxlength="5000" required placeholder="<?= htmlspecialchars($s['describePh'], ENT_QUOTES, 'UTF-8') ?>"></textarea></div>
            <div class="mb-2"><label class="form-label small fw-semibold" for="bhFiles"><?= htmlspecialchars($s['attach'], ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="file" id="bhFiles" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                <div class="form-text"><?= htmlspecialchars($s['attachHint'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="small text-muted bg-light rounded p-2 mb-2"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($s['added'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="text-danger small mb-2 d-none" id="bhFormErr" role="alert"></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary" id="bhCancel"><?= htmlspecialchars($s['cancel'], ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-send me-1"></i><?= htmlspecialchars($s['send'], ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
    <div class="border-top p-2 d-flex gap-2" id="bhFoot">
        <a href="<?= htmlspecialchars(getUrl('help') . '?tab=requests', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary flex-fill"><i class="bi bi-inbox me-1"></i><?= htmlspecialchars($s['myRequests'], ENT_QUOTES, 'UTF-8') ?><?php if (helpPanelWaiting() > 0): ?><span class="bh-count"><?= helpPanelWaiting() ?></span><?php endif; ?></a>
        <button type="button" class="btn btn-primary flex-fill" id="bhContact"><i class="bi bi-chat-dots me-1"></i><?= htmlspecialchars($s['contact'], ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</div>
<script>
(function () {
    const S = <?= json_encode($s, $J) ?>;
    const API = <?= json_encode(buildUrl('api/support_help.php'), $J) ?>;
    const REQ_API = <?= json_encode(buildUrl('api/support_requests.php'), $J) ?>;
    const panel = document.getElementById('bmsHelpPanel');
    if (!panel) return;
    const pageKey = panel.dataset.pageKey || '';
    const view = document.getElementById('bhView'), q = document.getElementById('bhQ');
    const back = document.getElementById('bhBack'), ctx = document.getElementById('bhCtx');
    const form = document.getElementById('bhForm'), formErr = document.getElementById('bhFormErr');
    const titleText = document.getElementById('bhTitleText'), foot = document.getElementById('bhFoot');
    const csrf = function () { return typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''; };
    let loaded = false, timer = null, logTimer = null, seq = 0, lastView = 'home';

    function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function post(data) {
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf(), 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
    }
    function mode(m) {
        const isForm = m === 'form';
        document.getElementById('bhSearchWrap').classList.toggle('d-none', m !== 'home');
        view.classList.toggle('d-none', isForm);
        form.classList.toggle('d-none', !isForm);
        foot.classList.toggle('d-none', isForm);
        back.classList.toggle('d-none', m === 'home');
        titleText.textContent = isForm ? S.contact : S.help;
    }
    function message(text) { view.replaceChildren(el('div', 'text-muted small py-3', text)); }
    function list(title, items) {
        const wrap = document.createDocumentFragment();
        wrap.appendChild(el('div', 'bh-lbl', title));
        const box = el('div', 'bh-list');
        items.forEach(function (a) {
            const b = el('button', 'bh-item'); b.type = 'button';
            const ic = el('span', 'bh-ic'); ic.appendChild(el('i', 'bi ' + (a.has_video ? 'bi-play-circle' : 'bi-file-text')));
            const tx = el('span', 'flex-grow-1'); tx.appendChild(el('span', 'bh-t', a.title)); if (a.category) tx.appendChild(el('span', 'bh-c', a.category));
            b.appendChild(ic); b.appendChild(tx); b.appendChild(el('i', 'bi bi-chevron-right text-muted'));
            b.addEventListener('click', function () { openArticle(a.id); });
            box.appendChild(b);
        });
        wrap.appendChild(box);
        return wrap;
    }
    function home() {
        mode('home'); lastView = 'home'; message('…');
        post({ action: 'home', page_key: pageKey }).then(function (r) {
            if (!r.success) { message(S.error); return; }
            loaded = true;
            if (r.page) { ctx.textContent = S.onPage + ': ' + r.page; ctx.classList.remove('d-none'); }
            if (!r.total) { message(S.empty); return; }
            const f = document.createDocumentFragment();
            if (r.here.length) f.appendChild(list(S.here, r.here));
            if (r.popular.length) f.appendChild(list(r.here.length ? S.more : S.popular, r.popular));
            view.replaceChildren(f);
        }).catch(function () { message(S.error); });
    }
    function search(log) {
        const term = q.value.trim();
        if (!term) { home(); return; }
        const my = ++seq;
        post({ action: 'search', q: term, log: log ? '1' : '0' }).then(function (r) {
            if (my !== seq) return;
            if (!r.success) { message(S.error); return; }
            const f = document.createDocumentFragment();
            if (r.note) f.appendChild(el('div', 'bh-note', r.note));
            if (r.results.length) f.appendChild(list(r.results.length + ' ' + S.results, r.results));
            else { f.appendChild(el('div', 'fw-semibold mt-3', S.none)); f.appendChild(el('div', 'text-muted small', S.noneHint)); }
            view.replaceChildren(f);
        }).catch(function () { message(S.error); });
    }
    function openArticle(id) {
        mode('article'); lastView = 'article'; message('…');
        post({ action: 'article', id: id, page_key: pageKey }).then(function (r) {
            if (!r.success) { message(r.message || S.error); return; }
            const a = r.article, box = el('div', 'bh-art');
            box.appendChild(el('h6', null, a.title));
            if (a.category) box.appendChild(el('div', 'bh-c mb-2', a.category));
            const body = el('div'); body.innerHTML = a.html; box.appendChild(body); // server-escaped, allow-listed tags only
            const acts = el('div', 'd-flex flex-wrap gap-2 my-3');
            if (a.take) { const t = el('a', 'btn btn-sm btn-outline-primary', S.take + ' → ' + a.take.label); t.href = a.take.url; acts.appendChild(t); }
            if (a.video_url) { const v = el('a', 'btn btn-sm btn-outline-primary', S.video); v.href = a.video_url; v.target = '_blank'; v.rel = 'noopener noreferrer'; acts.appendChild(v); }
            if (acts.children.length) box.appendChild(acts);
            const fb = el('div', 'd-flex align-items-center gap-2 small text-muted border-top pt-2');
            if (a.voted) fb.textContent = S.thanks;
            else {
                fb.appendChild(el('span', null, S.helpful));
                [['1', 'bi-hand-thumbs-up', S.yes], ['0', 'bi-hand-thumbs-down', S.no]].forEach(function (o) {
                    const b = el('button', 'btn btn-sm btn-outline-secondary'); b.type = 'button'; b.setAttribute('aria-label', o[2]);
                    b.appendChild(el('i', 'bi ' + o[1]));
                    b.addEventListener('click', function () { post({ action: 'feedback', id: a.id, helpful: o[0] }).then(function () { fb.textContent = S.thanks; }); });
                    fb.appendChild(b);
                });
            }
            box.appendChild(fb);
            const still = el('div', 'bh-still');
            still.appendChild(el('div', 'fw-semibold', S.still));
            still.appendChild(el('div', 'small mb-2', S.stillHint));
            const cb = el('button', 'btn btn-primary btn-sm w-100', S.contact); cb.type = 'button';
            cb.addEventListener('click', function () { contact({ subject: S.about + ' ' + a.title, article_id: a.id }); });
            still.appendChild(cb);
            box.appendChild(still);
            view.replaceChildren(box);
            document.getElementById('bhBody').scrollTop = 0;
        }).catch(function () { message(S.error); });
    }
    function contact(prefill) {
        prefill = prefill || {};
        form.reset(); formErr.classList.add('d-none');
        document.getElementById('bhSubject').value = (prefill.subject || '').slice(0, 255);
        document.getElementById('bhArticleId').value = prefill.article_id || '';
        mode('form');
        if (window.innerWidth >= 576) setTimeout(function () { document.getElementById(prefill.subject ? 'bhDesc' : 'bhSubject').focus(); }, 50);
    }
    function sent(r) {
        mode('sent');
        const box = el('div', 'text-center py-4');
        const ic = el('div', 'mb-2'); ic.appendChild(el('i', 'bi bi-check-circle text-primary fs-1')); box.appendChild(ic);
        box.appendChild(el('h6', 'fw-bold', S.sent));
        box.appendChild(el('div', 'font-monospace small mb-2', r.ref));
        box.appendChild(el('p', 'small text-muted', S.sentHint));
        const v = el('a', 'btn btn-primary btn-sm', S.view); v.href = r.url; box.appendChild(v);
        view.replaceChildren(box);
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        formErr.classList.add('d-none');
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const btn = form.querySelector('[type="submit"]'), orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = S.sending;
        fetch(REQ_API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }, body: new FormData(form) })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (r) {
                if (r && r.success) { sent(r); }
                else { formErr.textContent = (r && r.message) || S.error; formErr.classList.remove('d-none'); }
            })
            .catch(function () { formErr.textContent = S.error; formErr.classList.remove('d-none'); })
            .finally(function () { btn.disabled = false; btn.innerHTML = orig; });
    });
    document.getElementById('bhContact').addEventListener('click', function () { contact({}); });
    document.getElementById('bhCancel').addEventListener('click', function () { if (lastView === 'article') { mode('article'); } else { home(); } });
    back.addEventListener('click', function () {
        if (!form.classList.contains('d-none') && lastView === 'article') { mode('article'); return; }
        if (q.value.trim()) { mode('home'); search(false); } else home();
    });
    q.addEventListener('input', function () {
        clearTimeout(timer); clearTimeout(logTimer);
        timer = setTimeout(function () { search(false); }, 300);
        logTimer = setTimeout(function () { if (q.value.trim()) search(true); }, 1800);
    });
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); clearTimeout(logTimer); search(true); } });
    panel.addEventListener('show.bs.offcanvas', function () { if (!loaded) home(); });
    panel.addEventListener('shown.bs.offcanvas', function () { if (window.innerWidth >= 576 && !q.closest('.d-none')) q.focus(); });
    // Used by the Help & Support page's "New request" button.
    window.bmsHelp = {
        contact: function (prefill) { loaded = true; bootstrap.Offcanvas.getOrCreateInstance(panel).show(); contact(prefill); }
    };
})();
</script>
        <?php
    }
}
