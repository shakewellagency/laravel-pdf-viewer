<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Resources;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentProgressResource;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentProgressResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transforms_document_progress_to_array(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Progress Test Document',
            'status' => 'processing',
            'page_count' => 10,
            'is_searchable' => true,
            'processing_progress' => 45.5,
            'processing_started_at' => now()->subHours(2),
        ]);

        // Create some completed and failed pages
        PdfDocumentPage::factory()->count(4)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        PdfDocumentPage::factory()->count(1)->create([
            'pdf_document_id' => $document->id,
            'status' => 'failed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals($document->hash, $result['hash']);
        $this->assertEquals('Progress Test Document', $result['title']);
        $this->assertEquals('processing', $result['status']);
        $this->assertEquals(10, $result['total_pages']);
        $this->assertEquals(4, $result['completed_pages']);
        $this->assertEquals(1, $result['failed_pages']);
        $this->assertTrue($result['is_searchable']);
        $this->assertEquals(45.5, $result['processing_progress']);
    }

    public function test_includes_processing_error_when_failed(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'failed',
            'processing_error' => 'Failed to process document',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('processing_error', $result);
        $this->assertEquals('Failed to process document', $result['processing_error']);
    }

    public function test_excludes_processing_error_when_not_failed(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('processing_error', $result);
    }

    public function test_includes_estimated_completion_when_processing(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
            'page_count' => 10,
            'processing_started_at' => now()->subMinutes(30),
        ]);

        // Create some completed pages to enable estimation
        PdfDocumentPage::factory()->count(3)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('estimated_completion', $result);
        $this->assertIsString($result['estimated_completion']);
    }

    public function test_excludes_estimated_completion_when_not_processing(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'completed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('estimated_completion', $result);
    }

    public function test_estimated_completion_returns_null_with_no_completed_pages(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
            'page_count' => 10,
            'processing_started_at' => now()->subMinutes(30),
        ]);

        // No completed pages
        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('estimated_completion', $result);
    }

    public function test_estimated_completion_returns_null_with_no_processing_start_time(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
            'page_count' => 10,
            'processing_started_at' => null,
        ]);

        PdfDocumentPage::factory()->count(3)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('estimated_completion', $result);
    }

    public function test_estimated_completion_returns_null_with_zero_page_count(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
            'page_count' => 0,
            'processing_started_at' => now()->subMinutes(30),
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('estimated_completion', $result);
    }

    public function test_formats_timestamps_properly(): void
    {
        $startTime = now()->subHours(3);
        $endTime = now()->subHour();
        
        $document = PdfDocument::factory()->create([
            'processing_started_at' => $startTime,
            'processing_completed_at' => $endTime,
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertStringStartsWith($startTime->format('Y-m-d\TH:i:s'), $result['processing_started_at']);
        $this->assertStringStartsWith($endTime->format('Y-m-d\TH:i:s'), $result['processing_completed_at']);
    }

    public function test_handles_null_timestamps(): void
    {
        $document = PdfDocument::factory()->create([
            'processing_started_at' => null,
            'processing_completed_at' => null,
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertNull($result['processing_started_at']);
        $this->assertNull($result['processing_completed_at']);
    }

    public function test_includes_progress_percentage(): void
    {
        $document = PdfDocument::factory()->create([
            'page_count' => 10,
        ]);

        // Create 7 completed pages for 70% progress
        PdfDocumentPage::factory()->count(7)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('progress_percentage', $result);
        $this->assertEquals($document->getProcessingProgress(), $result['progress_percentage']);
    }

    public function test_handles_complete_document(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'completed',
            'page_count' => 5,
            'processing_started_at' => now()->subHours(2),
            'processing_completed_at' => now()->subHour(),
        ]);

        PdfDocumentPage::factory()->count(5)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(5, $result['total_pages']);
        $this->assertEquals(5, $result['completed_pages']);
        $this->assertEquals(0, $result['failed_pages']);
        $this->assertArrayNotHasKey('estimated_completion', $result);
        $this->assertArrayNotHasKey('processing_error', $result);
    }

    public function test_handles_failed_document_with_partial_processing(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'failed',
            'page_count' => 10,
            'processing_error' => 'Processing timeout',
            'processing_started_at' => now()->subHours(3),
        ]);

        PdfDocumentPage::factory()->count(3)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        PdfDocumentPage::factory()->count(2)->create([
            'pdf_document_id' => $document->id,
            'status' => 'failed',
        ]);

        $resource = new DocumentProgressResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(10, $result['total_pages']);
        $this->assertEquals(3, $result['completed_pages']);
        $this->assertEquals(2, $result['failed_pages']);
        $this->assertEquals('Processing timeout', $result['processing_error']);
        $this->assertArrayNotHasKey('estimated_completion', $result);
    }
}