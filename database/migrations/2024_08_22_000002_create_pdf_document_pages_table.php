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
        Schema::create('pdf_document_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pdf_document_id');
            $table->unsignedInteger('page_number');
            $table->longText('content')->nullable(); // Extracted text content
            $table->string('page_file_path')->nullable(); // Path to individual page file
            $table->string('thumbnail_path')->nullable(); // Path to thumbnail
            $table->json('metadata')->nullable(); // Page-specific metadata
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed'
            ])->default('pending');
            $table->text('processing_error')->nullable();
            $table->boolean('is_parsed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraint
            $table->foreign('pdf_document_id')->references('id')->on('pdf_documents')->onDelete('cascade');

            // Unique constraint to prevent duplicate pages
            $table->unique(['pdf_document_id', 'page_number']);

            // Indexes for performance
            $table->index(['pdf_document_id', 'page_number']);
            $table->index(['status']);
            $table->index(['is_parsed']);

            // Full-text search index for content
            $table->fullText(['content'], 'pdf_pages_content_fulltext');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_document_pages');
    }
};