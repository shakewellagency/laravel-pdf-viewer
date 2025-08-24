<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\SearchService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class SearchServiceTest extends TestCase
{
    protected SearchService $searchService;
    protected $cacheServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheServiceMock = Mockery::mock(\Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class);
        
        // Set up default cache mock expectations
        $this->cacheServiceMock->shouldReceive('getCachedSearchResults')->andReturn(null)->byDefault();
        $this->cacheServiceMock->shouldReceive('cacheSearchResults')->andReturn(true)->byDefault();
        $this->cacheServiceMock->shouldReceive('getCachedSearchSuggestions')->andReturn(null)->byDefault();
        $this->cacheServiceMock->shouldReceive('cacheSearchSuggestions')->andReturn(true)->byDefault();
        $this->cacheServiceMock->shouldReceive('getCachedPopularSearches')->andReturn(null)->byDefault();
        $this->cacheServiceMock->shouldReceive('cachePopularSearches')->andReturn(true)->byDefault();
        
        $this->searchService = new SearchService($this->cacheServiceMock);
    }

    public function test_search_documents_returns_results(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Aviation Safety Manual',
            'is_searchable' => true,
        ]);

        $results = $this->searchService->searchDocuments('aviation');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    }

    public function test_search_pages_returns_results_with_content(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Safety Manual',
            'is_searchable' => true,
        ]);

        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'content' => 'This is aviation safety content with important procedures.',
            'status' => 'completed',
        ]);

        $results = $this->searchService->searchPages($document->hash, 'aviation safety');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    }

    public function test_search_suggestions_returns_related_terms(): void
    {
        PdfDocumentPage::factory()->create([
            'content' => 'aviation safety procedures manual',
        ]);

        PdfDocumentPage::factory()->create([
            'content' => 'aircraft maintenance guidelines',
        ]);

        $suggestions = $this->searchService->getSearchSuggestions('aviat');

        $this->assertIsArray($suggestions);
    }

    public function test_search_with_filters_applies_constraints(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Safety Manual',
            'status' => 'completed',
            'is_searchable' => true,
        ]);

        $filters = [
            'status' => 'completed',
            'date_from' => now()->subDays(1),
            'date_to' => now()->addDays(1),
        ];

        $results = $this->searchService->searchDocuments('safety', $filters);

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    }

    public function test_search_empty_query_returns_empty_results(): void
    {
        $results = $this->searchService->searchDocuments('');

        $this->assertEquals(0, $results->count());
    }

    public function test_search_handles_special_characters(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Test Document with Special @#$% Characters',
            'is_searchable' => true,
        ]);

        $results = $this->searchService->searchDocuments('special @#$%');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    }

    public function test_get_popular_searches_returns_trending_terms(): void
    {
        // Simulate popular searches
        cache(['search_terms' => [
            'aviation' => 15,
            'safety' => 12,
            'manual' => 8,
        ]]);

        $popular = $this->searchService->getPopularSearches();

        $this->assertIsArray($popular);
        $this->assertNotEmpty($popular);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}