<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_audit_warnings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_extraction_audit_id')->constrained('pdf_extraction_audits')->onDelete('cascade');
            $table->string('warning_type', 50); // 'quality_degradation', 'partial_failure', etc
            $table->string('warning_code', 20); // error code for categorization
            $table->text('warning_message');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->unsignedInteger('page_number')->nullable(); // specific page if applicable
            $table->boolean('resolved')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['pdf_extraction_audit_id', 'severity']);
            $table->index(['warning_type', 'resolved']);
            $table->index(['severity', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_audit_warnings');
    }
};