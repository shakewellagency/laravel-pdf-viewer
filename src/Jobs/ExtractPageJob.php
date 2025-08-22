<?php

namespace Shakewellagency\LaravelPdfViewer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

class ExtractPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $retryAfter;

    public function __construct(
        public PdfDocument $document,
        public int $pageNumber
    ) {
        $this->onQueue(config('pdf-viewer.jobs.page_extraction.queue', 'default'));
        
        // Vapor-aware timeout configuration
        if (config('pdf-viewer.vapor.enabled', false)) {
            $this->timeout = min(
                config('pdf-viewer.jobs.page_extraction.timeout', 60),
                config('pdf-viewer.vapor.lambda_timeout', 900) - 30 // Leave 30 seconds buffer
            );
        } else {
            $this->timeout = config('pdf-viewer.jobs.page_extraction.timeout', 60);
        }
        
        $this->tries = config('pdf-viewer.jobs.page_extraction.tries', 2);
        $this->retryAfter = config('pdf-viewer.jobs.page_extraction.retry_after', 30);
    }

    public function handle(PageProcessingServiceInterface $pageService): void
    {
        try {
            $page = PdfDocumentPage::where('pdf_document_id', $this->document->id)
                ->where('page_number', $this->pageNumber)
                ->first();

            if (!$page) {
                throw new \Exception("Page {$this->pageNumber} not found for document {$this->document->hash}");
            }

            // Update page status to processing
            $page->update(['status' => 'processing']);

            Log::info('Starting page extraction', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->pageNumber,
                'page_id' => $page->id,
            ]);

            // Extract individual page from PDF
            $pageFilePath = $pageService->extractPage($this->document, $this->pageNumber);

            // Update page with file path
            $page->update(['page_file_path' => $pageFilePath]);

            // Generate thumbnail if enabled
            if (config('pdf-viewer.thumbnails.enabled', true)) {
                try {
                    $thumbnailPath = $pageService->generateThumbnail(
                        $pageFilePath,
                        config('pdf-viewer.thumbnails.width', 300),
                        config('pdf-viewer.thumbnails.height', 400)
                    );
                    
                    if ($thumbnailPath) {
                        $page->update(['thumbnail_path' => $thumbnailPath]);
                    }
                } catch (\Exception $e) {
                    // Thumbnail generation failure shouldn't fail the entire job
                    Log::warning('Thumbnail generation failed', [
                        'document_hash' => $this->document->hash,
                        'page_number' => $this->pageNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch text processing job for this page
            ProcessPageTextJob::dispatch($page);

            Log::info('Page extraction completed', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->pageNumber,
                'page_file_path' => $pageFilePath,
            ]);

        } catch (\Exception $e) {
            Log::error('Page extraction failed', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->pageNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update page status to failed
            if (isset($page)) {
                $pageService->handlePageFailure($page, $e->getMessage());
            }

            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractPageJob failed permanently', [
            'document_hash' => $this->document->hash,
            'page_number' => $this->pageNumber,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Find and mark page as failed
        $page = PdfDocumentPage::where('pdf_document_id', $this->document->id)
            ->where('page_number', $this->pageNumber)
            ->first();

        if ($page) {
            $page->update([
                'status' => 'failed',
                'processing_error' => $exception->getMessage(),
            ]);
        }

        // Check if all pages have completed (successfully or failed)
        $this->checkDocumentCompletion();
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30); // Stop retrying after 30 minutes
    }

    protected function checkDocumentCompletion(): void
    {
        $totalPages = $this->document->page_count;
        $completedPages = $this->document->pages()
            ->whereIn('status', ['completed', 'failed'])
            ->count();

        if ($completedPages >= $totalPages) {
            // All pages have been processed (successfully or failed)
            $failedPages = $this->document->failedPages()->count();
            
            if ($failedPages > 0) {
                Log::warning('Document processing completed with failures', [
                    'document_hash' => $this->document->hash,
                    'total_pages' => $totalPages,
                    'failed_pages' => $failedPages,
                ]);
            }

            // Update document status based on page results
            $status = $failedPages === $totalPages ? 'failed' : 'completed';
            
            $this->document->update([
                'status' => $status,
                'processing_completed_at' => now(),
                'processing_progress' => [
                    'stage' => $status,
                    'progress' => 100,
                    'completed_pages' => $completedPages,
                    'failed_pages' => $failedPages,
                ],
                'is_searchable' => $status === 'completed',
            ]);

            if ($status === 'completed') {
                // Trigger cache warming
                app(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class)
                    ->warmDocumentCache($this->document->hash);
            }
        }
    }
}