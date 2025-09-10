<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_audit_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_extraction_audit_id')->constrained('pdf_extraction_audits')->onDelete('cascade');
            $table->string('setting_key', 100); // 'quality', 'compression', 'format', etc
            $table->text('setting_value')->nullable();
            $table->string('setting_type', 20)->default('string'); // string, integer, float, boolean
            $table->timestamps();

            // Indexes for performance
            $table->unique(['pdf_extraction_audit_id', 'setting_key']);
            $table->index(['setting_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_audit_settings');
    }
};