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

        return PdfExtractionAudit::create([
            'pdf_document_id' => $document->id,
            'user_id' => auth()->id(),
            'operation_type' => $operationType,
            'pages_requested' => $pagesRequested,
            'extraction_reason' => $reason,
            'requester_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'compliance_flags' => $complianceFlags,
            'pdf_metadata' => [
                'original_title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'page_count' => $document->page_count,
                'document_hash' => $document->hash,
            ],
            'extraction_settings' => [
                'preserve_fonts' => config('pdf-viewer.page_extraction.preserve_fonts'),
                'resource_strategy' => config('pdf-viewer.page_extraction.resource_strategy'),
                'handle_encryption' => config('pdf-viewer.page_extraction.handle_encryption'),
                'strip_navigation' => config('pdf-viewer.page_extraction.strip_navigation'),
            ],
            'original_checksum' => $originalChecksum,
            'original_file_size' => $originalFileSize,
            'status' => 'initiated',
            'initiated_at' => now(),
        ]);
    }

    /**
     * Update audit with page completion
     */
    public function recordPageCompletion(PdfExtractionAudit $audit, int $pageNumber, array $extractionContext): void
    {
        $completed = $audit->pages_completed ?? [];
        $failed = $audit->pages_failed ?? [];
        
        if ($extractionContext['status'] === 'success') {
            $completed[] = $pageNumber;
            
            // Calculate and store page checksum
            if (config('pdf-viewer.page_extraction.enable_checksums', true) && isset($extractionContext['final_file_size'])) {
                $pageChecksums = $audit->page_checksums ?? [];
                $pageChecksums[$pageNumber] = [
                    'size' => $extractionContext['final_file_size'],
                    'extraction_method' => $extractionContext['method'] ?? 'fpdi',
                    'timestamp' => now()->toISOString(),
                ];
                
                $audit->update(['page_checksums' => $pageChecksums]);
            }
        } else {
            $failed[] = [
                'page_number' => $pageNumber,
                'error' => $extractionContext['error'] ?? 'Unknown error',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Update warnings
        $warnings = $audit->warnings ?? [];
        if (!empty($extractionContext['issues_detected'])) {
            $warnings = array_merge($warnings, $extractionContext['issues_detected']);
        }

        $audit->update([
            'pages_completed' => array_values(array_unique($completed)),
            'pages_failed' => $failed,
            'warnings' => array_values(array_unique($warnings)),
        ]);
    }

    /**
     * Complete audit trail
     */
    public function completeExtraction(PdfExtractionAudit $audit): void
    {
        $requestedCount = count($audit->pages_requested ?? []);
        $completedCount = count($audit->pages_completed ?? []);
        $failedCount = count($audit->pages_failed ?? []);
        
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
            'success_rate' => $audit->getSuccessRate(),
            'total_extracted_size' => $this->calculateTotalExtractedSize($audit),
        ];

        $audit->update([
            'status' => $status,
            'completed_at' => now(),
            'performance_metrics' => $performanceMetrics,
        ]);

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
                'compliance_flags' => $audit->compliance_flags,
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
            'performance_metrics' => [
                'failure_context' => $context,
                'duration_seconds' => $audit->getDurationInSeconds() ?? 0,
            ],
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
        $totalSize = 0;
        $pageChecksums = $audit->page_checksums ?? [];
        
        foreach ($pageChecksums as $pageData) {
            $totalSize += $pageData['size'] ?? 0;
        }
        
        return $totalSize;
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