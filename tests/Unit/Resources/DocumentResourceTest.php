<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Resources;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentResource;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transforms_document_to_array(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Test Document',
            'original_filename' => 'test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'page_count' => 5,
            'status' => 'completed',
            'is_searchable' => true,
            'metadata' => ['author' => 'Test Author'],
            'created_by' => 'user-123',
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals($document->id, $result['id']);
        $this->assertEquals($document->hash, $result['hash']);
        $this->assertEquals('Test Document', $result['title']);
        $this->assertEquals('test.pdf', $result['filename']);
        $this->assertEquals(1024, $result['file_size']);
        $this->assertEquals('application/pdf', $result['mime_type']);
        $this->assertEquals(5, $result['page_count']);
        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['is_searchable']);
        $this->assertEquals(['author' => 'Test Author'], $result['metadata']);
        $this->assertEquals('user-123', $result['created_by']);
    }

    public function test_includes_processing_progress_when_processing(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('processing_progress', $result);
    }

    public function test_includes_processing_progress_when_failed(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'failed',
            'processing_error' => 'Failed to process PDF',
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('processing_progress', $result);
        $this->assertArrayHasKey('processing_error', $result);
        $this->assertEquals('Failed to process PDF', $result['processing_error']);
    }

    public function test_excludes_processing_progress_when_completed(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'completed',
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('processing_progress', $result);
        $this->assertArrayNotHasKey('processing_error', $result);
    }

    public function test_includes_processing_timestamps(): void
    {
        $startTime = now()->subHours(2);
        $endTime = now()->subHour();
        
        $document = PdfDocument::factory()->create([
            'processing_started_at' => $startTime,
            'processing_completed_at' => $endTime,
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals($startTime->toISOString(), $result['processing_started_at']);
        $this->assertEquals($endTime->toISOString(), $result['processing_completed_at']);
    }

    public function test_handles_null_processing_timestamps(): void
    {
        $document = PdfDocument::factory()->create([
            'processing_started_at' => null,
            'processing_completed_at' => null,
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertNull($result['processing_started_at']);
        $this->assertNull($result['processing_completed_at']);
    }

    public function test_formats_timestamps_properly(): void
    {
        $document = PdfDocument::factory()->create();

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertIsString($result['created_at']);
        $this->assertIsString($result['updated_at']);
        $this->assertEquals($document->created_at->toISOString(), $result['created_at']);
        $this->assertEquals($document->updated_at->toISOString(), $result['updated_at']);
    }

    public function test_includes_formatted_file_size(): void
    {
        $document = PdfDocument::factory()->create([
            'file_size' => 2097152, // 2MB
        ]);

        $resource = new DocumentResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('formatted_file_size', $result);
        $this->assertEquals($document->formatted_file_size, $result['formatted_file_size']);
    }
}