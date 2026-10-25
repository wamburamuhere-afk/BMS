<?php
/**
 * includes/ask_widget.php — the floating "Ask BMS" chat (platform_ai_plan.md, Phase 5b).
 *
 * A round button at the bottom-right that opens a small chat, modelled on the
 * Ajira Portal helpdesk. It adds NO new data endpoint — it reuses:
 *   - api/support_help.php   popular questions (published articles) + article search
 *   - api/ai/ask.php         AI answers, limited to what this user may open (ai_insights)
 *   - the Help panel's Contact support form (window.bmsHelp.contact), pre-filled
 *
 * Rendered ONLY when the company's AI Assistant module is allowed and the user may
 * use it (canView('ai_assistant') checks the module first). Hidden on the POS Sell
 * screen. Chat history lives only in this browser tab (sessionStorage).
 * Everything the server or the model returns is inserted as text, except article
 * bodies, which api/support_help.php builds from escaped text only.
 */
require_once __DIR__ . '/../core/help_nav.php';
require_once __DIR__ . '/../core/support.php';

if (!function_exists('askWidgetAllowed')) {
    function askWidgetAllowed(): bool
    {
        if (empty($_SESSION['user_id'])) return false;
        if (function_exists('tenantFeatureEnabled') && !tenantFeatureEnabled('ai_assistant')) return false;
        if (!function_exists('canView') || !canView('ai_assistant')) return false;
        return helpCurrentPageKey() !== 'pos';   // never cover the cashier's Sell screen
    }
}

