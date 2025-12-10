<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessPageTextJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

beforeEach(function () {
    // Mock services
    $this->pageService = Mockery::mock(PageProcessingServiceInterface::class);
    $this->searchService = Mockery::mock(SearchServiceInterface::class);
    $this->cacheService = Mockery::mock(CacheServiceInterface::class);

    $this->app->instance(PageProcessingServiceInterface::class, $this->pageService);
    $this->app->instance(SearchServiceInterface::class, $this->searchService);
    $this->app->instance(CacheServiceInterface::class, $this->cacheService);

    // Create test document
    $this->document = PdfDocument::create([
        'hash' => 'test-document-123',
        'title' => 'Test Document',
        'filename' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024000, // 1MB
        'file_path' => 'pdf-documents/test.pdf',
        'page_count' => 3,
        'status' => 'processing',
    ]);

    // Create test page
    $this->page = PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 1,
        'status' => 'processing',
        'page_file_path' => 'pdf-pages/test-document-123/page_1.pdf',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('processes page text successfully', function () {
    $textContent = 'This is extracted text content from the PDF page.';

    $this->pageService->shouldReceive('extractText')
        ->once()
        ->with($this->page->page_file_path)
        ->andReturn($textContent);

    $this->pageService->shouldReceive('updatePageContent')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class), $textContent);

    $this->searchService->shouldReceive('indexPage')
        ->once()
        ->with($this->document->hash, 1, $textContent);

    $this->cacheService->shouldReceive('cachePageContent')
        ->once()
        ->with($this->document->hash, 1, Mockery::type('array'));

    $this->pageService->shouldReceive('markPageProcessed')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class));

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    expect(true)->toBeTrue(); // Assert job completed successfully
});

it('handles missing page file path', function () {
    Log::spy();

    $this->page->update(['page_file_path' => null]);

    $this->pageService->shouldReceive('handlePageFailure')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class), 'Page file path not found for page 1');

    $job = new ProcessPageTextJob($this->page);

    // Job handles exceptions internally and calls fail(), it doesn't throw
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    Log::shouldHaveReceived('error')
        ->with('Text processing failed', Mockery::type('array'))
        ->once();

    // Verify the job completed without throwing
    expect(true)->toBeTrue();
});

it('handles empty text content', function () {
    $emptyContent = '';

    $this->pageService->shouldReceive('extractText')
        ->once()
        ->andReturn($emptyContent);

    $this->pageService->shouldReceive('updatePageContent')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class), $emptyContent);

    // Should not index empty content
    $this->searchService->shouldNotReceive('indexPage');

    $this->cacheService->shouldReceive('cachePageContent')
        ->once()
        ->with($this->document->hash, 1, Mockery::subset([
            'content' => $emptyContent,
            'word_count' => 0,
            'has_content' => false,
        ]));

    $this->pageService->shouldReceive('markPageProcessed')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class));

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    expect(true)->toBeTrue(); // Assert job completed successfully
});

it('handles text extraction failure', function () {
    Log::spy();

    $this->pageService->shouldReceive('extractText')
        ->once()
        ->andThrow(new \Exception('Text extraction failed'));

    $this->pageService->shouldReceive('handlePageFailure')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class), 'Text extraction failed');

    $job = new ProcessPageTextJob($this->page);

    // Job handles exceptions internally and calls fail(), it doesn't throw
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    Log::shouldHaveReceived('error')
        ->with('Text processing failed', Mockery::type('array'))
        ->once();

    // Verify the job completed without throwing
    expect(true)->toBeTrue();
});

it('caches page content with correct metadata', function () {
    $textContent = 'This is some test content with multiple words for testing.';
    $expectedWordCount = str_word_count($textContent);

    $this->pageService->shouldReceive('extractText')->andReturn($textContent);
    $this->pageService->shouldReceive('updatePageContent')
        ->with(Mockery::type(PdfDocumentPage::class), $textContent);
    $this->pageService->shouldReceive('markPageProcessed')
        ->with(Mockery::type(PdfDocumentPage::class));
    $this->searchService->shouldReceive('indexPage');

    $this->cacheService->shouldReceive('cachePageContent')
        ->once()
        ->with($this->document->hash, 1, [
            'content' => $textContent,
            'page_number' => 1,
            'word_count' => $expectedWordCount,
            'has_content' => true,
        ]);

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    expect(true)->toBeTrue(); // Assert job completed successfully
});

it('logs processing information', function () {
    Log::spy();

    $textContent = 'Test content';

    $this->pageService->shouldReceive('extractText')->andReturn($textContent);
    $this->pageService->shouldReceive('updatePageContent')
        ->with(Mockery::type(PdfDocumentPage::class), $textContent);
    $this->pageService->shouldReceive('markPageProcessed')
        ->with(Mockery::type(PdfDocumentPage::class));
    $this->searchService->shouldReceive('indexPage');
    $this->cacheService->shouldReceive('cachePageContent');

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    Log::shouldHaveReceived('info')
        ->with('Starting text processing', [
            'document_hash' => $this->document->hash,
            'page_number' => 1,
            'page_id' => $this->page->id,
        ])
        ->once();

    Log::shouldHaveReceived('info')
        ->with('Text processing completed', [
            'document_hash' => $this->document->hash,
            'page_number' => 1,
            'content_length' => strlen($textContent),
            'word_count' => str_word_count($textContent),
        ])
        ->once();

    expect(true)->toBeTrue(); // Assert job completed successfully
});

