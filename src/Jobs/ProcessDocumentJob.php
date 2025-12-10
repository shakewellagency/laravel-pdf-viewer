<?php

namespace Shakewellagency\LaravelPdfViewer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;
use Shakewellagency\LaravelPdfViewer\Services\PDFOutlineExtractor;
use Shakewellagency\LaravelPdfViewer\Services\PDFLinkExtractor;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $retryAfter;

    public function __construct(
        public PdfDocument $document
    ) {
        $this->onQueue(config('pdf-viewer.jobs.document_processing.queue', 'default'));

        // Vapor-aware timeout configuration
        if (config('pdf-viewer.vapor.enabled', false)) {
            // Respect Vapor Lambda timeout limits (max 15 minutes)
            $this->timeout = min(
                config('pdf-viewer.jobs.document_processing.timeout', 300),
                config('pdf-viewer.vapor.lambda_timeout', 900) - 30 // Leave 30 seconds buffer
            );
        } else {
            $this->timeout = config('pdf-viewer.jobs.document_processing.timeout', 300);
        }

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
                'processing_progress' => ['stage' => 'extracting_metadata', 'progress' => 50],
            ]);

            // Extract and store TOC/Outline (if enabled)
            if (config('pdf-viewer.extraction.outline_enabled', true)) {
                $this->extractAndStoreOutline();
            }

            // Extract and store Links (if enabled)
            if (config('pdf-viewer.extraction.links_enabled', true)) {
                $this->extractAndStoreLinks();
            }

            // Update progress
            $this->document->update([
                'processing_progress' => ['stage' => 'pages_dispatched', 'progress' => 60],
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

    /**
     * Extract and store document outline (TOC)
     */
    protected function extractAndStoreOutline(): void
    {
        try {
            Log::info('Extracting document outline', [
                'document_hash' => $this->document->hash,
            ]);

            $filePath = $this->getLocalFilePath();
            if (!$filePath) {
                Log::warning('Could not get local file path for outline extraction', [
                    'document_hash' => $this->document->hash,
                ]);
                return;
            }

            $extractor = new PDFOutlineExtractor();
            $outline = $extractor->extract($filePath);

            if (empty($outline)) {
                Log::info('No outline/TOC found in document', [
                    'document_hash' => $this->document->hash,
                ]);
                return;
            }

            // Store outline entries in database with transaction
            DB::transaction(function () use ($outline) {
                // Clear existing outline entries for this document
                PdfDocumentOutline::where('pdf_document_id', $this->document->id)->delete();

                // Store new outline entries
                $this->storeOutlineEntries($outline, null, 0);
            });

            $totalEntries = PdfDocumentOutline::where('pdf_document_id', $this->document->id)->count();

            Log::info('Document outline extracted and stored', [
                'document_hash' => $this->document->hash,
                'total_entries' => $totalEntries,
            ]);

            // Cleanup temp file if we downloaded from S3
            $this->cleanupTempFile($filePath);

        } catch (\Exception $e) {
            Log::warning('Failed to extract document outline', [
                'document_hash' => $this->document->hash,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the job - outline extraction is optional
        }
    }

    /**
     * Store outline entries recursively
     */
    protected function storeOutlineEntries(array $entries, ?string $parentId, int $orderStart): int
    {
        $orderIndex = $orderStart;

        foreach ($entries as $entry) {
            $outline = PdfDocumentOutline::create([
                'pdf_document_id' => $this->document->id,
                'parent_id' => $parentId,
                'title' => $entry['title'],
                'level' => $entry['level'],
                'destination_page' => $entry['destination_page'],
                'destination_type' => $entry['destination_page'] ? 'page' : 'named',
                'order_index' => $orderIndex,
            ]);

            $orderIndex++;

            // Process children recursively
            if (!empty($entry['children'])) {
                $orderIndex = $this->storeOutlineEntries($entry['children'], $outline->id, 0);
            }
        }

        return $orderIndex;
    }

    /**
     * Extract and store document links
     */
    protected function extractAndStoreLinks(): void
    {
        try {
            Log::info('Extracting document links', [
                'document_hash' => $this->document->hash,
            ]);

            $filePath = $this->getLocalFilePath();
            if (!$filePath) {
                Log::warning('Could not get local file path for link extraction', [
                    'document_hash' => $this->document->hash,
                ]);
                return;
            }

            $extractor = new PDFLinkExtractor();
            $allLinks = $extractor->extract($filePath);

            if (empty($allLinks)) {
                Log::info('No links found in document', [
                    'document_hash' => $this->document->hash,
                ]);
                return;
            }

            // Store links in database with transaction
            DB::transaction(function () use ($allLinks) {
                // Clear existing links for this document
                PdfDocumentLink::where('pdf_document_id', $this->document->id)->delete();

                // Store new links - batch insert for performance
                $linksToInsert = [];

                foreach ($allLinks as $pageNumber => $pageLinks) {
                    foreach ($pageLinks as $link) {
                        $linksToInsert[] = [
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'pdf_document_id' => $this->document->id,
                            'source_page' => $link['source_page'],
                            'type' => $link['type'],
                            'destination_page' => $link['destination_page'],
                            'destination_url' => $link['destination_url'],
                            'destination_type' => $link['type'] === 'internal' ? 'page' : 'external',
                            'source_rect_x' => $link['coordinates']['x'] ?? 0,
                            'source_rect_y' => $link['coordinates']['y'] ?? 0,
                            'source_rect_width' => $link['coordinates']['width'] ?? 0,
                            'source_rect_height' => $link['coordinates']['height'] ?? 0,
                            'coord_x_percent' => $link['normalized_coordinates']['x_percent'] ?? 0,
                            'coord_y_percent' => $link['normalized_coordinates']['y_percent'] ?? 0,
                            'coord_width_percent' => $link['normalized_coordinates']['width_percent'] ?? 0,
                            'coord_height_percent' => $link['normalized_coordinates']['height_percent'] ?? 0,
                            'created_at' => now(),
                        ];

                        // Insert in batches of 100 to manage memory
                        if (count($linksToInsert) >= 100) {
                            PdfDocumentLink::insert($linksToInsert);
                            $linksToInsert = [];
                        }
                    }
                }

                // Insert remaining links
                if (!empty($linksToInsert)) {
                    PdfDocumentLink::insert($linksToInsert);
                }
            });

            $totalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)->count();
            $internalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)
                ->where('type', 'internal')
                ->count();
            $externalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)
                ->where('type', 'external')
                ->count();

            Log::info('Document links extracted and stored', [
                'document_hash' => $this->document->hash,
                'total_links' => $totalLinks,
                'internal_links' => $internalLinks,
                'external_links' => $externalLinks,
                'pages_with_links' => count($allLinks),
            ]);

            // Cleanup temp file if we downloaded from S3
            $this->cleanupTempFile($filePath);

        } catch (\Exception $e) {
            Log::warning('Failed to extract document links', [
                'document_hash' => $this->document->hash,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the job - link extraction is optional
        }
    }

    /**
     * Get local file path for extraction (download from S3 if needed)
     */
    protected function getLocalFilePath(): ?string
    {
        $disk = Storage::disk(config('pdf-viewer.storage.disk', 's3'));
        $filePath = $this->document->file_path;

        // If using local disk, return the path directly
        if (config('pdf-viewer.storage.disk') === 'local') {
            $fullPath = $disk->path($filePath);
            if (file_exists($fullPath)) {
                return $fullPath;
            }
            return null;
        }

        // For S3/remote storage, download to temp file
        try {
            if (!$disk->exists($filePath)) {
                Log::warning('Document file not found in storage', [
                    'document_hash' => $this->document->hash,
                    'file_path' => $filePath,
                ]);
                return null;
            }

            $tempPath = sys_get_temp_dir() . '/pdf_extraction_' . $this->document->hash . '.pdf';

            // Download file to temp location
            $content = $disk->get($filePath);
            file_put_contents($tempPath, $content);

            return $tempPath;

        } catch (\Exception $e) {
            Log::warning('Failed to download document for extraction', [
                'document_hash' => $this->document->hash,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cleanup temporary file after extraction
     */
    protected function cleanupTempFile(string $filePath): void
    {
        // Only cleanup if it's a temp file we created
        if (str_contains($filePath, sys_get_temp_dir()) && file_exists($filePath)) {
            @unlink($filePath);
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
