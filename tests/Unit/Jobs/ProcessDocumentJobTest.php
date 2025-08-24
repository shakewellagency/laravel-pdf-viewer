<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Jobs\ExtractPageJob;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class ProcessDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_dispatches_page_extraction_jobs(): void
    {
        Bus::fake();
        Storage::fake('testing');

        $document = PdfDocument::factory()->create([
            'status' => 'uploaded',
            'page_count' => 3,
        ]);

        // Create a fake PDF file
        Storage::disk('testing')->put(
            "pdf-documents/{$document->hash}.pdf",
            'fake pdf content'
        );

        $job = new ProcessDocumentJob($document);
        
        // Mock the services since this is a unit test
        $processingService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface::class);
        $pageService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface::class);
        
        $processingService->shouldReceive('getPageCount')->with($document)->andReturn(3);
        $processingService->shouldReceive('extractPages')->with($document)->andReturn(true);
        
        $job->handle($processingService, $pageService);

        // Verify ExtractPageJob was dispatched for each page
        Bus::assertDispatched(ExtractPageJob::class, 3);
        
        // Verify document status is updated
        $document->refresh();
        $this->assertEquals('processing', $document->status);
        $this->assertNotNull($document->processing_started_at);
    }

    public function test_job_handles_missing_file_gracefully(): void
    {
        Storage::fake('testing');

        $document = PdfDocument::factory()->create([
            'status' => 'uploaded',
            'page_count' => 3,
        ]);

        $job = new ProcessDocumentJob($document);
        
        // Mock the services
        $processingService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface::class);
        $pageService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface::class);
        
        $processingService->shouldReceive('getPageCount')->with($document)->andThrow(new \Exception('File not found'));
        
        $job->handle($processingService, $pageService);

        // Verify document is marked as failed
        $document->refresh();
        $this->assertEquals('failed', $document->status);
        $this->assertNotNull($document->processing_error);
    }

    public function test_job_updates_document_progress(): void
    {
        Bus::fake();
        Storage::fake('testing');

        $document = PdfDocument::factory()->create([
            'status' => 'uploaded',
            'page_count' => 5,
        ]);

        Storage::disk('testing')->put(
            "pdf-documents/{$document->hash}.pdf",
            'fake pdf content'
        );

        $job = new ProcessDocumentJob($document);
        
        // Mock the services
        $processingService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface::class);
        $pageService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface::class);
        
        $processingService->shouldReceive('getPageCount')->with($document)->andReturn(5);
        $processingService->shouldReceive('extractPages')->with($document)->andReturn(true);
        
        $job->handle($processingService, $pageService);

        $document->refresh();
        $this->assertEquals('processing', $document->status);
        $this->assertNotNull($document->processing_started_at);
    }

    public function test_job_handles_zero_page_document(): void
    {
        Storage::fake('testing');

        $document = PdfDocument::factory()->create([
            'status' => 'uploaded',
            'page_count' => 0,
        ]);

        Storage::disk('testing')->put(
            "pdf-documents/{$document->hash}.pdf",
            'fake pdf content'
        );

        $job = new ProcessDocumentJob($document);
        
        // Mock the services
        $processingService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface::class);
        $pageService = \Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface::class);
        
        $processingService->shouldReceive('getPageCount')->with($document)->andReturn(0);
        
        $job->handle($processingService, $pageService);

        $document->refresh();
        $this->assertEquals('completed', $document->status);
        $this->assertNotNull($document->processing_completed_at);
    }

    public function test_job_can_be_serialized(): void
    {
        $document = PdfDocument::factory()->create();
        $job = new ProcessDocumentJob($document);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ProcessDocumentJob::class, $unserialized);
        $this->assertEquals($document->id, $unserialized->document->id);
    }
}