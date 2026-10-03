<?php
/**
 * includes/field_reports/report_share.php — Customer Visits report: download as PDF,
 * share on WhatsApp, send by email. Used by the visits page and the print page.
 *
 * frRenderShareKit() prints (once) the email dialog, a toast and window.FrShare:
 *   FrShare.prepare(params)   build the PDF in the background (call when a menu opens)
 *   FrShare.download(params)  save the PDF
 *   FrShare.whatsapp(params)  phone: share sheet with the PDF (WhatsApp → choose a contact);
 *                             computer: PDF downloaded + WhatsApp opens to choose a contact
 *   FrShare.email(params)     dialog: type the address(es), send with the PDF attached
 *   FrShare.invalidate()      forget built PDFs (after the visits change)
 * params = { date_from, date_to, user_id, lang } — the same as the print page.
 *
 * The PDF is real text (jsPDF + AutoTable, loaded only when first needed): A4 landscape,
 * the print page's heading, summary and columns; headings repeat on every page, a row is
 * never split across pages, and column widths are measured so no word is cut.
 * Framework-free (the print page has no jQuery/Bootstrap).
 */
if (!function_exists('frRenderShareKit')) {
    function frRenderShareKit(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $L = [
            'preparing' => t('Preparing the report...'), 'failed' => t('The report could not be prepared. Check your internet and try again.'),
            'downloaded' => t('PDF downloaded.'), 'waFallback' => t('The PDF was downloaded. Attach it in the WhatsApp chat that opens.'),
            'ready' => t('The report is ready'), 'tapShare' => t('Tap to share it on WhatsApp.'), 'shareNow' => t('Share now'),
            'sending' => t('Sending...'), 'serverError' => t('Server error. Please try again.'), 'enterEmail' => t('Enter an email address.'),
            'attachHint' => t('(The PDF report is attached.)'),
        ];
        ?>
<div class="frs-backdrop" id="frsEmail" hidden>
    <div class="frs-dialog" role="dialog" aria-modal="true" aria-labelledby="frsEmailTitle">
        <div class="frs-head">
            <span id="frsEmailTitle">&#9993;&nbsp; <?= te('Send the report by email') ?></span>
            <button type="button" class="frs-x" data-frs-close aria-label="<?= te('Close') ?>">&times;</button>
        </div>
        <form id="frsEmailForm" novalidate autocomplete="off">
            <div class="frs-body">
                <label for="frsTo"><?= te('Recipient email') ?> <span class="frs-req">*</span></label>
                <input type="email" id="frsTo" multiple maxlength="500" placeholder="boss@example.com" list="frsRecent" inputmode="email">
                <datalist id="frsRecent"></datalist>
                <small><?= te('You can add up to 5 addresses, separated by commas.') ?></small>
                <label for="frsMsg"><?= te('Message') ?> <small>(<?= te('optional') ?>)</small></label>
                <textarea id="frsMsg" rows="3" maxlength="1000"></textarea>
                <div class="frs-attach"><span class="frs-pdf">PDF</span> <span id="frsFile">—</span></div>
                <div class="frs-err" id="frsErr" hidden></div>
            </div>
            <div class="frs-foot">
                <button type="button" class="frs-btn frs-sec" data-frs-close><?= te('Cancel') ?></button>
                <button type="submit" class="frs-btn frs-pri" id="frsSend">&#10148; <?= te('Send') ?></button>
            </div>
        </form>
    </div>
</div>
<div class="frs-backdrop" id="frsRetry" hidden>
    <div class="frs-dialog frs-small" role="dialog" aria-modal="true" aria-labelledby="frsRetryTitle">
        <div class="frs-head"><span id="frsRetryTitle"><?= te('The report is ready') ?></span><button type="button" class="frs-x" data-frs-close aria-label="<?= te('Close') ?>">&times;</button></div>
        <div class="frs-body"><p style="margin:0"><?= te('Tap to share it on WhatsApp.') ?></p></div>
        <div class="frs-foot"><button type="button" class="frs-btn frs-sec" data-frs-close><?= te('Cancel') ?></button><button type="button" class="frs-btn frs-wa" id="frsRetryBtn"><?= te('Share now') ?></button></div>
    </div>
</div>
<div class="frs-toast" id="frsToast" role="status" aria-live="polite" hidden></div>
<style>
.frs-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 12px; }
.frs-backdrop[hidden], .frs-toast[hidden] { display: none !important; }
.frs-dialog { background: #fff; border-radius: 10px; width: 100%; max-width: 460px; max-height: 100%; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.25); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #212529; }
.frs-dialog.frs-small { max-width: 340px; }
.frs-head { background: #0d6efd; color: #fff; padding: 12px 14px; font-weight: 600; font-size: 15px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.frs-x { background: none; border: 0; color: #fff; font-size: 24px; line-height: 1; cursor: pointer; padding: 0 4px; min-width: 0; }
.frs-dialog form { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; }
.frs-body { padding: 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
.frs-body label { font-weight: 600; margin-top: 4px; }
.frs-body small { color: #6c757d; font-size: 12px; }
.frs-body input, .frs-body textarea { width: 100%; box-sizing: border-box; border: 1px solid #ced4da; border-radius: 6px; padding: 8px 10px; font: inherit; }
.frs-body input:focus, .frs-body textarea:focus { outline: 0; border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13,110,253,.2); }
.frs-req { color: #dc3545; }
.frs-attach { margin-top: 6px; padding: 8px 10px; background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 6px; font-size: 13px; word-break: break-all; }
.frs-pdf { display: inline-block; background: #dc3545; color: #fff; font-size: 10px; font-weight: 700; border-radius: 3px; padding: 1px 5px; margin-right: 4px; vertical-align: 1px; }
.frs-err { color: #dc3545; font-size: 13px; }
.frs-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 10px 14px; border-top: 1px solid #dee2e6; }
.frs-btn { border: 1px solid transparent; border-radius: 6px; padding: 8px 16px; font-weight: 600; font-size: 14px; cursor: pointer; min-width: 0; }
.frs-btn:disabled { opacity: .65; cursor: default; }
.frs-pri { background: #0d6efd; color: #fff; }
.frs-sec { background: #6c757d; color: #fff; }
.frs-wa { background: #0d6efd; color: #fff; }
.frs-toast { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 2100; max-width: calc(100% - 32px); background: #212529; color: #fff; padding: 10px 16px; border-radius: 8px; font: 14px 'Segoe UI', Tahoma, sans-serif; box-shadow: 0 4px 14px rgba(0,0,0,.25); text-align: center; }
.frs-toast.frs-bad { background: #dc3545; }
@media (max-width: 575.98px) { .frs-backdrop { align-items: flex-end; padding: 0; } .frs-dialog { max-width: none; border-radius: 12px 12px 0 0; } .frs-foot .frs-btn { flex: 1 1 0; } }
@media print { .frs-backdrop, .frs-toast { display: none !important; } }
</style>
<script>
(function () {
    if (window.FrShare) return;
    const API = <?= json_encode(buildUrl('api/field_reports/')) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    const L = <?= json_encode($L) ?>;
    const LIBS = ['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'];
    const $ = id => document.getElementById(id);
    let libsP = null; const cache = {};

    function loadScript(src) {
        return new Promise((ok, bad) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = () => bad(new Error('load ' + src)); document.head.appendChild(s); });
    }
    function libs() {
        if (!libsP) libsP = (window.jspdf ? Promise.resolve() : loadScript(LIBS[0])).then(() => loadScript(LIBS[1])).catch(e => { libsP = null; throw e; });
        return libsP;
    }
    const keyOf = p => JSON.stringify([p.date_from || '', p.date_to || '', p.user_id || '', p.lang || '']);

    function toast(msg, bad) {
        const t = $('frsToast'); t.textContent = msg; t.classList.toggle('frs-bad', !!bad); t.hidden = false;
        clearTimeout(toast.h); toast.h = setTimeout(() => { t.hidden = true; }, bad ? 6000 : 3500);
    }

    // Logo → PNG data URL (any format the browser shows), or null.
    function logoData(url) {
        if (!url) return Promise.resolve(null);
        return new Promise(res => {
            const img = new Image(), done = v => { clearTimeout(h); res(v); };
            const h = setTimeout(() => done(null), 5000);
            img.onload = () => { try { const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight;
                c.getContext('2d').drawImage(img, 0, 0); done({ src: c.toDataURL('image/png'), w: img.naturalWidth, h: img.naturalHeight }); } catch (e) { done(null); } };
            img.onerror = () => done(null);
            img.src = url;
        });
    }

    // The built-in PDF fonts know Latin-1 only.
    function clean(s, doneWord) {
        return String(s == null ? '' : s)
            .replace(/ ?\(GPS ✓\)/g, ' (GPS)').replace(/ ✓$/g, ' (' + doneWord + ')').replace(/✓/g, '')
            .replace(/[–—]/g, '-').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/…/g, '...')
            .replace(/[^\x00-\xFF]/g, '');
    }

    function buildPdf(d, logo) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true });
        const W = 297, H = 210, M = 10, IW = W - 2 * M;
        const blue = [13, 110, 253], box = [52, 152, 219], ink = [26, 37, 47];
        const c = s => clean(s, d.labels.done);

        // ── Heading: company (left), title box (right) ──
        const boxW = 88, boxX = W - M - boxW, leftW = IW - boxW - 6;
        doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(...blue);
        const nameLines = doc.splitTextToSize(c(d.company.name).toUpperCase(), leftW);
        doc.text(nameLines, M, M + 5);
        let y = M + 5 + (nameLines.length - 1) * 5.5 + 3;
        let infoX = M;
        if (logo) { const lh = 13, lw = Math.min(30, lh * logo.w / Math.max(1, logo.h)); doc.addImage(logo.src, 'PNG', M, y, lw, lh); infoX = M + lw + 3; }
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...ink);
        const info = [];
        if (d.company.address) info.push(...doc.splitTextToSize(c(d.company.address), leftW - (infoX - M)));
        if (d.company.phone) info.push(c(d.labels.phone + ': ' + d.company.phone));
        if (d.company.email) info.push(c(d.labels.email + ': ' + d.company.email));
        let iy = y + 3;
        info.forEach(line => { doc.text(line, infoX, iy); iy += 3.8; });
        const leftBottom = Math.max(iy - 1, logo ? y + 13 : y);

        doc.setFontSize(8.5);
        const inner = boxW - 8;
        const tLines = (doc.setFont('helvetica', 'bold'), doc.setFontSize(11), doc.splitTextToSize(c(d.labels.title).toUpperCase(), inner));
        doc.setFontSize(8.5);
        const sLines = doc.splitTextToSize(c(d.labels.staff + ': ' + d.subject), inner);
        const dLines = doc.splitTextToSize(c(d.labels.date + ': ' + d.range), inner);
        const boxH = 6 + tLines.length * 4.6 + (sLines.length + dLines.length) * 3.9 + 2;
        doc.setFillColor(...box); doc.roundedRect(boxX, M, boxW, boxH, 2, 2, 'F');
        doc.setTextColor(255, 255, 255);
        let by = M + 6.5;
        doc.setFont('helvetica', 'bold'); doc.setFontSize(11);
        tLines.forEach(l => { doc.text(l, boxX + boxW - 4, by, { align: 'right' }); by += 4.6; });
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
        sLines.concat(dLines).forEach(l => { doc.text(l, boxX + boxW - 4, by, { align: 'right' }); by += 3.9; });

        y = Math.max(leftBottom, M + boxH) + 3;
        doc.setDrawColor(...box); doc.setLineWidth(0.8); doc.line(M, y, W - M, y);
        y += 4;

        // ── Submitted / not submitted ──
        if (d.status) {
            doc.setFontSize(8.5); doc.setFont('helvetica', 'normal');
            const sl = doc.splitTextToSize(c(d.status), IW - 6);
            const sh = sl.length * 3.8 + 3.2;
            doc.setFillColor(231, 240, 255); doc.setDrawColor(182, 204, 254); doc.setLineWidth(0.25);
            doc.roundedRect(M, y, IW, sh, 1.5, 1.5, 'FD');
            doc.setTextColor(...ink); doc.text(sl, M + 3, y + 4.2);
            y += sh + 3;
        }

        // ── Summary boxes ──
        const n = d.summary.length, gap = 2, bw = (IW - gap * (n - 1)) / n;
        doc.setFontSize(7);
        const lab = d.summary.map(s => doc.splitTextToSize(c(s[0]), bw - 3));
        const sbH = 8.5 + Math.max(...lab.map(l => l.length)) * 2.9 + 1.5;
        d.summary.forEach((s, i) => {
            const x = M + i * (bw + gap);
            doc.setFillColor(231, 240, 255); doc.setDrawColor(182, 204, 254); doc.setLineWidth(0.25);
            doc.roundedRect(x, y, bw, sbH, 1.5, 1.5, 'FD');
            doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.setTextColor(...blue);
            doc.text(String(s[1]), x + bw / 2, y + 6, { align: 'center' });
            doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(...ink);
            doc.text(lab[i], x + bw / 2, y + 9.8, { align: 'center' });
        });
        y += sbH + 4;

        // ── Table: widths measured so no word is ever cut ──
        const cols = d.columns, rows = d.rows.map(r => r.map(c));
        const head = cols.map(col => c(col.label));
        const center = ['sno', 'time', 'card', 'trial', 'training', 'joined'];
        const fixed = ['sno', 'date', 'time', 'phone', 'card', 'trial', 'training', 'interest', 'joined', 'follow_up'];
        const pad = 1.3;
        let fs = 8, widths = null;
        for (; fs >= 6; fs -= 0.5) {
            const mins = cols.map((col, i) => {
                let m = 0;
                doc.setFont('helvetica', 'bold'); doc.setFontSize(fs - 0.5);
                head[i].split(/\s+/).forEach(w => { m = Math.max(m, doc.getTextWidth(w)); });
                doc.setFont('helvetica', 'normal'); doc.setFontSize(fs);
                rows.forEach(r => String(r[i]).split(/\s+/).forEach(w => { m = Math.max(m, Math.min(45, doc.getTextWidth(w))); }));
                return m + 2 * pad + 0.6;
            });
            if (mins.reduce((a, b) => a + b, 0) > IW) continue;
            // Short columns (numbers, Yes/No, dates…) get just what their longest word needs;
            // the text columns share the rest by the print page's weights, never below their minimum.
            widths = new Array(cols.length).fill(null);
            let free = IW, open = [];
            cols.forEach((col, i) => {
                if (fixed.includes(col.key)) { widths[i] = mins[i] + 1.5; free -= widths[i]; } else open.push(i);
            });
            if (free < open.reduce((a, i) => a + mins[i], 0)) { widths = null; continue; }
            for (let pass = 0; pass < cols.length; pass++) {
                const wsum = open.reduce((a, i) => a + cols[i].weight, 0);
                const short = open.filter(i => free * cols[i].weight / wsum < mins[i]);
                if (!short.length) { open.forEach(i => { widths[i] = free * cols[i].weight / wsum; }); break; }
                short.forEach(i => { widths[i] = mins[i]; free -= mins[i]; });
                open = open.filter(i => !short.includes(i));
                if (!open.length) break;
            }
            break;
        }
        if (!widths) { fs = 6; const ws = cols.reduce((a, col) => a + col.weight, 0); widths = cols.map(col => IW * col.weight / ws); }
        const columnStyles = {};
        cols.forEach((col, i) => { columnStyles[i] = { cellWidth: widths[i], halign: center.includes(col.key) ? 'center' : 'left' }; });
        const body = rows.length ? rows : [[{ content: c(d.labels.empty), colSpan: cols.length, styles: { halign: 'center', textColor: [108, 117, 125] } }]];

        doc.autoTable({
            startY: y, head: [head], body, theme: 'plain', columnStyles,
            margin: { left: M, right: M, top: M, bottom: 17 },
            styles: { font: 'helvetica', fontSize: fs, cellPadding: pad, textColor: ink, overflow: 'linebreak', valign: 'top',
                      lineColor: [228, 232, 236], lineWidth: { bottom: 0.2 } },
            headStyles: { fillColor: [52, 73, 94], textColor: 255, fontStyle: 'bold', fontSize: fs - 0.5, valign: 'bottom', lineWidth: 0 },
            alternateRowStyles: { fillColor: [249, 250, 251] },
            showHead: 'everyPage', rowPageBreak: 'avoid',
        });

        // ── Footer on every page ──
        const pages = doc.getNumberOfPages();
        for (let p = 1; p <= pages; p++) {
            doc.setPage(p);
            doc.setDrawColor(222, 226, 230); doc.setLineWidth(0.2); doc.line(M, H - 13, W - M, H - 13);
            doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(73, 80, 87);
            doc.text(doc.splitTextToSize(c(d.footer), IW - 40), W / 2, H - 9.5, { align: 'center' });
            doc.setFontSize(6.5); doc.setTextColor(...blue);
            doc.text(c(d.brand), W / 2, H - 6, { align: 'center' });
            doc.setFontSize(7); doc.setTextColor(73, 80, 87);
            doc.text(c(d.labels.page + ' ' + p + ' ' + d.labels.of + ' ' + pages), W - M, H - 6, { align: 'right' });
        }
        return doc.output('blob');
    }

    // Build once per (dates, staff, language); the promise is cached so a menu tap can start it early.
    function prepare(p) {
        const k = keyOf(p);
        if (!cache[k]) {
            const qs = new URLSearchParams({ date_from: p.date_from || '', date_to: p.date_to || '', user_id: p.user_id || '', lang: p.lang || '' });
            cache[k] = { p: Promise.all([fetch(API + 'report_data.php?' + qs, { credentials: 'same-origin' }).then(r => r.json()), libs()])
                .then(([d]) => { if (!d || !d.success) throw new Error((d && d.message) || 'data'); return logoData(d.company.logo).then(logo => ({ d, blob: buildPdf(d, logo) })); })
                .then(r => { cache[k].r = r; return r; })
                .catch(e => { delete cache[k]; throw e; }) };
        }
        return cache[k].p;
    }

    function saveBlob(blob, name) {
        const url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = name; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }
    function busy(btn, on) {
        if (!btn) return;
        if (on) { btn.dataset.frsHtml = btn.innerHTML; btn.disabled = true; btn.textContent = L.preparing; }
        else if (btn.dataset.frsHtml !== undefined) { btn.innerHTML = btn.dataset.frsHtml; btn.disabled = false; delete btn.dataset.frsHtml; }
    }
    function fail(e) { console.error('FrShare', e); toast(L.failed, true); }

    function download(p, btn) {
        busy(btn, true);
        return prepare(p).then(r => { saveBlob(r.blob, r.d.filename); toast(L.downloaded); }).catch(fail).finally(() => busy(btn, false));
    }

    function shareFile(r) {
        const file = new File([r.blob], r.d.filename, { type: 'application/pdf' });
        return navigator.share({ files: [file], text: r.d.share_text });
    }
    function canShareFiles(r) {
        try { return !!(navigator.canShare && navigator.share && navigator.canShare({ files: [new File([r.blob], r.d.filename, { type: 'application/pdf' })] })); } catch (e) { return false; }
    }
    function whatsapp(p, btn) {
        busy(btn, true);
        return prepare(p).then(r => {
            busy(btn, false);
            if (canShareFiles(r)) {
                // Phone: the share sheet carries the PDF itself — pick WhatsApp, then the contact.
                return shareFile(r).catch(e => {
                    if (e && e.name === 'AbortError') return;          // the user closed the sheet
                    if (e && e.name === 'NotAllowedError') {          // the tap "expired" while the PDF was built
                        const dlg = $('frsRetry'); dlg.hidden = false;
                        $('frsRetryBtn').onclick = () => { dlg.hidden = true; shareFile(r).catch(x => { if (!x || x.name !== 'AbortError') fail(x); }); };
                        return;
                    }
                    throw e;
                });
            }
            // Computer: PDF downloaded, WhatsApp opens on "choose a contact" with the summary.
            saveBlob(r.blob, r.d.filename);
            window.open('https://wa.me/?text=' + encodeURIComponent(r.d.share_text + '\n\n' + L.attachHint), '_blank', 'noopener');
            toast(L.waFallback);
        }).catch(fail).finally(() => busy(btn, false));
    }

    // ── Email dialog ──
    const RECENT = 'frs_recent_emails';
    function recent() { try { return JSON.parse(localStorage.getItem(RECENT) || '[]').filter(x => typeof x === 'string').slice(0, 8); } catch (e) { return []; } }
    function remember(list) { try { localStorage.setItem(RECENT, JSON.stringify(Array.from(new Set(list.concat(recent()))).slice(0, 8))); } catch (e) { /* private mode */ } }
    let emailParams = null;
    function email(p) {
        emailParams = p;
        const r = recent();
        $('frsRecent').innerHTML = r.map(a => '<option value="' + a.replace(/[&"<>]/g, '') + '"></option>').join('');
        $('frsTo').value = r[0] || ''; $('frsMsg').value = ''; $('frsErr').hidden = true;
        $('frsFile').textContent = L.preparing;
        $('frsEmail').hidden = false;
        setTimeout(() => $('frsTo').focus(), 50);
        prepare(p).then(x => { $('frsFile').textContent = x.d.filename; }).catch(() => { $('frsFile').textContent = '—'; });
    }
    function showErr(m) { $('frsErr').textContent = m; $('frsErr').hidden = false; }
    function blobToBase64(blob) {
        return new Promise((ok, bad) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1] || ''); fr.onerror = bad; fr.readAsDataURL(blob); });
    }
    document.addEventListener('submit', function (e) {
        if (e.target.id !== 'frsEmailForm') return;
        e.preventDefault();
        const to = $('frsTo').value.trim(), btn = $('frsSend');
        if (!to) { showErr(L.enterEmail); $('frsTo').focus(); return; }
        $('frsErr').hidden = true;
        const html = btn.innerHTML; btn.disabled = true; btn.textContent = L.sending;
        prepare(emailParams).then(r => blobToBase64(r.blob)).then(b64 => {
            const fd = new FormData();
            Object.entries({ _csrf: CSRF, to, message: $('frsMsg').value, pdf: b64, date_from: emailParams.date_from || '', date_to: emailParams.date_to || '',
                             user_id: emailParams.user_id || '', lang: emailParams.lang || '' }).forEach(([k, v]) => fd.append(k, v));
            return fetch(API + 'email_report.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } })
                .then(res => res.json().catch(() => ({ success: false, message: L.serverError })));
        }).then(res => {
            if (res.success) { remember(to.split(/[\s,;]+/).filter(Boolean)); $('frsEmail').hidden = true; toast(res.message); }
            else showErr(res.message || L.serverError);
        }).catch(err => { console.error('FrShare email', err); showErr(L.failed); })
          .finally(() => { btn.disabled = false; btn.innerHTML = html; });
    });
    document.addEventListener('click', function (e) {
        const x = e.target.closest('[data-frs-close]');
        if (x) { x.closest('.frs-backdrop').hidden = true; return; }
        if (e.target.classList && e.target.classList.contains('frs-backdrop')) e.target.hidden = true;
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.frs-backdrop').forEach(b => { b.hidden = true; }); });

    window.FrShare = { prepare: p => prepare(p).catch(() => null), download, whatsapp, email, invalidate: () => { Object.keys(cache).forEach(k => delete cache[k]); } };
})();
</script>
        <?php
    }
}
