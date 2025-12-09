<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ExtractPageJob;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessPageTextJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\ExtractionAuditService;
use Shakewellagency\LaravelPdfViewer\Models\PdfExtractionAudit;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class ExtractPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected PageProcessingServiceInterface $pageService;

    protected ExtractionAuditService $auditService;

    protected PdfDocument $document;

    protected PdfDocumentPage $page;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** @test */
    public function it_extracts_page_successfully()
    {
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
        $this->assertEquals('processing', $this->page->status);
        $this->assertEquals($pageFilePath, $this->page->page_file_path);
        $this->assertEquals('thumbnails/test-document-123/page_1.jpg', $this->page->thumbnail_path);

        // Verify ProcessPageTextJob was dispatched
        Bus::assertDispatched(ProcessPageTextJob::class, function ($job) {
            return $job->page->id === $this->page->id;
        });
    }

    /** @test */
    public function it_handles_page_not_found()
    {
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
    }

    /** @test */
    public function it_handles_page_extraction_failure()
    {
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
    }

    /** @test */
    public function it_handles_thumbnail_generation_failure_gracefully()
    {
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
        $this->assertEquals($pageFilePath, $this->page->page_file_path);
        $this->assertNull($this->page->thumbnail_path);

        // Should log warning but continue (may have additional warnings from metadata)
        Log::shouldHaveReceived('warning')
            ->with('Thumbnail generation failed', Mockery::type('array'));

        // Should still dispatch text processing job
        Bus::assertDispatched(ProcessPageTextJob::class);
    }

    /** @test */
    public function it_skips_thumbnail_generation_when_disabled()
    {
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
        $this->assertEquals($pageFilePath, $this->page->page_file_path);
        $this->assertNull($this->page->thumbnail_path);

        Bus::assertDispatched(ProcessPageTextJob::class);
    }

    /** @test */
    public function it_logs_extraction_progress()
    {
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

        Log::shouldHaveReceived('info')
            ->with('Starting page extraction', [
                'document_hash' => 'test-document-123',
                'page_number' => 1,
                'page_id' => $this->page->id,
            ])
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Page extraction completed', Mockery::type('array'))
            ->once();
    }

    /** @test */
    public function it_handles_permanent_failure()
    {
        $job = new ExtractPageJob($this->document, 1);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);

        $this->page->refresh();
        $this->assertEquals('failed', $this->page->status);
        $this->assertEquals('Permanent failure', $this->page->processing_error);
    }

    /** @test */
    public function it_checks_document_completion_after_failure()
    {
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
        $this->assertEquals('failed', $this->document->status);
        $this->assertEquals(100, $this->document->processing_progress['progress']);
        $this->assertEquals(3, $this->document->processing_progress['failed_pages']);
        $this->assertFalse($this->document->is_searchable);
    }

    /** @test */
    public function it_marks_document_completed_with_mixed_results()
    {
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
        $this->assertEquals('completed', $this->document->status);
        $this->assertTrue($this->document->is_searchable);
        $this->assertEquals(1, $this->document->processing_progress['failed_pages']);
        $this->assertEquals(2, $this->document->processing_progress['completed_pages']);
    }

    /** @test */
    public function it_sets_correct_timeout_configuration()
    {
        $job = new ExtractPageJob($this->document, 1);

        $this->assertEquals(60, $job->timeout); // Default timeout
        $this->assertEquals(2, $job->tries); // Default tries
        $this->assertEquals(30, $job->retryAfter); // Default retry after
    }

    /** @test */
    public function it_respects_vapor_timeout_limits()
    {
        config(['pdf-viewer.vapor.enabled' => true]);
        config(['pdf-viewer.vapor.lambda_timeout' => 900]);
        config(['pdf-viewer.jobs.page_extraction.timeout' => 1200]); // Longer than lambda

        $job = new ExtractPageJob($this->document, 1);

        // Should use lambda timeout minus buffer (900 - 30 = 870)
        $this->assertEquals(870, $job->timeout);
    }

    /** @test */
    public function it_sets_retry_until_correctly()
    {
        $job = new ExtractPageJob($this->document, 1);
        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertGreaterThan(now(), $retryUntil);
        $this->assertLessThanOrEqual(now()->addMinutes(30), $retryUntil);
    }

    /** @test */
    public function it_can_be_serialized_and_unserialized()
    {
        $job = new ExtractPageJob($this->document, 1);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ExtractPageJob::class, $unserialized);
        $this->assertEquals($this->document->id, $unserialized->document->id);
        $this->assertEquals(1, $unserialized->pageNumber);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
