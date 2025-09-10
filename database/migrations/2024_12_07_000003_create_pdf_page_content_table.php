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
        Schema::create('pdf_page_content', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('page_id'); // Foreign key to pdf_document_pages
            $table->longText('content'); // Extracted text content
            $table->string('content_hash', 64)->nullable(); // SHA-256 hash for deduplication
            $table->unsignedInteger('content_length')->default(0); // Character count
            $table->unsignedInteger('word_count')->default(0); // Word count
            $table->timestamp('extracted_at'); // When content was extracted
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraint
            $table->foreign('page_id')->references('id')->on('pdf_document_pages')->onDelete('cascade');

            // Unique constraint to prevent duplicate content per page
            $table->unique('page_id');

            // Indexes for performance
            $table->index(['content_length']);
            $table->index(['word_count']);
            $table->index(['extracted_at']);
            $table->index(['content_hash']); // For deduplication queries

            // Full-text search index - highly optimized for search-only operations (MySQL only)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['content'], 'pdf_content_fulltext');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_page_content');
    }
};