<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('application_documents', 'attachment_type')) {
                $table->string('attachment_type', 100)->nullable()->after('document_type');
            }
            if (!Schema::hasColumn('application_documents', 'original_name')) {
                $table->string('original_name')->nullable()->after('attachment_type');
            }
            if (!Schema::hasColumn('application_documents', 'size')) {
                $table->unsignedBigInteger('size')->nullable()->after('mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            foreach (['attachment_type', 'original_name', 'size'] as $col) {
                if (Schema::hasColumn('application_documents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
