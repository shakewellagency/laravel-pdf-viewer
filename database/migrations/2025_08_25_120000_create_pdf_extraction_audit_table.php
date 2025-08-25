<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_extraction_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_document_id')->constrained('pdf_documents')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            
            // Extraction Details
            $table->string('operation_type', 50)->default('page_extraction'); // page_extraction, full_download, etc
            $table->json('pages_requested')->nullable(); // [1,2,3] or null for full document
            $table->json('pages_completed')->nullable(); // Track successful extractions
            $table->json('pages_failed')->nullable(); // Track failures
            
            // Legal & Compliance
            $table->string('extraction_reason')->nullable(); // business justification
            $table->string('requester_ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('compliance_flags')->nullable(); // GDPR, HIPAA, etc compliance markers
            
            // Technical Context
            $table->json('pdf_metadata')->nullable(); // Original PDF characteristics
            $table->json('extraction_settings')->nullable(); // Settings used for extraction
            $table->json('performance_metrics')->nullable(); // Timing, resource usage
            $table->string('extraction_method', 50)->default('fpdi'); // fpdi, fallback, etc
            
            // File Integrity
            $table->string('original_checksum', 64)->nullable(); // SHA256 of original
            $table->json('page_checksums')->nullable(); // Individual page checksums
            $table->bigInteger('original_file_size')->nullable();
            $table->bigInteger('total_extracted_size')->nullable();
            
            // Status Tracking
            $table->enum('status', ['initiated', 'processing', 'completed', 'failed', 'partial'])->default('initiated');
            $table->text('failure_reason')->nullable();
            $table->json('warnings')->nullable(); // Non-fatal issues
            
            // Timestamps
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes for compliance queries
            $table->index(['user_id', 'operation_type', 'created_at']);
            $table->index(['pdf_document_id', 'status']);
            $table->index(['initiated_at', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_extraction_audits');
    }
};