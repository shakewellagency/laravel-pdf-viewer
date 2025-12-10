<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: NOT removing 'metadata' from pdf_documents and pdf_document_pages tables
        // as the codebase still uses these columns directly. The new normalized tables
        // (pdf_document_metadata, pdf_page_metadata) provide an additional storage option
        // but the legacy columns are kept for backwards compatibility.
        //
        // Also keeping 'processing_progress' as it's used for job progress tracking.

        // Remove JSON columns from pdf_extraction_audits table only
        // These have been fully normalized to separate tables:
        // - pdf_audit_pages
        // - pdf_audit_compliance_flags
        // - pdf_audit_settings
        // - pdf_audit_performance_metrics
        // - pdf_audit_warnings
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