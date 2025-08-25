<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_audit_compliance_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_extraction_audit_id')->constrained('pdf_extraction_audits')->onDelete('cascade');
            $table->string('compliance_type', 50); // 'GDPR', 'HIPAA', 'SOX', 'PCI_DSS', etc
            $table->boolean('is_compliant')->default(true);
            $table->string('flag_reason')->nullable(); // reason for compliance flag
            $table->string('remediation_action')->nullable(); // action taken to address flag
            $table->timestamps();

            // Indexes for performance
            $table->index(['pdf_extraction_audit_id', 'compliance_type']);
            $table->index(['compliance_type', 'is_compliant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_audit_compliance_flags');
    }
};