<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_audit_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_extraction_audit_id')->constrained('pdf_extraction_audits')->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->enum('status', ['requested', 'processing', 'completed', 'failed'])->default('requested');
            $table->string('checksum', 64)->nullable(); // SHA256 of extracted page
            $table->bigInteger('file_size')->nullable(); // Size of extracted page file
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->unique(['pdf_extraction_audit_id', 'page_number']);
            $table->index(['pdf_extraction_audit_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_audit_pages');
    }
};