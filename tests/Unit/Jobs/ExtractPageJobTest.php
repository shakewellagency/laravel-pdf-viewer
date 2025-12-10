<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ExtractPageJob;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessPageTextJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfExtractionAudit;
use Shakewellagency\LaravelPdfViewer\Services\ExtractionAuditService;

beforeEach(function () {
    // Mock services
    $this->pageService = Mockery::mock(PageProcessingServiceInterface::class);
    $this->app->instance(PageProcessingServiceInterface::class, $this->pageService);

    $this->auditService = Mockery::mock(ExtractionAuditService::class);
    $this->app->instance(ExtractionAuditService::class, $this->auditService);

    // Create test document
    $this->document = PdfDocument::create([
        'hash' => 'test-document-123',
        'title' => 'Test Document',
        'filename' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'file_path' => 'pdf-documents/test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'page_count' => 3,
        'status' => 'processing',
    ]);

    // Create test page
    $this->page = PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 1,
        'status' => 'pending',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('extracts page successfully', function () {
    config(['pdf-viewer.thumbnails.enabled' => true]);
    Bus::fake();

    $pageFilePath = 'pdf-pages/test-document-123/page_1.pdf';
    $extractionResult = [
        'file_path' => $pageFilePath,
        'context' => ['method' => 'pdftk', 'duration' => 0.5],
    ];

    // Mock audit service - must return properly typed PdfExtractionAudit
    $auditMock = Mockery::mock(PdfExtractionAudit::class)->makePartial();
    $auditMock->id = 1;

    $this->auditService->shouldReceive('initiateExtraction')
        ->once()
        ->with($this->document, [1], 'page_extraction', Mockery::any())
        ->andReturn($auditMock);

    $this->auditService->shouldReceive('recordPageCompletion')
        ->once()
        ->with($auditMock, 1, $extractionResult['context']);

    $this->auditService->shouldReceive('completeExtraction')
        ->once()
        ->with($auditMock);

    $this->pageService->shouldReceive('extractPageWithContext')
        ->once()
        ->with($this->document, 1)
        ->andReturn($extractionResult);

    $this->pageService->shouldReceive('generateThumbnail')
        ->once()
        ->with($pageFilePath, 300, 400)
        ->andReturn('thumbnails/test-document-123/page_1.jpg');

    $job = new ExtractPageJob($this->document, 1);
    $job->handle($this->pageService, $this->auditService);

    // Verify page was updated with file path
    $this->page->refresh();
    expect($this->page->status)->toBe('processing');
    expect($this->page->page_file_path)->toBe($pageFilePath);
    expect($this->page->thumbnail_path)->toBe('thumbnails/test-document-123/page_1.jpg');

    // Verify ProcessPageTextJob was dispatched
    Bus::assertDispatched(ProcessPageTextJob::class, function ($job) {
        return $job->page->id === $this->page->id;
    });
});

it('handles page not found', function () {
    Log::spy();

    $this->auditService->shouldReceive('initiateExtraction')->never();

    $job = new ExtractPageJob($this->document, 99); // Page that doesn't exist

    // The job handles exceptions internally and calls fail(), it doesn't throw
    try {
        $job->handle($this->pageService, $this->auditService);
    } catch (\Exception $e) {
        // Expected to call fail() internally
    }

    Log::shouldHaveReceived('error')
        ->with('Page extraction failed', Mockery::type('array'))
        ->once();

    expect(true)->toBeTrue(); // Job handled page not found gracefully
});

it('handles page extraction failure', function () {
    Log::spy();

    $auditMock = Mockery::mock(PdfExtractionAudit::class)->makePartial();
    $auditMock->id = 1;

    $this->auditService->shouldReceive('initiateExtraction')
        ->once()
        ->andReturn($auditMock);

    $this->auditService->shouldReceive('recordExtractionFailure')
        ->once()
        ->with($auditMock, 'Page extraction failed', Mockery::type('array'));

    $this->pageService->shouldReceive('extractPageWithContext')
        ->once()
        ->andThrow(new \Exception('Page extraction failed'));

    $this->pageService->shouldReceive('handlePageFailure')
        ->once()
        ->with(Mockery::type(PdfDocumentPage::class), 'Page extraction failed');

    $job = new ExtractPageJob($this->document, 1);

    // The job handles exceptions internally and calls fail(), it doesn't throw
    try {
        $job->handle($this->pageService, $this->auditService);
    } catch (\Exception $e) {
        // Expected to call fail() internally
    }

    Log::shouldHaveReceived('error')
        ->with('Page extraction failed', Mockery::type('array'))
        ->once();

    expect(true)->toBeTrue(); // Job handled extraction failure gracefully
});

it('handles thumbnail generation failure gracefully', function () {
    config(['pdf-viewer.thumbnails.enabled' => true]);
    Bus::fake();
    Log::spy();

    $pageFilePath = 'pdf-pages/test-document-123/page_1.pdf';
    $extractionResult = [
        'file_path' => $pageFilePath,
        'context' => ['method' => 'pdftk'],
    ];

    $auditMock = Mockery::mock(PdfExtractionAudit::class)->makePartial();
    $auditMock->id = 1;

    $this->auditService->shouldReceive('initiateExtraction')->andReturn($auditMock);
    $this->auditService->shouldReceive('recordPageCompletion');
    $this->auditService->shouldReceive('completeExtraction');

    $this->pageService->shouldReceive('extractPageWithContext')
        ->once()
        ->andReturn($extractionResult);

    $this->pageService->shouldReceive('generateThumbnail')
        ->once()
        ->andThrow(new \Exception('Thumbnail generation failed'));

    $job = new ExtractPageJob($this->document, 1);
    $job->handle($this->pageService, $this->auditService);

    // Should not fail the entire job
    $this->page->refresh();
    expect($this->page->page_file_path)->toBe($pageFilePath);
    expect($this->page->thumbnail_path)->toBeNull();

    // Should log warning but continue (may have additional warnings from metadata)
    Log::shouldHaveReceived('warning')
        ->with('Thumbnail generation failed', Mockery::type('array'));

    // Should still dispatch text processing job
    Bus::assertDispatched(ProcessPageTextJob::class);
});

it('skips thumbnail generation when disabled', function () {
    config(['pdf-viewer.thumbnails.enabled' => false]);
    Bus::fake();

    $pageFilePath = 'pdf-pages/test-document-123/page_1.pdf';
    $extractionResult = [
        'file_path' => $pageFilePath,
        'context' => ['method' => 'pdftk'],
    ];

    $auditMock = Mockery::mock(PdfExtractionAudit::class)->makePartial();
    $auditMock->id = 1;

    $this->auditService->shouldReceive('initiateExtraction')->andReturn($auditMock);
    $this->auditService->shouldReceive('recordPageCompletion');
    $this->auditService->shouldReceive('completeExtraction');

    $this->pageService->shouldReceive('extractPageWithContext')
        ->once()
        ->andReturn($extractionResult);

    // Should not call generateThumbnail
    $this->pageService->shouldNotReceive('generateThumbnail');

    $job = new ExtractPageJob($this->document, 1);
    $job->handle($this->pageService, $this->auditService);

    $this->page->refresh();
    expect($this->page->page_file_path)->toBe($pageFilePath);
    expect($this->page->thumbnail_path)->toBeNull();

    Bus::assertDispatched(ProcessPageTextJob::class);
});

it('logs extraction progress', function () {
    config(['pdf-viewer.thumbnails.enabled' => true]);
    Log::spy();
    Bus::fake();

    $pageFilePath = 'pdf-pages/test-document-123/page_1.pdf';
    $extractionResult = [
        'file_path' => $pageFilePath,
        'context' => ['method' => 'pdftk'],
    ];

    $auditMock = Mockery::mock(PdfExtractionAudit::class)->makePartial();
    $auditMock->id = 1;

    $this->auditService->shouldReceive('initiateExtraction')->andReturn($auditMock);
    $this->auditService->shouldReceive('recordPageCompletion');
    $this->auditService->shouldReceive('completeExtraction');

    $this->pageService->shouldReceive('extractPageWithContext')->andReturn($extractionResult);
    $this->pageService->shouldReceive('generateThumbnail')->andReturn('thumbnail.jpg');

    $job = new ExtractPageJob($this->document, 1);
    $job->handle($this->pageService, $this->auditService);

    // Verify logging occurred - use Mockery::any() for flexibility
    Log::shouldHaveReceived('info')
        ->with('Starting page extraction', Mockery::type('array'))
        ->atLeast()->once();

    Log::shouldHaveReceived('info')
        ->with('Page extraction completed', Mockery::type('array'))
        ->atLeast()->once();

    expect(true)->toBeTrue(); // Logging verified via mock expectations
});

it('handles permanent failure', function () {
    $job = new ExtractPageJob($this->document, 1);
    $exception = new \Exception('Permanent failure');

    $job->failed($exception);

    $this->page->refresh();
    expect($this->page->status)->toBe('failed');
    expect($this->page->processing_error)->toBe('Permanent failure');
});

it('checks document completion after failure', function () {
    // Create additional pages
    PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 2,
        'status' => 'failed',
    ]);

    PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 3,
        'status' => 'failed',
    ]);

    $job = new ExtractPageJob($this->document, 1);
    $exception = new \Exception('Test failure');

    $job->failed($exception);

    // Should update document status when all pages completed/failed
    $this->document->refresh();
    expect($this->document->status)->toBe('failed');
    expect($this->document->processing_progress['progress'])->toBe(100);
    expect($this->document->processing_progress['failed_pages'])->toBe(3);
    expect($this->document->is_searchable)->toBeFalse();
});

