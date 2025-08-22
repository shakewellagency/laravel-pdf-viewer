<?php

namespace Shakewellagency\LaravelPdfViewer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $retryAfter;

    public function __construct(
        public PdfDocument $document
    ) {
        $this->onQueue(config('pdf-viewer.jobs.document_processing.queue', 'pdf-processing'));
        $this->timeout = config('pdf-viewer.jobs.document_processing.timeout', 300);
        $this->tries = config('pdf-viewer.jobs.document_processing.tries', 3);
        $this->retryAfter = config('pdf-viewer.jobs.document_processing.retry_after', 60);
    }

    public function handle(
        DocumentProcessingServiceInterface $processingService,
        PageProcessingServiceInterface $pageService
    ): void {
        try {
            Log::info('Starting document processing', [
                'document_hash' => $this->document->hash,
                'document_id' => $this->document->id,
            ]);

            // Validate PDF file
            if (!$processingService->validatePdf($this->document->file_path)) {
                throw new \Exception('Invalid PDF file');
            }

            // Update progress
            $this->document->update([
                'processing_progress' => ['stage' => 'creating_pages', 'progress' => 20],
            ]);

            // Create page records for each page
            for ($pageNumber = 1; $pageNumber <= $this->document->page_count; $pageNumber++) {
                $pageService->createPage($this->document, $pageNumber);
            }

            // Update progress
            $this->document->update([
                'processing_progress' => ['stage' => 'dispatching_jobs', 'progress' => 30],
            ]);

            // Dispatch individual page processing jobs for maximum parallelization
            for ($pageNumber = 1; $pageNumber <= $this->document->page_count; $pageNumber++) {
                ExtractPageJob::dispatch($this->document, $pageNumber);
            }

            // Update progress
            $this->document->update([
                'processing_progress' => ['stage' => 'pages_dispatched', 'progress' => 50],
            ]);

            Log::info('Document processing jobs dispatched', [
                'document_hash' => $this->document->hash,
                'total_pages' => $this->document->page_count,
            ]);

        } catch (\Exception $e) {
            Log::error('Document processing failed', [
                'document_hash' => $this->document->hash,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $processingService->handleFailure($this->document->hash, $e->getMessage());
            
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDocumentJob failed permanently', [
            'document_hash' => $this->document->hash,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Mark document as failed
        $this->document->update([
            'status' => 'failed',
            'processing_error' => $exception->getMessage(),
            'processing_progress' => ['stage' => 'failed', 'progress' => 0],
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(60); // Stop retrying after 1 hour
    }
}