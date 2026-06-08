<?php
/**
 * app/constant/communication/ai_assistant.php — "Ask BMS" chat (plan: ai_assistant.md, Phase 3).
 * Permission: ai_assistant. Answers come only from curated read-only insights.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/ai_service.php';

autoEnforcePermission('ai_assistant');
require_once __DIR__ . '/../../../header.php';

$ready = aiConfigured();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-stars text-primary me-2"></i>Ask BMS</h4>
            <p class="text-muted mb-0">Ask about your business in plain language — revenue, debtors, cash, stock and more.</p>
        </div>
        <?php if (isAdmin()): ?>
        <a href="<?= getUrl('ai_settings') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-gear me-1"></i> AI Settings</a>
        <?php endif; ?>
    </div>

    <?php if (!$ready): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>
        The AI Assistant isn't configured yet.
        <?= isAdmin() ? 'Go to <a href="' . getUrl('ai_settings') . '">AI Settings</a> to connect a provider.' : 'Ask an administrator to enable it.' ?>
    </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div id="aiChat" class="mb-3" style="min-height:280px; max-height:55vh; overflow-y:auto;">
                <div class="text-muted small mb-3">Try one of these:</div>
                <div class="d-flex flex-wrap gap-2 mb-2" id="aiSuggestions">
                    <?php foreach ([
                        'What was my revenue this month?',
                        'Who are my top 5 debtors?',
                        'What is my current cash position?',
                        'How much profit did I make last month?',
                        'Which products are low on stock?',
                        'Show my sales trend for the last 6 months',
                    ] as $sug): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary ai-suggest"><?= htmlspecialchars($sug) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <form id="aiAskForm" class="d-flex gap-2">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="text" id="aiQuestion" class="form-control" placeholder="Ask a question about your business…" autocomplete="off" maxlength="500">
                <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i></button>
            </form>
        </div>
    </div>

    <script>
    $(function(){
        const chat = document.getElementById('aiChat');
        function bubble(role, html){
            const wrap=document.createElement('div');
            wrap.className = 'd-flex mb-3 ' + (role==='user'?'justify-content-end':'justify-content-start');
            wrap.innerHTML = '<div class="p-2 px-3 rounded-3 '+(role==='user'?'bg-primary text-white':'bg-light border')+'" style="max-width:80%;">'+html+'</div>';
            chat.appendChild(wrap); chat.scrollTop = chat.scrollHeight; return wrap;
        }
        function ask(q){
            if(!q.trim()) return;
            bubble('user', $('<div>').text(q).html());
            const thinking = bubble('ai', '<span class="spinner-border spinner-border-sm me-1"></span>Thinking…');
            $('#aiQuestion').val('');
            $.ajax({ url:'<?= buildUrl('api/ai/ask.php') ?>', type:'POST', dataType:'json',
                data:{ _csrf: CSRF_TOKEN, question: q },
                success:r=>{
                    if(r.success){
                        let prov = (r.used && r.used.length) ? '<div class="mt-1"><span class="badge bg-secondary-subtle text-secondary border" style="font-size:.65rem;"><i class="bi bi-database"></i> '+r.used.join(', ')+'</span></div>' : '';
                        thinking.querySelector('div').innerHTML = $('<div>').text(r.answer).html().replace(/\n/g,'<br>') + prov;
                    } else {
                        thinking.querySelector('div').classList.add('text-danger');
                        thinking.querySelector('div').textContent = r.message || 'Could not answer.';
                    }
                },
                error:()=>{ thinking.querySelector('div').classList.add('text-danger'); thinking.querySelector('div').textContent='Server error.'; },
                complete:()=>{ chat.scrollTop = chat.scrollHeight; }
            });
        }
        $('#aiAskForm').on('submit', e=>{ e.preventDefault(); ask($('#aiQuestion').val()); });
        $(document).on('click', '.ai-suggest', function(){ ask($(this).text()); });
    });
    </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
