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
        Schema::create('pdf_document_outlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pdf_document_id');
            $table->uuid('parent_id')->nullable(); // For hierarchical structure
            $table->string('title', 500); // Title with max 500 chars as per spec
            $table->tinyInteger('level')->unsigned()->default(0); // Nesting level (0 = top level)
            $table->unsignedInteger('destination_page')->nullable(); // Target page number
            $table->enum('destination_type', ['page', 'named'])->default('page'); // Destination type
            $table->string('destination_name')->nullable(); // Named destination identifier
            $table->unsignedInteger('order_index')->default(0); // Order within same level
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('pdf_document_id')
                ->references('id')
                ->on('pdf_documents')
                ->onDelete('cascade');

            $table->foreign('parent_id')
                ->references('id')
                ->on('pdf_document_outlines')
                ->onDelete('cascade');

            // Indexes for performance (as per spec: idx_document_level, idx_parent)
            $table->index(['pdf_document_id', 'level'], 'idx_document_level');
            $table->index(['parent_id'], 'idx_parent');
            $table->index(['pdf_document_id']);
            $table->index(['destination_page']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_document_outlines');
    }
};
