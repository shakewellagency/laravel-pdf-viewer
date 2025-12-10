<?php

namespace Shakewellagency\LaravelPdfViewer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;
use Shakewellagency\LaravelPdfViewer\Services\PDFOutlineExtractor;
use Shakewellagency\LaravelPdfViewer\Services\PDFLinkExtractor;

class BackfillDocumentMetadataCommand extends Command
{
    protected $signature = 'pdf-viewer:backfill-metadata
                           {--document-hash= : Process a specific document by hash}
                           {--all : Process all documents missing metadata}
                           {--batch-size=50 : Number of documents to process per batch}
                           {--queue : Use queue-based processing instead of synchronous}
                           {--dry-run : Preview what would be processed without making changes}
                           {--force : Skip confirmation prompt}
                           {--outline-only : Only backfill outline/TOC data}
                           {--links-only : Only backfill link data}
                           {--status=completed : Filter by document status (default: completed)}';

    protected $description = 'Backfill TOC (outline) and links metadata for existing PDF documents';

    protected int $processedCount = 0;
    protected int $successCount = 0;
    protected int $failedCount = 0;
    protected int $skippedCount = 0;
    protected array $failures = [];

    public function handle(): int
    {
        $this->info('');
        $this->info('===================================================');
        $this->info('   PDF Document Metadata Backfill Command');
        $this->info('===================================================');
        $this->info('');

        // Validate options
        if (!$this->option('document-hash') && !$this->option('all')) {
            $this->error('You must specify either --document-hash or --all');
            $this->line('');
            $this->line('Examples:');
            $this->line('  php artisan pdf-viewer:backfill-metadata --all --dry-run');
            $this->line('  php artisan pdf-viewer:backfill-metadata --all --batch-size=100');
            $this->line('  php artisan pdf-viewer:backfill-metadata --document-hash=abc123');
            return 1;
        }

        $isDryRun = $this->option('dry-run');
        $useQueue = $this->option('queue');
        $batchSize = (int) $this->option('batch-size');
        $documentHash = $this->option('document-hash');
        $outlineOnly = $this->option('outline-only');
        $linksOnly = $this->option('links-only');
        $status = $this->option('status');

        // Display configuration
        $this->displayConfiguration($isDryRun, $useQueue, $batchSize, $outlineOnly, $linksOnly, $status);

        // Get documents to process
        $query = $this->buildDocumentQuery($documentHash, $outlineOnly, $linksOnly, $status);
        $totalDocuments = $query->count();

        if ($totalDocuments === 0) {
            $this->info('No documents found that need metadata backfill.');
            return 0;
        }

        $this->info("Found {$totalDocuments} document(s) to process.");
        $this->line('');

        // Dry run - just show what would be processed
        if ($isDryRun) {
            return $this->performDryRun($query, $batchSize);
        }

        // Confirmation prompt
        if (!$this->option('force') && !$this->confirm("Proceed with backfilling metadata for {$totalDocuments} document(s)?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Process documents
        if ($useQueue) {
            return $this->processWithQueue($query, $batchSize);
        }

        return $this->processSynchronously($query, $batchSize, $outlineOnly, $linksOnly);
    }

    protected function displayConfiguration(bool $isDryRun, bool $useQueue, int $batchSize, bool $outlineOnly, bool $linksOnly, string $status): void
    {
        $this->info('Configuration:');
        $this->line("  Mode: " . ($isDryRun ? '<fg=yellow>DRY RUN</>' : '<fg=green>LIVE</>'));
        $this->line("  Processing: " . ($useQueue ? 'Queue-based (async)' : 'Synchronous'));
        $this->line("  Batch Size: {$batchSize}");
        $this->line("  Status Filter: {$status}");

        if ($outlineOnly) {
            $this->line("  Target: <fg=cyan>Outlines only</>");
        } elseif ($linksOnly) {
            $this->line("  Target: <fg=cyan>Links only</>");
        } else {
            $this->line("  Target: <fg=cyan>Outlines and Links</>");
        }

        $this->line('');
    }

    protected function buildDocumentQuery(?string $documentHash, bool $outlineOnly, bool $linksOnly, string $status)
    {
        $query = PdfDocument::query();

        // Filter by specific hash
        if ($documentHash) {
            return $query->where('hash', $documentHash);
        }

        // Filter by status
        $query->where('status', $status);

        // Filter documents missing metadata
        if ($outlineOnly) {
            // Only documents without outlines
            $query->whereDoesntHave('outlines');
        } elseif ($linksOnly) {
            // Only documents without links
            $query->whereDoesntHave('links');
        } else {
            // Documents missing either outlines or links
            $query->where(function ($q) {
                $q->whereDoesntHave('outlines')
                  ->orWhereDoesntHave('links');
            });
        }

        return $query->orderBy('created_at', 'asc');
    }

    protected function performDryRun($query, int $batchSize): int
    {
        $this->info('<fg=yellow>DRY RUN - No changes will be made</>');
        $this->line('');

        $documents = $query->take(100)->get(); // Limit preview to 100 for performance
        $total = $query->count();

        $this->table(
            ['Hash', 'Title', 'Pages', 'Has Outline', 'Has Links', 'Created'],
            $documents->map(function ($doc) {
                return [
                    substr($doc->hash, 0, 12) . '...',
                    substr($doc->title ?? 'Untitled', 0, 30),
                    $doc->page_count,
                    $doc->outlines()->exists() ? '<fg=green>Yes</>' : '<fg=red>No</>',
                    $doc->links()->exists() ? '<fg=green>Yes</>' : '<fg=red>No</>',
                    $doc->created_at->format('Y-m-d'),
                ];
            })->toArray()
        );

        if ($total > 100) {
            $this->line("... and " . ($total - 100) . " more documents");
        }

        $this->line('');
        $this->info("Total documents that would be processed: {$total}");
        $this->line('');
        $this->line('To execute, run the command without --dry-run');

        return 0;
    }

    protected function processWithQueue($query, int $batchSize): int
    {
        $this->info('Dispatching documents to queue...');
        $this->line('');

        $progressBar = $this->output->createProgressBar($query->count());
        $progressBar->start();

        $dispatchedCount = 0;

        $query->chunk($batchSize, function ($documents) use ($progressBar, &$dispatchedCount) {
            foreach ($documents as $document) {
                try {
                    ProcessDocumentJob::dispatch($document);
                    $dispatchedCount++;

                    Log::info('Dispatched document for metadata backfill', [
                        'document_hash' => $document->hash,
                        'document_id' => $document->id,
                    ]);
                } catch (\Exception $e) {
                    $this->failedCount++;
                    $this->failures[] = [
                        'hash' => $document->hash,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Failed to dispatch document for backfill', [
                        'document_hash' => $document->hash,
                        'error' => $e->getMessage(),
                    ]);
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->line('');
        $this->line('');

        $this->info("Dispatched {$dispatchedCount} document(s) to queue.");

        if ($this->failedCount > 0) {
            $this->warn("Failed to dispatch {$this->failedCount} document(s).");
        }

        $this->line('');
        $this->info('Monitor queue processing with: php artisan queue:work');

        return $this->failedCount > 0 ? 1 : 0;
    }

    protected function processSynchronously($query, int $batchSize, bool $outlineOnly, bool $linksOnly): int
    {
        $this->info('Processing documents synchronously...');
        $this->line('');

        $total = $query->count();
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $query->chunk($batchSize, function ($documents) use ($progressBar, $outlineOnly, $linksOnly) {
            foreach ($documents as $document) {
                $progressBar->setMessage("Processing: {$document->hash}");

                try {
                    $this->processDocument($document, $outlineOnly, $linksOnly);
                    $this->successCount++;
                } catch (\Exception $e) {
                    $this->failedCount++;
                    $this->failures[] = [
                        'hash' => $document->hash,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Failed to backfill document metadata', [
                        'document_hash' => $document->hash,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $this->processedCount++;
                $progressBar->advance();
            }
        });

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Display results
        $this->displayResults();

        return $this->failedCount > 0 ? 1 : 0;
    }

    protected function processDocument(PdfDocument $document, bool $outlineOnly, bool $linksOnly): void
    {
        Log::info('Starting metadata backfill for document', [
            'document_hash' => $document->hash,
            'document_id' => $document->id,
        ]);

        $filePath = $this->getLocalFilePath($document);

        if (!$filePath) {
            throw new \Exception('Could not access document file');
        }

        try {
            if (!$linksOnly) {
                $this->extractAndStoreOutline($document, $filePath);
            }

            if (!$outlineOnly) {
                $this->extractAndStoreLinks($document, $filePath);
            }

            Log::info('Completed metadata backfill for document', [
                'document_hash' => $document->hash,
            ]);
        } finally {
            $this->cleanupTempFile($filePath);
        }
    }

    protected function extractAndStoreOutline(PdfDocument $document, string $filePath): void
    {
        // Skip if already has outlines
        if ($document->outlines()->exists()) {
            $this->skippedCount++;
            return;
        }

        $extractor = new PDFOutlineExtractor();
        $outline = $extractor->extract($filePath);

        if (empty($outline)) {
            Log::info('No outline found in document', [
                'document_hash' => $document->hash,
            ]);
            return;
        }

        DB::transaction(function () use ($document, $outline) {
            // Clear any existing outline entries
            PdfDocumentOutline::where('pdf_document_id', $document->id)->delete();

            // Store new outline entries
            $this->storeOutlineEntries($document, $outline, null, 0);
        });

        $totalEntries = PdfDocumentOutline::where('pdf_document_id', $document->id)->count();

        Log::info('Extracted and stored document outline', [
            'document_hash' => $document->hash,
            'total_entries' => $totalEntries,
        ]);
    }

    protected function storeOutlineEntries(PdfDocument $document, array $entries, ?string $parentId, int $orderStart): int
    {
        $orderIndex = $orderStart;

        foreach ($entries as $entry) {
            $outline = PdfDocumentOutline::create([
                'pdf_document_id' => $document->id,
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
                $orderIndex = $this->storeOutlineEntries($document, $entry['children'], $outline->id, 0);
            }
        }

        return $orderIndex;
    }

    protected function extractAndStoreLinks(PdfDocument $document, string $filePath): void
    {
        // Skip if already has links
        if ($document->links()->exists()) {
            $this->skippedCount++;
            return;
        }

        $extractor = new PDFLinkExtractor();
        $allLinks = $extractor->extract($filePath);

        if (empty($allLinks)) {
            Log::info('No links found in document', [
                'document_hash' => $document->hash,
            ]);
            return;
        }

        DB::transaction(function () use ($document, $allLinks) {
            // Clear any existing links
            PdfDocumentLink::where('pdf_document_id', $document->id)->delete();

            // Store new links - batch insert for performance
            $linksToInsert = [];
            $batchSize = config('pdf-viewer.extraction.link_batch_size', 100);

            foreach ($allLinks as $pageNumber => $pageLinks) {
                foreach ($pageLinks as $link) {
                    $linksToInsert[] = [
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'pdf_document_id' => $document->id,
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

                    // Insert in batches
                    if (count($linksToInsert) >= $batchSize) {
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

        $totalLinks = PdfDocumentLink::where('pdf_document_id', $document->id)->count();

        Log::info('Extracted and stored document links', [
            'document_hash' => $document->hash,
            'total_links' => $totalLinks,
        ]);
    }

    protected function getLocalFilePath(PdfDocument $document): ?string
    {
        $disk = Storage::disk(config('pdf-viewer.storage.disk', 's3'));
        $filePath = $document->file_path;

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
                    'document_hash' => $document->hash,
                    'file_path' => $filePath,
                ]);
                return null;
            }

            $tempPath = sys_get_temp_dir() . '/pdf_backfill_' . $document->hash . '.pdf';

            // Download file to temp location
            $content = $disk->get($filePath);
            file_put_contents($tempPath, $content);

            return $tempPath;

        } catch (\Exception $e) {
            Log::warning('Failed to download document for backfill', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function cleanupTempFile(string $filePath): void
    {
        // Only cleanup if it's a temp file we created
        if (str_contains($filePath, sys_get_temp_dir()) && file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    protected function displayResults(): void
    {
        $this->info('===================================================');
        $this->info('                 Backfill Results');
        $this->info('===================================================');
        $this->line('');
        $this->line("  Total Processed: {$this->processedCount}");
        $this->line("  <fg=green>Successful: {$this->successCount}</>");
        $this->line("  <fg=red>Failed: {$this->failedCount}</>");
        $this->line("  <fg=yellow>Skipped (already had data): {$this->skippedCount}</>");
        $this->line('');

        if (!empty($this->failures)) {
            $this->warn('Failed Documents:');
            $this->table(
                ['Hash', 'Error'],
                array_map(function ($failure) {
                    return [
                        substr($failure['hash'], 0, 20) . '...',
                        substr($failure['error'], 0, 60),
                    ];
                }, array_slice($this->failures, 0, 10))
            );

            if (count($this->failures) > 10) {
                $this->line('... and ' . (count($this->failures) - 10) . ' more failures. Check logs for details.');
            }
        }

        $this->line('');
        $this->info('Backfill complete. Check logs for detailed information.');
    }
}
