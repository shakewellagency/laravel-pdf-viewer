<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Resources;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentSearchResource;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentSearchResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transforms_search_result_to_array(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Search Test Document',
            'original_filename' => 'search-test.pdf',
            'file_size' => 2048,
            'page_count' => 10,
            'status' => 'completed',
            'is_searchable' => true,
            'metadata' => ['author' => 'Search Author'],
        ]);

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals($document->id, $result['id']);
        $this->assertEquals($document->hash, $result['hash']);
        $this->assertEquals('Search Test Document', $result['title']);
        $this->assertEquals('search-test.pdf', $result['filename']);
        $this->assertEquals(2048, $result['file_size']);
        $this->assertEquals(10, $result['page_count']);
        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['is_searchable']);
        $this->assertEquals(['author' => 'Search Author'], $result['metadata']);
    }

    public function test_includes_relevance_score_when_present(): void
    {
        $document = PdfDocument::factory()->create();
        $document->relevance_score = 0.8765;

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('relevance_score', $result);
        $this->assertEquals(0.8765, $result['relevance_score']);
    }

    public function test_rounds_relevance_score_to_four_decimals(): void
    {
        $document = PdfDocument::factory()->create();
        $document->relevance_score = 0.876543;

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals(0.8765, $result['relevance_score']);
    }

    public function test_excludes_relevance_score_when_not_present(): void
    {
        $document = PdfDocument::factory()->create();

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('relevance_score', $result);
    }

    public function test_includes_search_snippets_when_present(): void
    {
        $document = PdfDocument::factory()->create();
        $document->search_snippets = [
            'Page 1: ...found aviation safety...',
            'Page 3: ...aviation regulations...'
        ];

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('search_snippets', $result);
        $this->assertEquals([
            'Page 1: ...found aviation safety...',
            'Page 3: ...aviation regulations...'
        ], $result['search_snippets']);
    }

    public function test_excludes_search_snippets_when_not_present(): void
    {
        $document = PdfDocument::factory()->create();

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('search_snippets', $result);
    }

    public function test_includes_matching_pages_when_pages_loaded(): void
    {
        $document = PdfDocument::factory()
            ->has(PdfDocumentPage::factory()->count(3), 'pages')
            ->create();

        // Load the relationship
        $document->load('pages');

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('matching_pages', $result);
        $this->assertEquals(3, $result['matching_pages']);
    }

    public function test_excludes_matching_pages_when_pages_not_loaded(): void
    {
        $document = PdfDocument::factory()->create();

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('matching_pages', $result);
    }

    public function test_formats_timestamps_properly(): void
    {
        $document = PdfDocument::factory()->create();

        $resource = new DocumentSearchResource($document);
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
            'file_size' => 1048576, // 1MB
        ]);

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('formatted_file_size', $result);
        $this->assertEquals($document->formatted_file_size, $result['formatted_file_size']);
    }

    public function test_handles_complex_search_data(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Aviation Manual',
            'is_searchable' => true,
        ]);
        
        // Simulate search result data
        $document->relevance_score = 0.95;
        $document->search_snippets = [
            'Page 5: ...aircraft maintenance procedures...',
            'Page 12: ...safety protocols for aviation...',
            'Page 18: ...flight operations manual...'
        ];
        
        $document->load('pages');

        $resource = new DocumentSearchResource($document);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertTrue($result['is_searchable']);
        $this->assertEquals(0.95, $result['relevance_score']);
        $this->assertCount(3, $result['search_snippets']);
        $this->assertArrayHasKey('matching_pages', $result);
    }
}