it('checks document completion after processing', function () {
    // Use DB facade to avoid transaction issues
    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 2,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 3,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $textContent = 'Test content';

    $this->pageService->shouldReceive('extractText')->andReturn($textContent);
    $this->pageService->shouldReceive('updatePageContent')
        ->with(Mockery::type(PdfDocumentPage::class), $textContent);
    // Mock markPageProcessed to actually update the page status
    $this->pageService->shouldReceive('markPageProcessed')
        ->with(Mockery::type(PdfDocumentPage::class))
        ->andReturnUsing(function ($page) {
            $page->update(['status' => 'completed']);
        });
    $this->searchService->shouldReceive('indexPage');
    $this->cacheService->shouldReceive('cachePageContent');

    // Mock cache warming
    $this->cacheService->shouldReceive('warmDocumentCache')
        ->once()
        ->with($this->document->hash);

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    // Should mark document as completed
    $this->document->refresh();
    expect($this->document->status)->toBe('completed');
    expect($this->document->is_searchable)->toBeTrue();
    expect($this->document->processing_completed_at)->not->toBeNull();
    expect($this->document->processing_progress['successful_pages'])->toBe(3);
});

it('handles all pages failed', function () {
    // Use DB facade to avoid transaction issues
    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 2,
        'status' => 'failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 3,
        'status' => 'failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new ProcessPageTextJob($this->page);
    $exception = new \Exception('Processing failed');

    $job->failed($exception);

    // Should mark document as failed
    $this->document->refresh();
    expect($this->document->status)->toBe('failed');
    expect($this->document->is_searchable)->toBeFalse();
    expect($this->document->processing_error)->toBe('All page processing jobs failed');
    expect($this->document->processing_progress['failed_pages'])->toBe(3);
});

it('handles cache warming failure gracefully', function () {
    Log::spy();

    // Use DB facade to avoid transaction issues
    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 2,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdf_document_pages')->insert([
        'id' => fake()->uuid(),
        'pdf_document_id' => $this->document->id,
        'page_number' => 3,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $textContent = 'Test content';

    $this->pageService->shouldReceive('extractText')->andReturn($textContent);
    $this->pageService->shouldReceive('updatePageContent')
        ->with(Mockery::type(PdfDocumentPage::class), $textContent);
    // Mock markPageProcessed to actually update the page status
    $this->pageService->shouldReceive('markPageProcessed')
        ->with(Mockery::type(PdfDocumentPage::class))
        ->andReturnUsing(function ($page) {
            $page->update(['status' => 'completed']);
        });
    $this->searchService->shouldReceive('indexPage');
    $this->cacheService->shouldReceive('cachePageContent');

    // Mock cache warming failure
    $this->cacheService->shouldReceive('warmDocumentCache')
        ->once()
        ->andThrow(new \Exception('Cache warming failed'));

    $job = new ProcessPageTextJob($this->page);
    $job->handle($this->pageService, $this->searchService, $this->cacheService);

    // Should still mark document as completed despite cache failure
    $this->document->refresh();
    expect($this->document->status)->toBe('completed');

    // Should log warning
    Log::shouldHaveReceived('warning')
        ->with('Failed to warm cache for completed document', Mockery::type('array'))
        ->once();
});

it('handles permanent failure', function () {
    $job = new ProcessPageTextJob($this->page);
    $exception = new \Exception('Permanent failure');

    $job->failed($exception);

    $this->page->refresh();
    expect($this->page->status)->toBe('failed');
    expect($this->page->processing_error)->toBe('Permanent failure');
});

it('sets correct timeout configuration', function () {
    $job = new ProcessPageTextJob($this->page);

    expect($job->timeout)->toBe(30); // Default timeout
    expect($job->tries)->toBe(2); // Default tries
    expect($job->retryAfter)->toBe(15); // Default retry after
});

it('respects vapor timeout limits', function () {
    config(['pdf-viewer.vapor.enabled' => true]);
    config(['pdf-viewer.vapor.lambda_timeout' => 900]);
    config(['pdf-viewer.jobs.text_processing.timeout' => 1200]); // Longer than lambda

    $job = new ProcessPageTextJob($this->page);

    // Should use lambda timeout minus buffer (900 - 30 = 870)
    expect($job->timeout)->toBe(870);
});

it('sets retry until correctly', function () {
    $job = new ProcessPageTextJob($this->page);
    $retryUntil = $job->retryUntil();

    expect($retryUntil)->toBeInstanceOf(\DateTime::class);
    expect($retryUntil)->toBeGreaterThan(now());
    expect($retryUntil)->toBeLessThanOrEqual(now()->addMinutes(20));
});

it('can be serialized and unserialized', function () {
    $job = new ProcessPageTextJob($this->page);

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(ProcessPageTextJob::class);
    expect($unserialized->page->id)->toBe($this->page->id);
});