it('marks document completed with mixed results', function () {
    // Create additional pages with mixed statuses
    PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 2,
        'status' => 'completed',
    ]);

    PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 3,
        'status' => 'completed',
    ]);

    // Mock cache service for warming
    $cacheService = Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class);
    $cacheService->shouldReceive('warmDocumentCache')
        ->once()
        ->with($this->document->hash);

    $this->app->instance(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class, $cacheService);

    $job = new ExtractPageJob($this->document, 1);
    $exception = new \Exception('Test failure');

    $job->failed($exception);

    // Should mark as completed since some pages succeeded
    $this->document->refresh();
    expect($this->document->status)->toBe('completed');
    expect($this->document->is_searchable)->toBeTrue();
    expect($this->document->processing_progress['failed_pages'])->toBe(1);
    expect($this->document->processing_progress['completed_pages'])->toBe(2);
});

it('sets correct timeout configuration', function () {
    $job = new ExtractPageJob($this->document, 1);

    expect($job->timeout)->toBe(60); // Default timeout
    expect($job->tries)->toBe(2); // Default tries
    expect($job->retryAfter)->toBe(30); // Default retry after
});

it('respects vapor timeout limits', function () {
    config(['pdf-viewer.vapor.enabled' => true]);
    config(['pdf-viewer.vapor.lambda_timeout' => 900]);
    config(['pdf-viewer.jobs.page_extraction.timeout' => 1200]); // Longer than lambda

    $job = new ExtractPageJob($this->document, 1);

    // Should use lambda timeout minus buffer (900 - 30 = 870)
    expect($job->timeout)->toBe(870);
});

it('sets retry until correctly', function () {
    $job = new ExtractPageJob($this->document, 1);
    $retryUntil = $job->retryUntil();

    expect($retryUntil)->toBeInstanceOf(\DateTime::class);
    expect($retryUntil)->toBeGreaterThan(now());
    expect($retryUntil)->toBeLessThanOrEqual(now()->addMinutes(30));
});

it('can be serialized and unserialized', function () {
    $job = new ExtractPageJob($this->document, 1);

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(ExtractPageJob::class);
    expect($unserialized->document->id)->toBe($this->document->id);
    expect($unserialized->pageNumber)->toBe(1);
});
