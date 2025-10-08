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
        // Check if table already exists (handles production case)
        if (!Schema::hasTable('pdf_document_metadata')) {
            Schema::create('pdf_document_metadata', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('pdf_document_id');
                $table->string('key', 100);
                $table->text('value')->nullable();
                $table->string('type', 20)->default('string');
                $table->timestamps();

                $table->foreign('pdf_document_id')
                    ->references('id')
                    ->on('pdf_documents')
                    ->onDelete('cascade');

                $table->index(['pdf_document_id', 'key']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_document_metadata');
    }
};
