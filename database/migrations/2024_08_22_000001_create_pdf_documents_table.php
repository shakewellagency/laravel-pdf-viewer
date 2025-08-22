<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pdf_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('hash')->unique()->index(); // Hash-based identification for security
            $table->string('title');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('file_path');
            $table->unsignedInteger('page_count')->default(0);
            $table->enum('status', [
                'uploaded',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ])->default('uploaded');
            $table->json('metadata')->nullable(); // Store PDF metadata
            $table->json('processing_progress')->nullable(); // Store processing progress
            $table->text('processing_error')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->boolean('is_searchable')->default(false);
            $table->uuid('created_by')->nullable(); // User who uploaded the document
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['status']);
            $table->index(['is_searchable']);
            $table->index(['created_by']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_documents');
    }
};