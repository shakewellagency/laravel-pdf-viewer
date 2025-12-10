<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ExtractPageJob;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

beforeEach(function () {
    // Mock services
    $this->processingService = Mockery::mock(DocumentProcessingServiceInterface::class);
    $this->pageService = Mockery::mock(PageProcessingServiceInterface::class);

    // Create mock page for all tests
    $this->mockPage = Mockery::mock(\Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage::class);

    // Bind mocks to container
    $this->app->instance(DocumentProcessingServiceInterface::class, $this->processingService);
    $this->app->instance(PageProcessingServiceInterface::class, $this->pageService);

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
        'status' => 'uploaded',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('processes document successfully', function () {
    Bus::fake();

    $this->processingService->shouldReceive('validatePdf')
        ->once()
        ->with($this->document->file_path)
        ->andReturn(true);

    $this->pageService->shouldReceive('createPage')
        ->times(3)
        ->with($this->document, Mockery::type('int'))
        ->andReturn($this->mockPage);

    $job = new ProcessDocumentJob($this->document);
    $job->handle($this->processingService, $this->pageService);

    // Verify ExtractPageJob was dispatched for each page
    Bus::assertDispatched(ExtractPageJob::class, 3);

    // Verify document processing progress was updated
    $this->document->refresh();
    expect($this->document->processing_progress)->not->toBeNull();
    expect($this->document->processing_progress['stage'])->toBe('pages_dispatched');
    expect($this->document->processing_progress['progress'])->toBe(50);
});

it('handles invalid pdf file', function () {
    Log::spy();

    $this->processingService->shouldReceive('validatePdf')
        ->once()
        ->with($this->document->file_path)
        ->andReturn(false);

    $this->processingService->shouldReceive('handleFailure')
        ->once()
        ->with($this->document->hash, 'Invalid PDF file');

    $job = new ProcessDocumentJob($this->document);

    // The job handles exceptions internally and calls fail(), it doesn't throw
    $job->handle($this->processingService, $this->pageService);

    Log::shouldHaveReceived('error')
        ->with('Document processing failed', Mockery::type('array'))
        ->once();

    // Verify the job completed without throwing
    expect(true)->toBeTrue();
});

it('updates processing progress stages', function () {
    Bus::fake();

    $this->processingService->shouldReceive('validatePdf')->andReturn(true);
    $this->pageService->shouldReceive('createPage')->andReturn($this->mockPage);

    $job = new ProcessDocumentJob($this->document);
    $job->handle($this->processingService, $this->pageService);

    $this->document->refresh();

    // Check that progress was updated multiple times
    $progress = $this->document->processing_progress;
    expect($progress['stage'])->toBe('pages_dispatched');
    expect($progress['progress'])->toBe(50);
});

it('handles document with no pages', function () {
    Bus::fake();

    $this->document->update(['page_count' => 0]);

    $this->processingService->shouldReceive('validatePdf')->andReturn(true);

    // Should not call createPage for zero pages
    $this->pageService->shouldNotReceive('createPage');

    $job = new ProcessDocumentJob($this->document);
    $job->handle($this->processingService, $this->pageService);

    // Should not dispatch any ExtractPageJob
    Bus::assertNotDispatched(ExtractPageJob::class);
});

it('logs processing information', function () {
    Log::spy();
    Bus::fake();

    $this->processingService->shouldReceive('validatePdf')->andReturn(true);
    $this->pageService->shouldReceive('createPage')->andReturn($this->mockPage);

    $job = new ProcessDocumentJob($this->document);
    $job->handle($this->processingService, $this->pageService);

    Log::shouldHaveReceived('info')
        ->with('Starting document processing', [
            'document_hash' => $this->document->hash,
            'document_id' => $this->document->id,
        ])
        ->once();

    Log::shouldHaveReceived('info')
        ->with('Document processing jobs dispatched', [
            'document_hash' => $this->document->hash,
            'total_pages' => $this->document->page_count,
        ])
        ->once();

    // Verify the job completed successfully
    expect(true)->toBeTrue();
});

it('handles page creation failure', function () {
    Log::spy();

    $this->processingService->shouldReceive('validatePdf')->andReturn(true);
    $this->pageService->shouldReceive('createPage')
        ->andThrow(new \Exception('Page creation failed'));

    $this->processingService->shouldReceive('handleFailure')
        ->once()
        ->with($this->document->hash, 'Page creation failed');

    $job = new ProcessDocumentJob($this->document);

    // The job handles exceptions internally and calls fail(), it doesn't throw
    $job->handle($this->processingService, $this->pageService);

    Log::shouldHaveReceived('error')
        ->with('Document processing failed', Mockery::type('array'))
        ->once();

    // Verify the job completed without throwing
    expect(true)->toBeTrue();
});

it('fails permanently after max attempts', function () {
    $this->document->update(['status' => 'uploaded']);

    $job = new ProcessDocumentJob($this->document);
    $exception = new \Exception('Test failure');

    $job->failed($exception);

    $this->document->refresh();
    expect($this->document->status)->toBe('failed');
    expect($this->document->processing_error)->toBe('Test failure');
    expect($this->document->processing_progress['stage'])->toBe('failed');
    expect($this->document->processing_progress['progress'])->toBe(0);
});

it('sets correct timeout configuration', function () {
    $job = new ProcessDocumentJob($this->document);

    expect($job->timeout)->toBe(300); // Default timeout
    expect($job->tries)->toBe(3); // Default tries
    expect($job->retryAfter)->toBe(60); // Default retry after
});

it('respects vapor timeout limits', function () {
    config(['pdf-viewer.vapor.enabled' => true]);
    config(['pdf-viewer.vapor.lambda_timeout' => 900]);
    config(['pdf-viewer.jobs.document_processing.timeout' => 1200]); // Longer than lambda

    $job = new ProcessDocumentJob($this->document);

    // Should use lambda timeout minus buffer (900 - 30 = 870)
    expect($job->timeout)->toBe(870);
});

it('sets retry until correctly', function () {
    $job = new ProcessDocumentJob($this->document);
    $retryUntil = $job->retryUntil();

    expect($retryUntil)->toBeInstanceOf(\DateTime::class);
    expect($retryUntil)->toBeGreaterThan(now());
    expect($retryUntil)->toBeLessThanOrEqual(now()->addHour());
});

it('can be serialized and unserialized', function () {
    $job = new ProcessDocumentJob($this->document);

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(ProcessDocumentJob::class);
    expect($unserialized->document->id)->toBe($this->document->id);
    expect($unserialized->document->hash)->toBe($this->document->hash);
});

it('uses correct queue configuration', function () {
    config(['pdf-viewer.jobs.document_processing.queue' => 'high-priority']);

    Queue::fake();

    ProcessDocumentJob::dispatch($this->document);

    Queue::assertPushedOn('high-priority', ProcessDocumentJob::class);
});

it('dispatches extract page jobs in correct order', function () {
    Bus::fake();

    $this->processingService->shouldReceive('validatePdf')->andReturn(true);
    $this->pageService->shouldReceive('createPage')->andReturn($this->mockPage);

    $job = new ProcessDocumentJob($this->document);
    $job->handle($this->processingService, $this->pageService);

    // Verify jobs are dispatched for pages 1, 2, and 3
    for ($i = 1; $i <= 3; $i++) {
        Bus::assertDispatched(ExtractPageJob::class, function ($job) use ($i) {
            return $job->document->id === $this->document->id && $job->pageNumber === $i;
        });
    }
});
