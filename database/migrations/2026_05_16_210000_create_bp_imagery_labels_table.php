<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bp_imagery_labels')) {
            return;
        }

        Schema::create('bp_imagery_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->unique()->constrained('bp_applications')->cascadeOnDelete();
            $table->foreignId('labeled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 20); // open|built|mixed
            $table->string('label_source', 80)->default('ad_epermit_manual');
            $table->text('notes')->nullable();
            $table->timestamp('labeled_at')->nullable();
            $table->timestamps();

            $table->index(['label', 'labeled_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bp_imagery_labels')) {
            return;
        }

        Schema::dropIfExists('bp_imagery_labels');
    }
};
