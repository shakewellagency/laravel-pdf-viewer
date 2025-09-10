<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_document_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_document_id')->constrained('pdf_documents')->onDelete('cascade');
            $table->string('key', 100); // metadata key like 'title', 'author', 'creation_date'
            $table->text('value')->nullable(); // metadata value
            $table->string('type', 20)->default('string'); // string, integer, float, boolean, date
            $table->timestamps();

            // Indexes for performance
            $table->unique(['pdf_document_id', 'key']);
            $table->index(['key']);
            $table->index(['pdf_document_id', 'key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_document_metadata');
    }
};