<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_document_processing_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_document_id')->constrained('pdf_documents')->onDelete('cascade');
            $table->string('step_name', 50); // 'page_extraction', 'text_analysis', 'thumbnail_generation', etc
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'skipped'])->default('pending');
            $table->unsignedInteger('total_items')->default(0); // total pages or items to process
            $table->unsignedInteger('completed_items')->default(0); // completed pages or items
            $table->unsignedInteger('failed_items')->default(0); // failed pages or items
            $table->decimal('progress_percentage', 5, 2)->default(0.00); // 0.00 to 100.00
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->unique(['pdf_document_id', 'step_name']);
            $table->index(['pdf_document_id', 'status']);
            $table->index(['step_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_document_processing_steps');
    }
};