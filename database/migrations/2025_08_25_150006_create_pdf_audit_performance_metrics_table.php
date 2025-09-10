<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_audit_performance_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_extraction_audit_id')->constrained('pdf_extraction_audits')->onDelete('cascade');
            $table->string('metric_name', 100); // 'total_time', 'memory_peak', 'cpu_usage', etc
            $table->decimal('metric_value', 15, 4); // numeric value
            $table->string('metric_unit', 20); // 'seconds', 'bytes', 'percent', etc
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Indexes for performance
            $table->index(['pdf_extraction_audit_id', 'metric_name']);
            $table->index(['metric_name', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_audit_performance_metrics');
    }
};