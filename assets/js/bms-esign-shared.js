/**
 * bms-esign-shared.js — pieces of the e-signature embedding pipeline shared
 * between the full Document Signing Wizard (select_document_add_esignature.php)
 * and the one-click "Save & Sign" path (create_document.php). Keeping these
 * here means both flows produce byte-for-byte the same Certificate of
 * Completion page and the same SHA-256 integrity check, instead of two
 * copies that could quietly drift apart.
 */

// SHA-256 of an ArrayBuffer -> lowercase hex. Returns null if Web Crypto is unavailable.
async function bmsSha256Hex(buffer) {
    if (!window.crypto || !crypto.subtle) return null;
    try {
        const digest = await crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest))
            .map(b => b.toString(16).padStart(2, '0')).join('');
    } catch (e) {
        return null;
    }
}

// Append a Certificate of Completion page to the signed PDF (pure pdf-lib).
async function bmsAppendCertificatePage(pdfLibDoc, cert) {
    const { StandardFonts, rgb } = PDFLib;
    const font     = await pdfLibDoc.embedFont(StandardFonts.Helvetica);
    const fontBold = await pdfLibDoc.embedFont(StandardFonts.HelveticaBold);

    const page = pdfLibDoc.addPage([595.28, 841.89]); // A4 portrait
    const W = 595.28, H = 841.89, M = 56;
    const ink   = rgb(0.13, 0.13, 0.13);
    const muted = rgb(0.42, 0.42, 0.42);
    const brand = rgb(0.05, 0.43, 0.99);

    let y = H - M;
    page.drawText('CERTIFICATE OF COMPLETION', { x: M, y, size: 18, font: fontBold, color: brand });
    y -= 20;
    page.drawText('Electronic Signature Record', { x: M, y, size: 10, font, color: muted });
    y -= 16;
    page.drawLine({ start: { x: M, y }, end: { x: W - M, y }, thickness: 1, color: brand });
    y -= 34;

    // Word-wrap helper — strips characters the standard PDF font cannot encode
    const wrap = (text, size, f, maxW) => {
        const safe  = String(text).replace(/[^\x20-\x7E\xA0-\xFF–—]/g, '?');
        const words = safe.split(/\s+/);
        const lines = [];
        let line = '';
        words.forEach(w => {
            const test = line ? line + ' ' + w : w;
            if (f.widthOfTextAtSize(test, size) > maxW && line) {
                lines.push(line); line = w;
            } else { line = test; }
        });
        if (line) lines.push(line);
        return lines;
    };

    const row = (label, value) => {
        page.drawText(label.toUpperCase(), { x: M, y, size: 8, font: fontBold, color: muted });
        y -= 14;
        wrap(value || '—', 11, font, W - 2 * M).forEach(ln => {
            page.drawText(ln, { x: M, y, size: 11, font, color: ink });
            y -= 15;
        });
        y -= 10;
    };

    row('Document', cert.documentName);
    row('Digitally signed by', cert.signerName + (cert.signerEmail ? '  <' + cert.signerEmail + '>' : ''));
    row('Date & time', cert.signedAt + '  (server-recorded, tamper-evident)');
    row('Signing reference', cert.signingReference);
    row('Original document fingerprint (SHA-256)',
        cert.originalHash || 'Recorded in the BMS signature register');
    row('Consent statement accepted', cert.consentText);

    y -= 6;
    page.drawLine({ start: { x: M, y }, end: { x: W - M, y }, thickness: 0.5, color: muted });
    y -= 18;
    wrap('This certificate page is part of the signed PDF. The document\'s integrity can be ' +
         'verified at any time inside BMS — any change to the file after signing will be detected.',
         9, font, W - 2 * M).forEach(ln => {
        page.drawText(ln, { x: M, y, size: 9, font, color: muted });
        y -= 13;
    });
}

// Draw the embedded signature image plus the "Digitally signed by ..." label
// block directly beneath it, in raw PDF point coordinates (bottom-left origin).
async function bmsDrawSignatureWithLabel(pdfLibDoc, pdfPage, embeddedSig, x, y, width, height, signerName, signingReference) {
    pdfPage.drawImage(embeddedSig, { x, y, width, height });

    const { StandardFonts, rgb } = PDFLib;
    const lblFont  = await pdfLibDoc.embedFont(StandardFonts.Helvetica);
    const lblBold  = await pdfLibDoc.embedFont(StandardFonts.HelveticaBold);
    const inkBlue  = rgb(0.05, 0.43, 0.99);
    const inkGray  = rgb(0.30, 0.30, 0.30);
    const now      = new Date();
    const dateFmt  = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    const timeFmt  = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const lSize    = 7;
    const lh       = 9;
    let   ly       = y - 4; // just below the signature image bottom edge

    pdfPage.drawText('Digitally signed by: ' + (signerName || 'BMS User'), {
        x, y: ly, size: lSize, font: lblBold, color: inkBlue,
    });
    pdfPage.drawText(dateFmt + '  ·  ' + timeFmt, {
        x, y: ly - lh, size: lSize - 0.5, font: lblFont, color: inkGray,
    });
    if (signingReference) {
        pdfPage.drawText('Ref: ' + signingReference, {
            x, y: ly - lh * 2, size: lSize - 0.5, font: lblFont, color: inkGray,
        });
    }
}
