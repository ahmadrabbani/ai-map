<div class="card">
    <div class="card-header">Plan Assistant</div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Ask a simple question about the wizard or layer guidelines. This FAQ assistant uses the current configured answers and can later be replaced by an OpenClaw-based agent.
        </p>
        <div class="input-group mb-2">
            <input type="text" class="form-control" id="faq-question" placeholder="Example: What layer name should I use for the plot boundary?">
            <button class="btn btn-outline-primary" type="button" id="faq-ask-btn">Ask</button>
        </div>
        <div id="faq-answer" class="small text-muted">No question asked yet.</div>
    </div>
</div>

@push('footer_scripts_inline')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('faq-ask-btn');
    const input = document.getElementById('faq-question');
    const answer = document.getElementById('faq-answer');
    if (!button || !input || !answer) return;
    button.addEventListener('click', async function () {
        const question = input.value.trim();
        if (!question) {
            answer.textContent = 'Enter a question first.';
            return;
        }
        answer.textContent = 'Checking the guidance...';
        const response = await fetch(@json(route('admin.plan.approval-wizard.faq')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
                'Accept': 'application/json',
            },
            body: JSON.stringify({ question })
        });
        const data = await response.json();
        answer.textContent = data.answer || 'No answer found.';
    });
});
</script>
@endpush
