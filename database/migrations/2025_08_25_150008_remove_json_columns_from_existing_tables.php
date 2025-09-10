<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove JSON columns from pdf_documents table
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropColumn(['metadata', 'processing_progress']);
        });

        // Remove JSON column from pdf_document_pages table
        Schema::table('pdf_document_pages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        // Remove JSON columns from pdf_extraction_audits table
        Schema::table('pdf_extraction_audits', function (Blueprint $table) {
            $table->dropColumn([
                'pages_requested',
                'pages_completed', 
                'pages_failed',
                'compliance_flags',
                'pdf_metadata',
                'extraction_settings',
                'performance_metrics',
                'page_checksums',
                'warnings'
            ]);
        });
    }

    public function down(): void
    {
        // Restore JSON columns to pdf_documents table
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->json('metadata')->nullable();
            $table->json('processing_progress')->nullable();
        });

        // Restore JSON column to pdf_document_pages table
        Schema::table('pdf_document_pages', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });

        // Restore JSON columns to pdf_extraction_audits table
        Schema::table('pdf_extraction_audits', function (Blueprint $table) {
            $table->json('pages_requested')->nullable();
            $table->json('pages_completed')->nullable();
            $table->json('pages_failed')->nullable();
            $table->json('compliance_flags')->nullable();
            $table->json('pdf_metadata')->nullable();
            $table->json('extraction_settings')->nullable();
            $table->json('performance_metrics')->nullable();
            $table->json('page_checksums')->nullable();
            $table->json('warnings')->nullable();
        });
    }
};