<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfExtractionAudit;

class ExtractionAuditService
{
    /**
     * Initiate audit trail for extraction operation
     */
    public function initiateExtraction(
        PdfDocument $document, 
        array $pagesRequested = null, 
        string $operationType = 'page_extraction',
        string $reason = null
    ): PdfExtractionAudit {
        
        $request = request();
        $complianceFlags = $this->parseComplianceFlags();
        
        // Calculate original file checksum for integrity
        $originalChecksum = null;
        $originalFileSize = null;
        
        if (config('pdf-viewer.page_extraction.enable_checksums', true)) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
                $fileContent = $disk->get($document->file_path);
                $originalChecksum = hash('sha256', $fileContent);
                $originalFileSize = strlen($fileContent);
            } catch (\Exception $e) {
                Log::warning('Failed to calculate original file checksum', [
                    'document_hash' => $document->hash,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $audit = PdfExtractionAudit::create([
            'pdf_document_id' => $document->id,
            'user_id' => auth()->id(),
            'operation_type' => $operationType,
            'extraction_reason' => $reason,
            'requester_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'original_checksum' => $originalChecksum,
            'original_file_size' => $originalFileSize,
            'status' => 'initiated',
            'initiated_at' => now(),
        ]);

        // Store pages requested in normalized structure
        if ($pagesRequested) {
            foreach ($pagesRequested as $pageNumber) {
                $audit->auditPages()->create([
                    'page_number' => $pageNumber,
                    'status' => 'requested',
                ]);
            }
        }

        // Store compliance flags in normalized structure
        foreach ($complianceFlags as $type => $isCompliant) {
            $audit->complianceFlags()->create([
                'compliance_type' => $type,
                'is_compliant' => $isCompliant,
            ]);
        }

        // Store PDF metadata in normalized structure
        $pdfMetadata = [
            'original_title' => $document->title,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'page_count' => $document->page_count,
            'document_hash' => $document->hash,
        ];

        foreach ($pdfMetadata as $key => $value) {
            $audit->settings()->create([
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'setting_type' => is_int($value) ? 'integer' : 'string',
            ]);
        }

        // Store extraction settings in normalized structure
        $extractionSettings = [
            'preserve_fonts' => config('pdf-viewer.page_extraction.preserve_fonts'),
            'resource_strategy' => config('pdf-viewer.page_extraction.resource_strategy'),
            'handle_encryption' => config('pdf-viewer.page_extraction.handle_encryption'),
            'strip_navigation' => config('pdf-viewer.page_extraction.strip_navigation'),
        ];
        
        foreach ($extractionSettings as $key => $value) {
            $audit->settings()->create([
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'setting_type' => is_bool($value) ? 'boolean' : 'string',
            ]);
        }

        return $audit;
    }

    /**
     * Update audit with page completion
     */
    public function recordPageCompletion(PdfExtractionAudit $audit, int $pageNumber, array $extractionContext): void
    {
        // Find the audit page record
        $auditPage = $audit->auditPages()->where('page_number', $pageNumber)->first();
        
        if ($extractionContext['status'] === 'success') {
            $auditPage?->markAsCompleted(
                $extractionContext['checksum'] ?? null,
                $extractionContext['final_file_size'] ?? null
            );
        } else {
            $auditPage?->markAsFailed($extractionContext['error'] ?? 'Unknown error');
        }

        // Record warnings
        if (!empty($extractionContext['issues_detected'])) {
            foreach ($extractionContext['issues_detected'] as $issue) {
                $audit->warnings()->create([
                    'warning_type' => 'extraction_issue',
                    'warning_code' => 'EXT001',
                    'warning_message' => $issue,
                    'page_number' => $pageNumber,
                    'severity' => 'medium',
                ]);
            }
        }
    }

    /**
     * Complete audit trail
     */
    public function completeExtraction(PdfExtractionAudit $audit): void
    {
        $requestedCount = $audit->auditPages()->count();
        $completedCount = $audit->auditPages()->where('status', 'completed')->count();
        $failedCount = $audit->auditPages()->where('status', 'failed')->count();
        
        // Determine final status
        $status = 'completed';
        if ($failedCount > 0) {
            $status = $completedCount > 0 ? 'partial' : 'failed';
        }
        
        // Calculate performance metrics
        $duration = $audit->getDurationInSeconds() ?? 0;
        $performanceMetrics = [
            'duration_seconds' => $duration,
            'pages_per_second' => $duration > 0 ? round($completedCount / $duration, 2) : 0,
            'success_rate' => $completedCount > 0 ? round(($completedCount / $requestedCount) * 100, 2) : 0,
            'total_extracted_size' => $this->calculateTotalExtractedSize($audit),
        ];

        $audit->update([
            'status' => $status,
            'completed_at' => now(),
            'total_extracted_size' => $performanceMetrics['total_extracted_size'],
        ]);

        // Store performance metrics in normalized structure
        foreach ($performanceMetrics as $metricName => $metricValue) {
            $audit->performanceMetrics()->create([
                'metric_name' => $metricName,
                'metric_value' => (float) $metricValue,
                'metric_unit' => $this->getMetricUnit($metricName),
                'recorded_at' => now(),
            ]);
        }

        // Get compliance flags for logging
        $complianceFlags = $audit->complianceFlags()->pluck('is_compliant', 'compliance_type')->toArray();

        // Log compliance event
        if (config('pdf-viewer.compliance.log_user_actions', true)) {
            Log::info('PDF extraction audit completed', [
                'audit_id' => $audit->id,
                'document_hash' => $audit->document->hash,
                'user_id' => $audit->user_id,
                'operation_type' => $audit->operation_type,
                'status' => $status,
                'pages_requested' => $requestedCount,
                'pages_completed' => $completedCount,
                'pages_failed' => $failedCount,
                'compliance_flags' => $complianceFlags,
                'duration_seconds' => $duration,
            ]);
        }
    }

    /**
     * Handle extraction failure
     */
    public function recordExtractionFailure(PdfExtractionAudit $audit, string $failureReason, array $context = []): void
    {
        $audit->update([
            'status' => 'failed',
            'failure_reason' => $failureReason,
            'completed_at' => now(),
        ]);

        // Record failure metrics in normalized structure
        $duration = $audit->getDurationInSeconds() ?? 0;
        $audit->performanceMetrics()->create([
            'metric_name' => 'duration_seconds',
            'metric_value' => $duration,
            'metric_unit' => 'seconds',
            'recorded_at' => now(),
        ]);

        // Record failure warning
        $audit->warnings()->create([
            'warning_type' => 'extraction_failure',
            'warning_code' => 'EXT_FAIL',
            'warning_message' => $failureReason,
            'severity' => 'critical',
        ]);

        Log::error('PDF extraction audit failed', [
            'audit_id' => $audit->id,
            'document_hash' => $audit->document->hash,
            'user_id' => $audit->user_id,
            'failure_reason' => $failureReason,
            'context' => $context,
        ]);
    }

    /**
     * Parse compliance flags from configuration
     */
    protected function parseComplianceFlags(): array
    {
        $flagsString = config('pdf-viewer.compliance.compliance_flags', '');
        return array_filter(array_map('trim', explode(',', $flagsString)));
    }

    /**
     * Calculate total size of extracted files
     */
    protected function calculateTotalExtractedSize(PdfExtractionAudit $audit): int
    {
        return $audit->auditPages()
            ->whereNotNull('file_size')
            ->sum('file_size');
    }

    /**
     * Get metric unit for performance metrics
     */
    protected function getMetricUnit(string $metricName): string
    {
        return match ($metricName) {
            'duration_seconds' => 'seconds',
            'pages_per_second' => 'pages/second',
            'success_rate' => 'percent',
            'total_extracted_size' => 'bytes',
            default => 'count',
        };
    }

    /**
     * Clean up audit records based on retention policy
     */
    public function cleanupAuditRecords(): int
    {
        $retentionDays = config('pdf-viewer.compliance.retention_policy', 2555);
        $cutoffDate = now()->subDays($retentionDays);
        
        $deletedCount = PdfExtractionAudit::where('created_at', '<', $cutoffDate)->delete();
        
        if ($deletedCount > 0) {
            Log::info('Audit records cleaned up', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays,
                'cutoff_date' => $cutoffDate->toISOString(),
            ]);
        }
        
        return $deletedCount;
    }

    /**
     * Get compliance report for document
     */
    public function getComplianceReport(string $documentHash): array
    {
        $document = PdfDocument::where('hash', $documentHash)->first();
        
        if (!$document) {
            throw new \Exception("Document not found: {$documentHash}");
        }

        $audits = PdfExtractionAudit::where('pdf_document_id', $document->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'document' => [
                'hash' => $document->hash,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'created_at' => $document->created_at->toISOString(),
            ],
            'audit_summary' => [
                'total_extractions' => $audits->count(),
                'successful_extractions' => $audits->where('status', 'completed')->count(),
                'failed_extractions' => $audits->where('status', 'failed')->count(),
                'partial_extractions' => $audits->where('status', 'partial')->count(),
            ],
            'extractions' => $audits->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'user_id' => $audit->user_id,
                    'operation_type' => $audit->operation_type,
                    'pages_requested' => $audit->pages_requested,
                    'status' => $audit->status,
                    'success_rate' => $audit->getSuccessRate(),
                    'duration_seconds' => $audit->getDurationInSeconds(),
                    'initiated_at' => $audit->initiated_at->toISOString(),
                    'completed_at' => $audit->completed_at?->toISOString(),
                    'compliance_flags' => $audit->compliance_flags,
                ];
            })->toArray(),
        ];
    }
}