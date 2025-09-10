<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Spatie\PdfToText\Pdf;

class DocumentProcessingService implements DocumentProcessingServiceInterface
{
    public function process(PdfDocument $document): void
    {
        // Update document status to processing
        $document->update([
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        // Create processing step for initialization
        $document->updateProcessingStep('initialization', [
            'status' => 'processing',
            'total_items' => 1,
            'completed_items' => 0,
            'progress_percentage' => 0,
        ]);

        // Extract basic metadata first
        try {
            $metadata = $this->extractMetadata($document->file_path);
            $pageCount = $this->getPageCount($document->file_path);

            $document->update([
                'page_count' => $pageCount,
            ]);

            // Store extracted metadata in normalized structure
            foreach ($metadata as $key => $value) {
                $document->setMetadata($key, $value);
            }

            // Update processing step for metadata extraction
            $initStep = $document->getProcessingStep('initialization');
            if ($initStep) {
                $initStep->markAsCompleted();
            }

            $document->updateProcessingStep('metadata_extraction', [
                'status' => 'completed',
                'total_items' => 1,
                'completed_items' => 1,
                'progress_percentage' => 100,
            ]);

            // Dispatch the main processing job
            ProcessDocumentJob::dispatch($document);

            if (config('pdf-viewer.monitoring.log_processing')) {
                Log::info('PDF document processing started', [
                    'document_hash' => $document->hash,
                    'page_count' => $pageCount,
                ]);
            }

        } catch (\Exception $e) {
            $this->handleFailure($document->hash, $e->getMessage());
            throw $e;
        }
    }

    public function getPageCount(string $filePath): int
    {
        try {
            // Use a simple approach to count PDF pages
            $pdf = file_get_contents(storage_path('app/' . $filePath));
            $pageCount = preg_match_all("/\/Page\W/", $pdf);
            
            // Fallback method if the above doesn't work
            if ($pageCount === 0) {
                $pageCount = preg_match_all("/\/Count\s+(\d+)/", $pdf, $matches);
                if (!empty($matches[1])) {
                    $pageCount = max($matches[1]);
                }
            }

            return max(1, $pageCount); // Ensure at least 1 page
        } catch (\Exception $e) {
            Log::warning('Failed to get PDF page count', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return 1; // Default to 1 page if we can't determine
        }
    }

    public function extractMetadata(string $filePath): array
    {
        try {
            $fullPath = storage_path('app/' . $filePath);
            
            // Basic file metadata
            $metadata = [
                'file_size_readable' => $this->formatBytes(filesize($fullPath)),
                'extracted_at' => now()->toISOString(),
            ];

            // Try to extract PDF metadata using basic methods
            $content = file_get_contents($fullPath);
            
            // Extract title
            if (preg_match('/\/Title\s*\(([^)]+)\)/', $content, $matches)) {
                $metadata['pdf_title'] = trim($matches[1]);
            }

            // Extract author
            if (preg_match('/\/Author\s*\(([^)]+)\)/', $content, $matches)) {
                $metadata['pdf_author'] = trim($matches[1]);
            }

            // Extract subject
            if (preg_match('/\/Subject\s*\(([^)]+)\)/', $content, $matches)) {
                $metadata['pdf_subject'] = trim($matches[1]);
            }

            // Extract creation date
            if (preg_match('/\/CreationDate\s*\(D:(\d{4})(\d{2})(\d{2})/', $content, $matches)) {
                $metadata['pdf_creation_date'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            }

            return $metadata;
        } catch (\Exception $e) {
            Log::warning('Failed to extract PDF metadata', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return ['extracted_at' => now()->toISOString()];
        }
    }

    public function validatePdf(string $filePath): bool
    {
        try {
            $fullPath = storage_path('app/' . $filePath);
            
            if (!file_exists($fullPath)) {
                return false;
            }

            // Check file header for PDF signature
            $handle = fopen($fullPath, 'r');
            $header = fread($handle, 5);
            fclose($handle);

            return strpos($header, '%PDF') === 0;
        } catch (\Exception $e) {
            Log::error('PDF validation failed', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getProcessingStatus(string $documentHash): array
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document) {
            return ['status' => 'not_found'];
        }

        $completedPages = $document->completedPages()->count();
        $failedPages = $document->failedPages()->count();
        $totalPages = $document->page_count;

        return [
            'status' => $document->status,
            'progress_percentage' => $totalPages > 0 ? round(($completedPages / $totalPages) * 100, 2) : 0,
            'total_pages' => $totalPages,
            'completed_pages' => $completedPages,
            'failed_pages' => $failedPages,
            'processing_started_at' => $document->processing_started_at,
            'processing_completed_at' => $document->processing_completed_at,
            'processing_error' => $document->processing_error,
            'processing_steps' => $document->processingSteps()->get()->keyBy('step_name'),
            'is_searchable' => $document->is_searchable,
        ];
    }

    public function cancelProcessing(string $documentHash): bool
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document || $document->status !== 'processing') {
            return false;
        }

        $document->update([
            'status' => 'cancelled',
            'processing_error' => 'Processing cancelled by user',
        ]);

        // Update processing steps to cancelled
        $document->processingSteps()->update(['status' => 'failed']);

        // TODO: Cancel any running jobs for this document
        // This would require job tracking implementation

        return true;
    }

    public function retryProcessing(string $documentHash): bool
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document || !in_array($document->status, ['failed', 'cancelled'])) {
            return false;
        }

        // Reset document status and clear error
        $document->update([
            'status' => 'uploaded',
            'processing_error' => null,
            'processing_started_at' => null,
            'processing_completed_at' => null,
        ]);

        // Clear processing steps
        $document->processingSteps()->delete();

        // Reset all failed pages
        $document->pages()->where('status', 'failed')->update([
            'status' => 'pending',
            'processing_error' => null,
        ]);

        // Start processing again
        $this->process($document);

        return true;
    }

    public function markComplete(string $documentHash): void
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document) {
            return;
        }

        $document->update([
            'status' => 'completed',
            'processing_completed_at' => now(),
            'is_searchable' => true,
        ]);

        // Mark all processing steps as completed
        $document->processingSteps()->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Trigger cache warming
        app(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class)
            ->warmDocumentCache($documentHash);

        if (config('pdf-viewer.monitoring.log_processing')) {
            Log::info('PDF document processing completed', [
                'document_hash' => $documentHash,
                'processing_time' => $document->processing_completed_at
                    ->diffInSeconds($document->processing_started_at),
            ]);
        }
    }

    public function handleFailure(string $documentHash, string $error): void
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document) {
            return;
        }

        $document->update([
            'status' => 'failed',
            'processing_error' => $error,
        ]);

        // Mark processing steps as failed
        $document->processingSteps()
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => now(),
            ]);

        Log::error('PDF document processing failed', [
            'document_hash' => $documentHash,
            'error' => $error,
        ]);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}