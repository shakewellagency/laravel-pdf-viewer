<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_page_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_document_page_id')->constrained('pdf_document_pages')->onDelete('cascade');
            $table->string('key', 100); // metadata key like 'width', 'height', 'rotation', 'dpi'
            $table->text('value')->nullable(); // metadata value
            $table->string('type', 20)->default('string'); // string, integer, float, boolean
            $table->timestamps();

            // Indexes for performance
            $table->unique(['pdf_document_page_id', 'key']);
            $table->index(['key']);
            $table->index(['pdf_document_page_id', 'key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_page_metadata');
    }
};