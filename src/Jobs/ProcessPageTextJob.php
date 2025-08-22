<?php

namespace Shakewellagency\LaravelPdfViewer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

class ProcessPageTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $retryAfter;

    public function __construct(
        public PdfDocumentPage $page
    ) {
        $this->onQueue(config('pdf-viewer.jobs.text_processing.queue', 'default'));
        $this->timeout = config('pdf-viewer.jobs.text_processing.timeout', 30);
        $this->tries = config('pdf-viewer.jobs.text_processing.tries', 2);
        $this->retryAfter = config('pdf-viewer.jobs.text_processing.retry_after', 15);
    }

    public function handle(
        PageProcessingServiceInterface $pageService,
        SearchServiceInterface $searchService,
        CacheServiceInterface $cacheService
    ): void {
        try {
            // Refresh page model to get latest data
            $this->page->refresh();

            if (!$this->page->page_file_path) {
                throw new \Exception("Page file path not found for page {$this->page->page_number}");
            }

            Log::info('Starting text processing', [
                'document_hash' => $this->page->document->hash,
                'page_number' => $this->page->page_number,
                'page_id' => $this->page->id,
            ]);

            // Extract text content from the page
            $textContent = $pageService->extractText($this->page->page_file_path);

            // Update page with extracted content
            $pageService->updatePageContent($this->page, $textContent);

            // Index the content for search
            if (!empty($textContent)) {
                $searchService->indexPage(
                    $this->page->document->hash,
                    $this->page->page_number,
                    $textContent
                );
            }

            // Cache the page content
            $pageData = [
                'content' => $textContent,
                'page_number' => $this->page->page_number,
                'word_count' => str_word_count(strip_tags($textContent)),
                'has_content' => !empty(trim($textContent)),
            ];

            $cacheService->cachePageContent(
                $this->page->document->hash,
                $this->page->page_number,
                $pageData
            );

            // Mark page as completed
            $pageService->markPageProcessed($this->page);

            Log::info('Text processing completed', [
                'document_hash' => $this->page->document->hash,
                'page_number' => $this->page->page_number,
                'content_length' => strlen($textContent),
                'word_count' => $pageData['word_count'],
            ]);

            // Check if all pages in the document are completed
            $this->checkDocumentCompletion();

        } catch (\Exception $e) {
            Log::error('Text processing failed', [
                'document_hash' => $this->page->document->hash,
                'page_number' => $this->page->page_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $pageService->handlePageFailure($this->page, $e->getMessage());
            
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPageTextJob failed permanently', [
            'document_hash' => $this->page->document->hash,
            'page_number' => $this->page->page_number,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Mark page as failed
        $this->page->update([
            'status' => 'failed',
            'processing_error' => $exception->getMessage(),
        ]);

        // Check document completion even with failures
        $this->checkDocumentCompletion();
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(20); // Stop retrying after 20 minutes
    }

    protected function checkDocumentCompletion(): void
    {
        $document = $this->page->document;
        $totalPages = $document->page_count;
        
        $completedPages = $document->pages()
            ->whereIn('status', ['completed', 'failed'])
            ->count();

        if ($completedPages >= $totalPages) {
            // All pages have been processed
            $successfulPages = $document->completedPages()->count();
            $failedPages = $document->failedPages()->count();

            Log::info('All pages processed for document', [
                'document_hash' => $document->hash,
                'total_pages' => $totalPages,
                'successful_pages' => $successfulPages,
                'failed_pages' => $failedPages,
            ]);

            // Determine final document status
            if ($successfulPages > 0) {
                // At least some pages were processed successfully
                $document->update([
                    'status' => 'completed',
                    'processing_completed_at' => now(),
                    'processing_progress' => [
                        'stage' => 'completed',
                        'progress' => 100,
                        'successful_pages' => $successfulPages,
                        'failed_pages' => $failedPages,
                    ],
                    'is_searchable' => true,
                ]);

                // Warm document cache
                try {
                    app(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class)
                        ->warmDocumentCache($document->hash);
                } catch (\Exception $e) {
                    Log::warning('Failed to warm cache for completed document', [
                        'document_hash' => $document->hash,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('Document processing completed successfully', [
                    'document_hash' => $document->hash,
                    'processing_time' => $document->processing_completed_at
                        ->diffInSeconds($document->processing_started_at),
                    'successful_pages' => $successfulPages,
                    'failed_pages' => $failedPages,
                ]);

            } else {
                // All pages failed
                $document->update([
                    'status' => 'failed',
                    'processing_error' => 'All page processing jobs failed',
                    'processing_progress' => [
                        'stage' => 'failed',
                        'progress' => 0,
                        'failed_pages' => $failedPages,
                    ],
                    'is_searchable' => false,
                ]);

                Log::error('Document processing failed completely', [
                    'document_hash' => $document->hash,
                    'failed_pages' => $failedPages,
                ]);
            }
        }
    }
}