if (!function_exists('askWidgetRender')) {
    function askWidgetRender(): void
    {
        static $done = false;
        if ($done || !askWidgetAllowed()) return;
        $done = true;

        $aiReady = function_exists('aiConfigured') && aiConfigured();
        $hours = '';
        if (function_exists('supportSettings')) $hours = trim((string)(supportSettings()['support_hours'] ?? ''));
        $first = '';
        try {
            $st = $GLOBALS['pdo']->prepare('SELECT first_name FROM users WHERE user_id = ?');
            $st->execute([(int)$_SESSION['user_id']]);
            $first = trim((string)$st->fetchColumn());
        } catch (Throwable $e) { $first = ''; }
        $hour = (int)(new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format('G');
        $greet = $hour < 12 ? t('Good morning') : ($hour < 17 ? t('Good afternoon') : t('Good evening'));
        $bnav = function_exists('posSimpleModeEnabled') && posSimpleModeEnabled();
        $dark = (($_SESSION['theme'] ?? 'light') === 'dark');
        require_once __DIR__ . '/../core/ai_service.php';
        $product = aiProductName();   // "Smart POS" | "Smart BMS"

        $s = [
            'title' => $product === 'Smart POS' ? t('Ask Smart POS') : t('Ask Smart BMS'), 'hello' => $greet . ($first !== '' ? ', ' . $first : '') . '!',
            'how' => t('How can we help you today?'), 'popular' => t('Popular questions'),
            'ph' => t('Type your question…'), 'send' => t('Send'), 'person' => t('Talk to a person'),
            'found' => t('These guides may help:'), 'aiOffer' => t('Get an answer from AI'),
            'none' => t('I could not find a guide for that.'), 'noneHint' => t('You can talk to a person — we will reply here and by email.'),
            'thinking' => t('Thinking…'), 'error' => t('Help is not available right now. Please try again.'),
            'take' => t('Take me there'), 'restart' => t('Start over'), 'close' => t('Close'),
            'hours' => $hours !== '' ? t('Support hours:') . ' ' . $hours : t('Answers cover only what you can open.'),
            'you' => t('You'), 'bms' => $product, 'subject' => t('Question:'), 'chat' => t('Chat so far:'),
            'aiNote' => t('AI answer — check important figures before acting on them.'),
        ];
        $J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
<style>
    .bms-ask-btn { position: fixed; right: 20px; bottom: 20px; z-index: 1040; width: 56px; height: 56px; border-radius: 50%; border: 0;
        background: #0d6efd; color: #fff; box-shadow: 0 6px 18px rgba(13,110,253,.4); display: grid; place-items: center; font-size: 1.45rem; }
    .bms-ask-btn:focus-visible { outline: 3px solid #b6ccfe; outline-offset: 2px; }
    .bms-ask-box { position: fixed; right: 20px; bottom: 88px; z-index: 1041; width: 370px; max-width: calc(100vw - 24px);
        height: min(560px, calc(100vh - 120px)); display: flex; flex-direction: column; border-radius: 14px; overflow: hidden;
        background: <?= $dark ? '#24282d' : '#fff' ?>; color: <?= $dark ? '#e1e7ec' : 'inherit' ?>; box-shadow: 0 12px 36px rgba(0,0,0,.22); border: 1px solid <?= $dark ? '#3a3f45' : '#dee2e6' ?>; }
    .bms-ask-head { background: #0d47a1; color: #fff; padding: 10px 12px; display: flex; align-items: center; gap: 10px; }
    .bms-ask-head .ic { width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,.18); display: grid; place-items: center; flex: 0 0 auto; }
    .bms-ask-head .t { font-weight: 700; line-height: 1.2; }
    .bms-ask-head .s { font-size: .72rem; opacity: .85; }
    .bms-ask-head button { background: none; border: 0; color: #fff; width: 32px; height: 32px; border-radius: 50%; }
    .bms-ask-head button:hover { background: rgba(255,255,255,.15); }
    .bms-ask-log { flex: 1 1 auto; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .bms-ask-msg { max-width: 88%; padding: 8px 11px; border-radius: 12px; font-size: .88rem; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; }
    .bms-ask-msg.b { align-self: flex-start; background: <?= $dark ? '#1a2a47' : '#e7f0ff' ?>; border-bottom-left-radius: 4px; }
    .bms-ask-msg.u { align-self: flex-end; background: #0d6efd; color: #fff; border-bottom-right-radius: 4px; }
    .bms-ask-msg.art { white-space: normal; }
    .bms-ask-msg.art ol, .bms-ask-msg.art ul { padding-left: 1.1rem; margin-bottom: .4rem; }
    .bms-ask-msg .help-path { background: rgba(13,110,253,.12); border-radius: 4px; padding: 0 4px; font-weight: 600; }
    .bms-ask-msg .help-tip { border-left: 3px solid #0d6efd; padding: 4px 8px; margin: 6px 0; }
    .bms-ask-note { font-size: .7rem; color: #6c757d; align-self: flex-start; margin-top: -4px; }
    .bms-ask-chips { padding: 0 12px 8px; display: flex; flex-wrap: wrap; gap: 6px; }
    .bms-ask-chip { border: 1px solid #b6ccfe; background: <?= $dark ? 'transparent' : '#fff' ?>; color: #0d6efd; border-radius: 16px; font-size: .78rem; padding: 3px 10px; text-align: left; }
    .bms-ask-chip:hover, .bms-ask-chip:focus-visible { background: #e7f0ff; }
    .bms-ask-lbl { font-size: .7rem; color: #6c757d; padding: 0 12px 4px; }
    .bms-ask-foot { border-top: 1px solid <?= $dark ? '#3a3f45' : '#dee2e6' ?>; padding: 8px 10px; }
    .bms-ask-foot form { display: flex; gap: 6px; }
    .bms-ask-foot input { border-radius: 18px; }
    .bms-ask-foot .send { width: 38px; height: 38px; border-radius: 50%; flex: 0 0 auto; }
    @media (max-width: 575.98px) { .bms-ask-box { right: 12px; left: 12px; width: auto; bottom: 84px; } .bms-ask-btn { right: 14px; } }
    <?php if ($bnav): ?>
    @media (max-width: 991.98px) { .bms-ask-btn { bottom: 86px; } .bms-ask-box { bottom: 152px; height: min(520px, calc(100vh - 190px)); } }
    <?php endif; ?>
    @media print { .bms-ask-btn, .bms-ask-box { display: none !important; } }
</style>
<button type="button" class="bms-ask-btn d-print-none" id="bmsAskBtn" aria-controls="bmsAskBox" aria-expanded="false" aria-label="<?= $e($s['title']) ?>" title="<?= $e($s['title']) ?>"><i class="bi bi-chat-dots-fill"></i></button>
<div class="bms-ask-box d-none d-print-none" id="bmsAskBox" role="dialog" aria-labelledby="bmsAskTitle" data-ai="<?= $aiReady ? '1' : '0' ?>">
    <div class="bms-ask-head">
        <span class="ic"><i class="bi bi-stars"></i></span>
        <div class="flex-grow-1"><div class="t" id="bmsAskTitle"><?= $e($s['title']) ?></div><div class="s"><?= $e($s['hours']) ?></div></div>
        <button type="button" id="bmsAskRestart" title="<?= $e($s['restart']) ?>" aria-label="<?= $e($s['restart']) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button type="button" id="bmsAskClose" title="<?= $e($s['close']) ?>" aria-label="<?= $e($s['close']) ?>"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="bms-ask-log" id="bmsAskLog" aria-live="polite"></div>
    <div class="bms-ask-lbl d-none" id="bmsAskChipsLbl"><?= $e($s['popular']) ?></div>
    <div class="bms-ask-chips" id="bmsAskChips"></div>
    <div class="bms-ask-foot">
        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill mb-2" id="bmsAskPerson"><i class="bi bi-person-headset me-1"></i><?= $e($s['person']) ?></button>
        <form id="bmsAskForm" autocomplete="off">
            <input type="text" class="form-control" id="bmsAskQ" maxlength="500" placeholder="<?= $e($s['ph']) ?>" aria-label="<?= $e($s['ph']) ?>">
            <button type="submit" class="btn btn-primary send" aria-label="<?= $e($s['send']) ?>"><i class="bi bi-send-fill"></i></button>
        </form>
    </div>
</div>
<script>
(function () {
    const S = <?= json_encode($s, $J) ?>;
    const HELP = <?= json_encode(buildUrl('api/support_help.php'), $J) ?>;
    const ASK = <?= json_encode(buildUrl('api/ai/ask.php'), $J) ?>;
    const KEY = 'bmsAsk.v1.' + <?= json_encode((string)(int)$_SESSION['user_id'], $J) ?>;
    const btn = document.getElementById('bmsAskBtn'), box = document.getElementById('bmsAskBox');
    if (!btn || !box) return;
    const AI = box.dataset.ai === '1';
    const log = document.getElementById('bmsAskLog'), chips = document.getElementById('bmsAskChips');
    const chipsLbl = document.getElementById('bmsAskChipsLbl'), form = document.getElementById('bmsAskForm'), q = document.getElementById('bmsAskQ');
    const pageKey = (document.getElementById('bmsHelpPanel') || {}).dataset ? (document.getElementById('bmsHelpPanel').dataset.pageKey || '') : '';
    const csrf = function () { return typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''; };
    let hist = [], busy = false, started = false;

    function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    // AI text → bold, bullet and numbered lists. Built only from createElement + text nodes,
    // so nothing the model writes can become markup.
    function inline(node, s) {
        String(s).replace(/`/g, '').split(/(\*\*[^*]+\*\*)/g).forEach(function (part) {
            if (/^\*\*[^*]+\*\*$/.test(part)) node.appendChild(el('strong', null, part.slice(2, -2)));
            else if (part) node.appendChild(document.createTextNode(part.replace(/\*\*/g, '')));
        });
    }
    function rich(text) {
        const out = el('div'); let list = null, tag = '';
        String(text).replace(/\r/g, '').split('\n').forEach(function (raw) {
            const line = raw.trim();
            if (line === '') return;   // blank lines keep a numbered list going
            const ul = /^[-*•]\s+(.*)$/.exec(line), ol = /^(\d+)[.)]\s+(.*)$/.exec(line);
            if (ul || ol) {
                const want = ul ? 'ul' : 'ol';
                if (!list || tag !== want) {
                    list = el(want, 'mb-1'); tag = want;
                    if (ol) list.start = parseInt(ol[1], 10) || 1;
                    out.appendChild(list);
                }
                const li = el('li'); inline(li, ul ? ul[1] : ol[2]); list.appendChild(li);
                return;
            }
            list = null;
            const h = /^#{1,6}\s+(.*)$/.exec(line);
            const p = el('div', h ? 'fw-semibold mt-1' : 'mb-1'); inline(p, h ? h[1] : line); out.appendChild(p);
        });
        return out;
    }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(hist.slice(-30))); } catch (e) {} }
    function load() { try { const v = JSON.parse(sessionStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
    function scroll() { log.scrollTop = log.scrollHeight; }
    function post(url, data) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf(), 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ _csrf: csrf() }, data)) })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
    }
    // One chat entry. Kinds: u (user text), b (bot text), ai (AI text), list (article buttons), art (article body, not stored).
    function render(m) {
        if (m.k === 'u') log.appendChild(el('div', 'bms-ask-msg u', m.t));
        else if (m.k === 'b') log.appendChild(el('div', 'bms-ask-msg b', m.t));
        else if (m.k === 'ai') { const w = el('div', 'bms-ask-msg b art'); w.appendChild(rich(m.t)); log.appendChild(w); log.appendChild(el('div', 'bms-ask-note', S.aiNote)); }
        else if (m.k === 'list') {
            const w = el('div', 'bms-ask-msg b'); w.appendChild(el('div', 'mb-1', m.t));
            (m.a || []).forEach(function (a) {
                const b = el('button', 'bms-ask-chip d-block mb-1', a.title); b.type = 'button';
                b.addEventListener('click', function () { openArticle(a.id, a.title); });
                w.appendChild(b);
            });
            if (m.offer && AI) {
                const o = el('button', 'btn btn-sm btn-primary rounded-pill mt-1', S.aiOffer); o.type = 'button';
                o.addEventListener('click', function () { o.remove(); aiAnswer(m.offer); });
                w.appendChild(o);
            }
            log.appendChild(w);
        }
        scroll();
    }
    function add(m, keep) { render(m); if (keep !== false) { hist.push(m); save(); } }
    function typing() { const t = el('div', 'bms-ask-msg b text-muted', S.thinking); log.appendChild(t); scroll(); return t; }
    function setBusy(v) { busy = v; form.querySelector('[type="submit"]').disabled = v; }

    function start() {
        if (started) return; started = true;
        hist = load();
        if (!hist.length) { add({ k: 'b', t: S.hello + ' ' + S.how }); }
        else hist.forEach(render);
        post(HELP, { action: 'home', page_key: pageKey }).then(function (r) {
            if (!r || !r.success) return;
            const items = (r.here || []).concat(r.popular || []).slice(0, 6);
            chips.replaceChildren();
            items.forEach(function (a) {
                const c = el('button', 'bms-ask-chip', a.title); c.type = 'button';
                c.addEventListener('click', function () { openArticle(a.id, a.title); });
                chips.appendChild(c);
            });
            chipsLbl.classList.toggle('d-none', !items.length);
        });
    }
    function openArticle(id, title) {
        if (busy) return;
        add({ k: 'u', t: title });
        setBusy(true); const t = typing();
        post(HELP, { action: 'article', id: id, page_key: pageKey }).then(function (r) {
            t.remove();
            if (!r || !r.success) { add({ k: 'b', t: (r && r.message) || S.error }); return; }
            const a = r.article, w = el('div', 'bms-ask-msg b art');
            w.appendChild(el('div', 'fw-semibold mb-1', a.title));
            const body = el('div'); body.innerHTML = a.html; w.appendChild(body);   // server-escaped, allow-listed tags only
            if (a.take) { const l = el('a', 'btn btn-sm btn-outline-primary mt-1', S.take + ' → ' + a.take.label); l.href = a.take.url; w.appendChild(l); }
            log.appendChild(w); scroll();
            hist.push({ k: 'b', t: a.title }); save();
        }).catch(function () { t.remove(); add({ k: 'b', t: S.error }); }).finally(function () { setBusy(false); });
    }
    function aiAnswer(text) {
        setBusy(true); const t = typing();
        post(ASK, { question: text }).then(function (r) {
            t.remove();
            if (r && r.success && r.answer) add({ k: 'ai', t: String(r.answer) });
            else { add({ k: 'b', t: (r && r.message) || S.error }); add({ k: 'b', t: S.noneHint }); }
        }).catch(function () { t.remove(); add({ k: 'b', t: S.error }); }).finally(function () { setBusy(false); });
    }
    function ask(text) {
        add({ k: 'u', t: text });
        setBusy(true); const t = typing();
        post(HELP, { action: 'search', q: text.slice(0, 100), log: '1' }).then(function (r) {
            t.remove();
            const hits = (r && r.success && Array.isArray(r.results)) ? r.results.slice(0, 3) : [];
            if (hits.length) { add({ k: 'list', t: S.found, a: hits.map(function (h) { return { id: h.id, title: h.title }; }), offer: text }); setBusy(false); }
            else if (AI) { setBusy(false); aiAnswer(text); }
            else { add({ k: 'b', t: S.none + ' ' + S.noneHint }); setBusy(false); }
        }).catch(function () { t.remove(); add({ k: 'b', t: S.error }); setBusy(false); });
    }
    function transcript() {
        const lines = [];
        hist.forEach(function (m) {
            if (m.k === 'u') lines.push(S.you + ': ' + m.t);
            else if (m.k === 'b') lines.push(S.bms + ': ' + m.t);
            else if (m.k === 'ai') lines.push(S.bms + ': ' + String(m.t).replace(/\*\*/g, ''));
            else if (m.k === 'list') lines.push(S.bms + ': ' + m.t + ' ' + (m.a || []).map(function (a) { return a.title; }).join('; '));
        });
        return lines.join('\n');
    }
    function open(v) {
        box.classList.toggle('d-none', !v);
        btn.setAttribute('aria-expanded', v ? 'true' : 'false');
        btn.querySelector('i').className = v ? 'bi bi-x-lg' : 'bi bi-chat-dots-fill';
        if (v) { start(); if (window.innerWidth >= 576) setTimeout(function () { q.focus(); }, 50); }
    }
    btn.addEventListener('click', function () { open(box.classList.contains('d-none')); });
    document.getElementById('bmsAskClose').addEventListener('click', function () { open(false); btn.focus(); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { open(false); btn.focus(); } });
    document.getElementById('bmsAskRestart').addEventListener('click', function () {
        hist = []; save(); log.replaceChildren(); add({ k: 'b', t: S.hello + ' ' + S.how });
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const text = q.value.trim().slice(0, 500);
        if (!text || busy) return;
        q.value = ''; ask(text);
    });
    document.getElementById('bmsAskPerson').addEventListener('click', function () {
        const firstQ = (hist.find(function (m) { return m.k === 'u'; }) || {}).t || '';
        const chat = transcript();
        open(false);
        if (window.bmsHelp && typeof window.bmsHelp.contact === 'function') {
            window.bmsHelp.contact({ subject: firstQ ? (S.subject + ' ' + firstQ).slice(0, 255) : '', body: chat ? (S.chat + '\n' + chat).slice(0, 4500) : '' });
        }
    });
})();
</script>
        <?php
    }
}
