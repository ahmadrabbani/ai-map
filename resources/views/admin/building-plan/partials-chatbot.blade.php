<div class="card">
    <div class="card-header">AI Chatbot Panel</div>
    <div class="card-body">
        <div style="max-height:280px; overflow:auto; border:1px solid #eee; border-radius:8px; padding:10px; margin-bottom:10px;">
            @forelse($application->chatMessages as $msg)
                <div class="mb-2">
                    <strong>{{ strtoupper($msg->role) }}:</strong>
                    <span>{{ $msg->message }}</span>
                </div>
            @empty
                <div class="text-muted">No chat messages yet.</div>
            @endforelse
        </div>
        <form method="POST" action="{{ route('admin.plan.bp.chat.store', $application) }}">
            @csrf
            <div class="input-group">
                <input type="text" class="form-control" name="message" placeholder="Ask about rules, setbacks, boundary, warnings..." required>
                <button class="btn btn-outline-primary" type="submit">Send</button>
            </div>
            <div class="form-text">Chat uses only map analysis output, rules JSON, layer schema, report data, and this application chat history.</div>
        </form>
    </div>
</div>
