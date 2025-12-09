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
            $table->unsignedInteger('source_page'); // Page where the link appears
            $table->enum('type', ['internal', 'external', 'unknown'])->default('unknown');
            $table->unsignedInteger('destination_page')->nullable(); // For internal links
            $table->text('destination_url')->nullable(); // For external links

            // Absolute coordinates (in PDF points)
            $table->decimal('coord_x', 10, 4)->default(0);
            $table->decimal('coord_y', 10, 4)->default(0);
            $table->decimal('coord_width', 10, 4)->default(0);
            $table->decimal('coord_height', 10, 4)->default(0);

            // Normalized coordinates (as percentages for responsive display)
            $table->decimal('coord_x_percent', 8, 4)->default(0);
            $table->decimal('coord_y_percent', 8, 4)->default(0);
            $table->decimal('coord_width_percent', 8, 4)->default(0);
            $table->decimal('coord_height_percent', 8, 4)->default(0);

            $table->timestamps();

            // Foreign key constraint
            $table->foreign('pdf_document_id')
                ->references('id')
                ->on('pdf_documents')
                ->onDelete('cascade');

            // Indexes for performance
            $table->index(['pdf_document_id']);
            $table->index(['pdf_document_id', 'source_page']);
            $table->index(['type']);
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
