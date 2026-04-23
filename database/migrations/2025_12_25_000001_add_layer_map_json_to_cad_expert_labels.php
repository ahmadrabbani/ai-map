<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cad_expert_labels', function (Blueprint $table) {
            if (!Schema::hasColumn('cad_expert_labels', 'layer_map_json')) {
                $table->json('layer_map_json')->nullable()->after('text_layer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_expert_labels', function (Blueprint $table) {
            if (Schema::hasColumn('cad_expert_labels', 'layer_map_json')) {
                $table->dropColumn('layer_map_json');
            }
        });
    }
};
