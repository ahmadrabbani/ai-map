<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('public_bp_chat_messages', function (Blueprint $table) {
            $table->timestamp('read_by_applicant_at')->nullable()->after('sent_at');
            $table->index(['application_id', 'role', 'read_by_applicant_at'], 'public_bp_chat_app_unread_idx');
        });
    }

    public function down(): void
    {
        Schema::table('public_bp_chat_messages', function (Blueprint $table) {
            $table->dropIndex('public_bp_chat_app_unread_idx');
            $table->dropColumn('read_by_applicant_at');
        });
    }
};
