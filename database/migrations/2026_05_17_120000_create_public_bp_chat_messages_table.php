<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_bp_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('building_plan_applications')->cascadeOnDelete();
            $table->string('role', 40);
            $table->text('message');
            $table->json('context_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_bp_chat_messages');
    }
};
