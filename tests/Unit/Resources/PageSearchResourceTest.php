<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Resources;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\PageSearchResource;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PageSearchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the routes that the resource uses
        Route::get('/documents/{document_hash}/pages/{page_number}/thumbnail', function() {
            return response()->json(['url' => 'thumbnail-url']);
        })->name('pdf-viewer.documents.pages.thumbnail');
        
        Route::get('/documents/{document_hash}/pages/{page_number}', function() {
            return response()->json(['url' => 'page-url']);
        })->name('pdf-viewer.documents.pages.show');
    }

    public function test_transforms_search_page_to_array(): void
    {
        $document = PdfDocument::factory()->create([
            'hash' => 'test-search-hash',
            'title' => 'Search Document',
            'original_filename' => 'search-doc.pdf',
        ]);
        
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 3,
            'content' => '<p>This is test content for search results.</p>',
        ]);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertEquals($page->id, $result['id']);
        $this->assertEquals(3, $result['page_number']);
        $this->assertEquals($page->content_length, $result['content_length']);
        $this->assertEquals($page->word_count, $result['word_count']);
        $this->assertFalse($result['has_thumbnail']);
        
        // Document data
        $this->assertEquals('test-search-hash', $result['document']['hash']);
        $this->assertEquals('Search Document', $result['document']['title']);
        $this->assertEquals('search-doc.pdf', $result['document']['filename']);
        
        // URLs
        $this->assertArrayHasKey('page_url', $result);
    }

    public function test_includes_relevance_score_when_present(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);
        
        $page->relevance_score = 0.7654321;

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('relevance_score', $result);
        $this->assertEquals(0.7654, $result['relevance_score']);
    }

    public function test_excludes_relevance_score_when_not_present(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        // Laravel's when() method will include the key but set it to null when condition is false
        $this->assertArrayNotHasKey('relevance_score', $result);
    }

    public function test_includes_search_snippet_when_present(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);
        
        $page->search_snippet = '...found aviation safety protocols...';

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('search_snippet', $result);
        $this->assertEquals('...found aviation safety protocols...', $result['search_snippet']);
    }

    public function test_excludes_search_snippet_when_not_present(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('search_snippet', $result);
    }

    public function test_includes_highlighted_content_when_present_and_highlighting_enabled(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);
        
        $page->highlighted_content = 'Content with <mark>highlighted</mark> terms';

        $request = new Request(['highlight' => true]);
        $resource = new PageSearchResource($page);
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('highlighted_content', $result);
        $this->assertEquals('Content with <mark>highlighted</mark> terms', $result['highlighted_content']);
    }

    public function test_excludes_highlighted_content_when_highlighting_disabled(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);
        
        $page->highlighted_content = 'Content with <mark>highlighted</mark> terms';

        $request = new Request(['highlight' => false]);
        $resource = new PageSearchResource($page);
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('highlighted_content', $result);
    }

    public function test_includes_full_content_when_requested(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'content' => 'Full page content for search results',
        ]);

        $request = new Request(['include_full_content' => true]);
        $resource = new PageSearchResource($page);
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('content', $result);
        $this->assertEquals('Full page content for search results', $result['content']);
    }

    public function test_excludes_full_content_by_default(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'content' => 'Full page content for search results',
        ]);

        $request = new Request();
        $resource = new PageSearchResource($page);
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('content', $result);
    }

    public function test_includes_thumbnail_url_when_has_thumbnail(): void
    {
        $document = PdfDocument::factory()->create([
            'hash' => 'test-hash-123',
        ]);
        
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
        ]);

        // Mock the hasThumbnail method to return true
        $page = \Mockery::mock($page)->makePartial();
        $page->shouldReceive('hasThumbnail')->andReturn(true);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayHasKey('thumbnail_url', $result);
        $this->assertTrue($result['has_thumbnail']);
    }

    public function test_excludes_thumbnail_url_when_no_thumbnail(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertArrayNotHasKey('thumbnail_url', $result);
        $this->assertFalse($result['has_thumbnail']);
    }

    public function test_formats_timestamps_properly(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);

        $resource = new PageSearchResource($page);
        $request = new Request();
        
        $result = $resource->toArray($request);

        $this->assertIsString($result['created_at']);
        $this->assertIsString($result['updated_at']);
        $this->assertStringStartsWith($page->created_at->format('Y-m-d\TH:i:s'), $result['created_at']);
        $this->assertStringStartsWith($page->updated_at->format('Y-m-d\TH:i:s'), $result['updated_at']);
    }

    public function test_handles_complex_search_scenario(): void
    {
        $document = PdfDocument::factory()->create([
            'hash' => 'aviation-doc',
            'title' => 'Aviation Safety Manual',
            'original_filename' => 'aviation-safety.pdf',
        ]);
        
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 15,
            'content' => 'Aviation safety protocols and emergency procedures.',
        ]);
        
        // Simulate search result data
        $page->relevance_score = 0.89;
        $page->search_snippet = '...Aviation safety protocols...';
        $page->highlighted_content = '<mark>Aviation</mark> safety protocols and emergency procedures.';

        $request = new Request([
            'highlight' => true,
            'include_full_content' => true
        ]);
        $resource = new PageSearchResource($page);
        
        $result = $resource->toArray($request);

        $this->assertEquals(0.89, $result['relevance_score']);
        $this->assertEquals('...Aviation safety protocols...', $result['search_snippet']);
        $this->assertEquals('<mark>Aviation</mark> safety protocols and emergency procedures.', $result['highlighted_content']);
        $this->assertEquals('Aviation safety protocols and emergency procedures.', $result['content']);
        $this->assertEquals('Aviation Safety Manual', $result['document']['title']);
    }
}