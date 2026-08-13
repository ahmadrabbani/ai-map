<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_training_labels', function (Blueprint $table) {
            $table->json('floor_templates')->nullable()->after('floor_handles');
        });
    }

    public function down(): void
    {
        Schema::table('cad_training_labels', function (Blueprint $table) {
            $table->dropColumn('floor_templates');
        });
    }
};
