<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ExtractPageJob;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class ProcessDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentProcessingServiceInterface $processingService;

    protected PageProcessingServiceInterface $pageService;

    protected PdfDocument $document;
    protected $mockPage;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** @test */
    public function it_processes_document_successfully()
    {
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
        $this->assertNotNull($this->document->processing_progress);
        $this->assertEquals('pages_dispatched', $this->document->processing_progress['stage']);
        $this->assertEquals(50, $this->document->processing_progress['progress']);
    }

    /** @test */
    public function it_handles_invalid_pdf_file()
    {
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
        $this->assertTrue(true);
    }

    /** @test */
    public function it_updates_processing_progress_stages()
    {
        Bus::fake();

        $this->processingService->shouldReceive('validatePdf')->andReturn(true);
        $this->pageService->shouldReceive('createPage')->andReturn($this->mockPage);

        $job = new ProcessDocumentJob($this->document);
        $job->handle($this->processingService, $this->pageService);

        $this->document->refresh();

        // Check that progress was updated multiple times
        $progress = $this->document->processing_progress;
        $this->assertEquals('pages_dispatched', $progress['stage']);
        $this->assertEquals(50, $progress['progress']);
    }

    /** @test */
    public function it_handles_document_with_no_pages()
    {
        Bus::fake();

        $this->document->update(['page_count' => 0]);

        $this->processingService->shouldReceive('validatePdf')->andReturn(true);

        // Should not call createPage for zero pages
        $this->pageService->shouldNotReceive('createPage');

        $job = new ProcessDocumentJob($this->document);
        $job->handle($this->processingService, $this->pageService);

        // Should not dispatch any ExtractPageJob
        Bus::assertNotDispatched(ExtractPageJob::class);
    }

    /** @test */
    public function it_logs_processing_information()
    {
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
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_page_creation_failure()
    {
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
        $this->assertTrue(true);
    }

    /** @test */
    public function it_fails_permanently_after_max_attempts()
    {
        $this->document->update(['status' => 'uploaded']);

        $job = new ProcessDocumentJob($this->document);
        $exception = new \Exception('Test failure');

        $job->failed($exception);

        $this->document->refresh();
        $this->assertEquals('failed', $this->document->status);
        $this->assertEquals('Test failure', $this->document->processing_error);
        $this->assertEquals('failed', $this->document->processing_progress['stage']);
        $this->assertEquals(0, $this->document->processing_progress['progress']);
    }

    /** @test */
    public function it_sets_correct_timeout_configuration()
    {
        $job = new ProcessDocumentJob($this->document);

        $this->assertEquals(300, $job->timeout); // Default timeout
        $this->assertEquals(3, $job->tries); // Default tries
        $this->assertEquals(60, $job->retryAfter); // Default retry after
    }

    /** @test */
    public function it_respects_vapor_timeout_limits()
    {
        config(['pdf-viewer.vapor.enabled' => true]);
        config(['pdf-viewer.vapor.lambda_timeout' => 900]);
        config(['pdf-viewer.jobs.document_processing.timeout' => 1200]); // Longer than lambda

        $job = new ProcessDocumentJob($this->document);

        // Should use lambda timeout minus buffer (900 - 30 = 870)
        $this->assertEquals(870, $job->timeout);
    }

    /** @test */
    public function it_sets_retry_until_correctly()
    {
        $job = new ProcessDocumentJob($this->document);
        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertGreaterThan(now(), $retryUntil);
        $this->assertLessThanOrEqual(now()->addHour(), $retryUntil);
    }

    /** @test */
    public function it_can_be_serialized_and_unserialized()
    {
        $job = new ProcessDocumentJob($this->document);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ProcessDocumentJob::class, $unserialized);
        $this->assertEquals($this->document->id, $unserialized->document->id);
        $this->assertEquals($this->document->hash, $unserialized->document->hash);
    }

    /** @test */
    public function it_uses_correct_queue_configuration()
    {
        config(['pdf-viewer.jobs.document_processing.queue' => 'high-priority']);

        Queue::fake();

        ProcessDocumentJob::dispatch($this->document);

        Queue::assertPushedOn('high-priority', ProcessDocumentJob::class);
    }

    /** @test */
    public function it_dispatches_extract_page_jobs_in_correct_order()
    {
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
