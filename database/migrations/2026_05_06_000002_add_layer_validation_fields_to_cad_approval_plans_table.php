<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_approval_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('cad_approval_plans', 'uploaded_extension')) {
                $table->string('uploaded_extension')->nullable()->after('original_file_path');
            }
            if (! Schema::hasColumn('cad_approval_plans', 'layer_validation_json')) {
                $table->json('layer_validation_json')->nullable()->after('analysis_result');
            }
            if (! Schema::hasColumn('cad_approval_plans', 'detected_layers_json')) {
                $table->json('detected_layers_json')->nullable()->after('layer_validation_json');
            }
            if (! Schema::hasColumn('cad_approval_plans', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 2)->nullable()->after('detected_layers_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_approval_plans', function (Blueprint $table) {
            $table->dropColumn([
                'uploaded_extension',
                'layer_validation_json',
                'detected_layers_json',
                'confidence_score',
            ]);
        });
    }
};
