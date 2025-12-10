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
        Schema::create('pdf_document_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pdf_document_id');
            $table->uuid('source_page_id')->nullable(); // Reference to pdf_document_pages
            $table->unsignedInteger('source_page'); // Page number where the link appears

            // Source rectangle coordinates (in PDF points, decimal 10,2 as per spec)
            $table->decimal('source_rect_x', 10, 2)->default(0);
            $table->decimal('source_rect_y', 10, 2)->default(0);
            $table->decimal('source_rect_width', 10, 2)->default(0);
            $table->decimal('source_rect_height', 10, 2)->default(0);

            // Normalized coordinates (as percentages for responsive display)
            $table->decimal('coord_x_percent', 8, 4)->default(0);
            $table->decimal('coord_y_percent', 8, 4)->default(0);
            $table->decimal('coord_width_percent', 8, 4)->default(0);
            $table->decimal('coord_height_percent', 8, 4)->default(0);

            // Destination info
            $table->unsignedInteger('destination_page')->nullable(); // For internal links
            $table->enum('destination_type', ['page', 'named', 'external'])->default('page');
            $table->string('destination_name')->nullable(); // Named destination identifier
            $table->string('destination_url', 2048)->nullable(); // For external links (max 2048 chars)
            $table->string('link_text', 1000)->nullable(); // Link text content (max 1000 chars)

            $table->timestamp('created_at')->nullable();

            // Legacy type column for backwards compatibility
            $table->enum('type', ['internal', 'external', 'unknown'])->default('unknown');

            // Foreign key constraints
            $table->foreign('pdf_document_id')
                ->references('id')
                ->on('pdf_documents')
                ->onDelete('cascade');

            $table->foreign('source_page_id')
                ->references('id')
                ->on('pdf_document_pages')
                ->onDelete('cascade');

            // Indexes for performance (as per spec: idx_document, idx_source_page)
            $table->index(['pdf_document_id'], 'idx_document');
            $table->index(['source_page_id'], 'idx_source_page');
            $table->index(['pdf_document_id', 'source_page']);
            $table->index(['destination_type']);
            $table->index(['destination_page']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_document_links');
    }
};
