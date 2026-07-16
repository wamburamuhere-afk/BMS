<?php
/**
 * app/includes/ai_generate.php — reusable "Generate with AI" widget.
 *
 * Usage on any page that has a text field:
 *   require_once __DIR__ . '/../../includes/ai_generate.php';   // adjust depth
 *   <textarea id="description" ...></textarea>
 *   <?= aiButton('description', 'invoice_description') ?>
 *
 * The button + the shared modal + JS render ONLY when the AI Assistant is
 * enabled and the current user has the 'ai_assistant' permission — otherwise
 * everything is silently absent and the host field works exactly as before.
 */

require_once __DIR__ . '/../../core/ai_service.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!function_exists('aiWidgetAvailable')) {
    function aiWidgetAvailable(): bool
    {
        return aiConfigured() && function_exists('canView') && canView('ai_assistant');
    }
}

if (!function_exists('aiButton')) {
    /**
     * Render a ✨ Generate button bound to a target field id. Returns '' when AI
     * is unavailable. Also injects the shared modal+JS once per page.
     */
    function aiButton(string $targetFieldId, string $fieldType = 'text', string $label = 'Generate with AI'): string
    {
        if (!aiWidgetAvailable()) return '';
        $html = '<button type="button" class="btn btn-sm btn-outline-primary ai-gen-btn" '
              . 'data-ai-target="' . htmlspecialchars($targetFieldId, ENT_QUOTES) . '" '
              . 'data-ai-fieldtype="' . htmlspecialchars($fieldType, ENT_QUOTES) . '" '
              . 'title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
              . '<i class="bi bi-stars"></i> AI</button>';
        $html .= aiGenerateModalOnce();
        return $html;
    }
}

if (!function_exists('aiGenerateModalOnce')) {
    function aiGenerateModalOnce(): string
    {
        static $done = false;
        if ($done) return '';
        $done = true;
        $url = buildUrl('api/ai/generate.php');
        ob_start(); ?>
<div class="modal fade" id="aiGenModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-no-autoclose="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-stars me-1"></i> Generate with AI</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="aiGenCloseX"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="aiGenTarget"><input type="hidden" id="aiGenFieldType">
        <div class="mb-3">
          <label class="form-label">What should it say?</label>
          <textarea id="aiGenInstruction" class="form-control" rows="3" placeholder="e.g. a polite note that payment is due in 14 days"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Tone</label>
          <select id="aiGenTone" class="form-select">
            <option value="professional">Professional</option>
            <option value="friendly">Friendly</option>
            <option value="concise">Concise</option>
            <option value="formal">Formal</option>
          </select>
        </div>
        <div id="aiGenResultWrap" class="mt-3 d-none">
          <label class="form-label">Suggestion <span class="text-muted small">(editable)</span></label>
          <textarea id="aiGenResult" class="form-control" rows="5"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="aiGenCancel">Cancel</button>
        <button type="button" class="btn btn-outline-primary" id="aiGenRun"><i class="bi bi-stars me-1"></i> Generate</button>
        <button type="button" class="btn btn-primary d-none" id="aiGenUse"><i class="bi bi-check-circle me-1"></i> Use this</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  if (window.__aiGenWired) return; window.__aiGenWired = true;
  const URL = '<?= $url ?>';
  document.addEventListener('click', function(e){
    const b = e.target.closest('.ai-gen-btn'); if(!b) return;
    document.getElementById('aiGenTarget').value = b.dataset.aiTarget || '';
    document.getElementById('aiGenFieldType').value = b.dataset.aiFieldtype || 'text';
    document.getElementById('aiGenInstruction').value = '';
    document.getElementById('aiGenResultWrap').classList.add('d-none');
    document.getElementById('aiGenUse').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('aiGenModal')).show();
  });
  // Summernote-managed elements aren't plain form fields (no .value) — read/
  // write them via the summernote('code', ...) API instead. Everything else
  // (plain <textarea>/<input> targets, already used on invoices/quotations/
  // expenses/etc.) is untouched.
  function isSummernoteTarget(el){ return !!(el && window.jQuery && jQuery(el).data('summernote')); }
  function readTargetText(el){
    if (isSummernoteTarget(el)) {
      return jQuery(el).summernote('code')
        .replace(/<\/p>\s*<p>/gi, '\n\n').replace(/<br\s*\/?>/gi, '\n')
        .replace(/<[^>]+>/g, '').trim();
    }
    return el ? (el.value || '') : '';
  }
  function writeTargetText(el, text){
    if (isSummernoteTarget(el)) {
      const html = '<p>' + String(text).split(/\n\s*\n/).map(p => p.replace(/\n/g, '<br>')).join('</p><p>') + '</p>';
      jQuery(el).summernote('code', html);
    } else if (el) {
      el.value = text;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  document.getElementById('aiGenRun').addEventListener('click', function(){
    const btn=this, orig=btn.innerHTML;
    const tgt=document.getElementById('aiGenTarget').value;
    const existingEl=document.getElementById(tgt);
    const closeX=document.getElementById('aiGenCloseX'), cancelBtn=document.getElementById('aiGenCancel');
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Generating…';
    // Belt-and-braces alongside the modal's own data-bs-backdrop="static"
    // data-bs-keyboard="false": block the explicit Cancel/X too, so there is
    // no way — accidental (backdrop click, Escape) or deliberate (Cancel/X) —
    // to dismiss the modal while a request is in flight. Without this, a
    // generated result can land in a modal the user already closed, which
    // looks exactly like "it generated, then closed, and I saw nothing."
    closeX.disabled=true; cancelBtn.disabled=true;
    $.ajax({ url:URL, type:'POST', dataType:'json', data:{
        _csrf: CSRF_TOKEN,
        instruction: document.getElementById('aiGenInstruction').value,
        field_type: document.getElementById('aiGenFieldType').value,
        tone: document.getElementById('aiGenTone').value,
        existing: readTargetText(existingEl)
      },
      success:r=>{
        if(r.success){
          const resultBox=document.getElementById('aiGenResult');
          const resultWrap=document.getElementById('aiGenResultWrap');
          resultBox.value=r.text;
          resultWrap.classList.remove('d-none');
          document.getElementById('aiGenUse').classList.remove('d-none');
          // Make the result impossible to miss — scroll it into view and
          // focus it, rather than relying on the user noticing it appeared
          // below the fold inside the modal body.
          resultWrap.scrollIntoView({ behavior:'smooth', block:'nearest' });
          resultBox.focus();
        } else { Swal.fire({icon:'error',title:'AI',text:r.message||'Could not generate.'}); }
      },
      error:()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}),
      complete:()=>{ btn.disabled=false; btn.innerHTML=orig; closeX.disabled=false; cancelBtn.disabled=false; }
    });
  });
  document.getElementById('aiGenUse').addEventListener('click', function(){
    const tgt=document.getElementById('aiGenTarget').value;
    const el=document.getElementById(tgt);
    writeTargetText(el, document.getElementById('aiGenResult').value);
    bootstrap.Modal.getInstance(document.getElementById('aiGenModal')).hide();
  });
})();
</script>
<?php
        return ob_get_clean();
    }
}
