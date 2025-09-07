<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessPageTextJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class ProcessPageTextJobTest extends TestCase
{
    use RefreshDatabase;

    protected PageProcessingServiceInterface $pageService;

    protected SearchServiceInterface $searchService;

    protected CacheServiceInterface $cacheService;

    protected PdfDocument $document;

    protected PdfDocumentPage $page;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** @test */
    public function it_processes_page_text_successfully()
    {
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
        
        $this->assertTrue(true); // Assert job completed successfully
    }

    /** @test */
    public function it_handles_missing_page_file_path()
    {
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
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_empty_text_content()
    {
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
        
        $this->assertTrue(true); // Assert job completed successfully
    }

    /** @test */
    public function it_handles_text_extraction_failure()
    {
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
        $this->assertTrue(true);
    }

    /** @test */
    public function it_caches_page_content_with_correct_metadata()
    {
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
        
        $this->assertTrue(true); // Assert job completed successfully
    }

    /** @test */
    public function it_logs_processing_information()
    {
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
            
        $this->assertTrue(true); // Assert job completed successfully
    }

    /** @test */
    public function it_checks_document_completion_after_processing()
    {
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
        $this->pageService->shouldReceive('markPageProcessed')
            ->with(Mockery::type(PdfDocumentPage::class));
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
        $this->assertEquals('completed', $this->document->status);
        $this->assertTrue($this->document->is_searchable);
        $this->assertNotNull($this->document->processing_completed_at);
        $this->assertEquals(3, $this->document->processing_progress['successful_pages']);
    }

    /** @test */
    public function it_handles_all_pages_failed()
    {
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
        $this->assertEquals('failed', $this->document->status);
        $this->assertFalse($this->document->is_searchable);
        $this->assertEquals('All page processing jobs failed', $this->document->processing_error);
        $this->assertEquals(3, $this->document->processing_progress['failed_pages']);
    }

    /** @test */
    public function it_handles_cache_warming_failure_gracefully()
    {
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
        $this->pageService->shouldReceive('markPageProcessed')
            ->with(Mockery::type(PdfDocumentPage::class));
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
        $this->assertEquals('completed', $this->document->status);

        // Should log warning
        Log::shouldHaveReceived('warning')
            ->with('Failed to warm cache for completed document', Mockery::type('array'))
            ->once();
    }

    /** @test */
    public function it_handles_permanent_failure()
    {
        $job = new ProcessPageTextJob($this->page);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);

        $this->page->refresh();
        $this->assertEquals('failed', $this->page->status);
        $this->assertEquals('Permanent failure', $this->page->processing_error);
    }

    /** @test */
    public function it_sets_correct_timeout_configuration()
    {
        $job = new ProcessPageTextJob($this->page);

        $this->assertEquals(30, $job->timeout); // Default timeout
        $this->assertEquals(2, $job->tries); // Default tries
        $this->assertEquals(15, $job->retryAfter); // Default retry after
    }

    /** @test */
    public function it_respects_vapor_timeout_limits()
    {
        config(['pdf-viewer.vapor.enabled' => true]);
        config(['pdf-viewer.vapor.lambda_timeout' => 900]);
        config(['pdf-viewer.jobs.text_processing.timeout' => 1200]); // Longer than lambda

        $job = new ProcessPageTextJob($this->page);

        // Should use lambda timeout minus buffer (900 - 30 = 870)
        $this->assertEquals(870, $job->timeout);
    }

    /** @test */
    public function it_sets_retry_until_correctly()
    {
        $job = new ProcessPageTextJob($this->page);
        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertGreaterThan(now(), $retryUntil);
        $this->assertLessThanOrEqual(now()->addMinutes(20), $retryUntil);
    }

    /** @test */
    public function it_can_be_serialized_and_unserialized()
    {
        $job = new ProcessPageTextJob($this->page);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ProcessPageTextJob::class, $unserialized);
        $this->assertEquals($this->page->id, $unserialized->page->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
