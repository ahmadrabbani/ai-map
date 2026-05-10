<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bp_applications', function (Blueprint $table) {
            $table->string('metadata_doc_name')->nullable()->after('uploaded_file_size');
            $table->string('metadata_doc_path')->nullable()->after('metadata_doc_name');
            $table->json('applicant_data_json')->nullable()->after('metadata_doc_path');
            $table->json('plot_data_json')->nullable()->after('applicant_data_json');
            $table->json('layer_table_json')->nullable()->after('plot_data_json');
        });
    }

    public function down(): void
    {
        Schema::table('bp_applications', function (Blueprint $table) {
            $table->dropColumn(['metadata_doc_name', 'metadata_doc_path', 'applicant_data_json', 'plot_data_json', 'layer_table_json']);
        });
    }
};
