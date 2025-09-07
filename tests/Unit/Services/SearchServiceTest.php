<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfPageContent;
use Shakewellagency\LaravelPdfViewer\Services\SearchService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class SearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SearchService $searchService;

    protected CacheServiceInterface $cacheService;

    protected PdfDocument $document;

    protected PdfDocumentPage $page1;

    protected PdfDocumentPage $page2;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock cache service
        $this->cacheService = Mockery::mock(CacheServiceInterface::class);
        $this->cacheService->shouldReceive('getCachedSearchResults')->andReturn(null)->byDefault();
        $this->cacheService->shouldReceive('cacheSearchResults')->andReturn(true)->byDefault();

        $this->searchService = new SearchService($this->cacheService);

        // Create test document
        $this->document = PdfDocument::create([
            'hash' => 'test-document-123',
            'title' => 'Test Document',
            'filename' => 'test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024000,
            'file_path' => 'pdf-documents/test.pdf',
            'page_count' => 2,
            'status' => 'completed',
            'is_searchable' => true,
        ]);

        // Create test pages (without content - it's in a separate table now)
        $this->page1 = PdfDocumentPage::create([
            'pdf_document_id' => $this->document->id,
            'page_number' => 1,
            'status' => 'completed',
            'is_parsed' => true,
        ]);

        $this->page2 = PdfDocumentPage::create([
            'pdf_document_id' => $this->document->id,
            'page_number' => 2,
            'status' => 'completed',
            'is_parsed' => true,
        ]);

        // Create page content in the separate content table
        PdfPageContent::createOrUpdateForPage(
            $this->page1,
            'This is the first page with important information about fire safety procedures. The NCC requires proper documentation of all emergency exits and fire extinguisher locations.'
        );

        PdfPageContent::createOrUpdateForPage(
            $this->page2,
            'The second page contains additional details about building codes and compliance requirements. Emergency procedures must be clearly visible near all exit doors.'
        );
    }

    /** @test */
    public function it_can_search_documents_with_fulltext_query()
    {
        // Skip if SQLite doesn't support fulltext
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        // Enable full-text search for testing (on the new content table)
        DB::statement('ALTER TABLE pdf_page_content ADD FULLTEXT(content)');

        $results = $this->searchService->searchDocuments('fire safety');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertGreaterThan(0, $results->total());

        $firstResult = $results->items()[0];
        $this->assertEquals($this->document->id, $firstResult->id);
        $this->assertNotNull($firstResult->search_snippets);
        $this->assertNotNull($firstResult->relevance_score);
    }

    /** @test */
    public function it_returns_empty_results_for_short_queries()
    {
        $results = $this->searchService->searchDocuments('fi');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertEquals(0, $results->total());
        $this->assertCount(0, $results->items());
    }

    /** @test */
    public function it_can_search_pages_within_document()
    {
        // Skip if SQLite doesn't support fulltext
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        // Enable full-text search for testing (on the new content table)
        DB::statement('ALTER TABLE pdf_page_content ADD FULLTEXT(content)');

        $results = $this->searchService->searchPages('test-document-123', 'NCC');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertGreaterThan(0, $results->total());

        $firstResult = $results->items()[0];
        $this->assertEquals($this->page1->id, $firstResult->id);
        $this->assertEquals(1, $firstResult->page_number);
        $this->assertNotNull($firstResult->search_snippet);
        $this->assertNotNull($firstResult->highlighted_content);
        $this->assertNotNull($firstResult->relevance_score);
    }

    /** @test */
    public function it_returns_empty_results_for_non_existent_document()
    {
        $results = $this->searchService->searchPages('non-existent-hash', 'test');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertEquals(0, $results->total());
    }

    /** @test */
    public function it_returns_empty_results_for_non_searchable_document()
    {
        $this->document->update(['is_searchable' => false]);

        $results = $this->searchService->searchPages('test-document-123', 'test');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertEquals(0, $results->total());
    }

    /** @test */
    public function it_can_generate_search_suggestions()
    {
        $suggestions = $this->searchService->getSuggestions('fire');

        $this->assertIsArray($suggestions);
        $this->assertContains('fire', $suggestions);
    }

    /** @test */
    public function it_returns_empty_suggestions_for_short_queries()
    {
        $suggestions = $this->searchService->getSuggestions('f');

        $this->assertIsArray($suggestions);
        $this->assertEmpty($suggestions);
    }

    /** @test */
    public function it_can_highlight_content()
    {
        $content = 'This is a test document with important information.';
        $query = 'test important';

        $highlighted = $this->searchService->highlightContent($content, $query);

        $this->assertStringContainsString('<mark>test</mark>', $highlighted);
        $this->assertStringContainsString('<mark>important</mark>', $highlighted);
    }

    /** @test */
    public function it_handles_empty_content_highlighting()
    {
        $highlighted = $this->searchService->highlightContent('', 'test');

        $this->assertEquals('', $highlighted);
    }

    /** @test */
    public function it_handles_empty_query_highlighting()
    {
        $content = 'This is test content.';
        $highlighted = $this->searchService->highlightContent($content, '');

        $this->assertEquals($content, $highlighted);
    }

    /** @test */
    public function it_can_generate_search_snippets()
    {
        $content = 'This is a very long piece of content that contains the search term fire safety in the middle of the text. The search function should be able to extract a relevant snippet around this term.';
        $query = 'fire safety';

        $snippet = $this->searchService->generateSnippet($content, $query, 100);

        $this->assertStringContainsString('fire safety', $snippet);
        $this->assertLessThanOrEqual(105, strlen($snippet)); // 100 + '...'
        $this->assertTrue(strpos($snippet, 'fire safety') !== false);
    }

    /** @test */
    public function it_handles_snippet_when_query_not_found()
    {
        $content = 'This is some content without the search term.';
        $query = 'missing term';

        $snippet = $this->searchService->generateSnippet($content, $query, 50);

        $this->assertLessThanOrEqual(53, strlen($snippet)); // 50 + '...'
        $this->assertStringStartsWith('This is some', $snippet);
    }

    /** @test */
    public function it_returns_empty_snippet_for_empty_content()
    {
        $snippet = $this->searchService->generateSnippet('', 'test');

        $this->assertEquals('', $snippet);
    }

    /** @test */
    public function it_can_calculate_relevance_score()
    {
        $content = 'fire safety fire safety emergency procedures';
        $query = 'fire safety';

        $relevance = $this->searchService->calculateRelevance($content, $query);

        $this->assertIsFloat($relevance);
        $this->assertGreaterThan(0, $relevance);
        $this->assertLessThanOrEqual(100, $relevance);
    }

    /** @test */
    public function it_returns_zero_relevance_for_empty_content()
    {
        $relevance = $this->searchService->calculateRelevance('', 'test');

        $this->assertEquals(0.0, $relevance);
    }

    /** @test */
    public function it_returns_zero_relevance_for_empty_query()
    {
        $relevance = $this->searchService->calculateRelevance('test content', '');

        $this->assertEquals(0.0, $relevance);
    }

    /** @test */
    public function it_can_index_page_content()
    {
        $newContent = 'Updated content for search indexing';

        $result = $this->searchService->indexPage('test-document-123', 1, $newContent);

        $this->assertTrue($result);

        $this->page1->refresh();
        $this->assertEquals($newContent, $this->page1->content->content);
        $this->assertTrue($this->page1->is_parsed);
        $this->assertEquals('completed', $this->page1->status);
    }

    /** @test */
    public function it_returns_false_when_indexing_non_existent_document()
    {
        $result = $this->searchService->indexPage('non-existent-hash', 1, 'test content');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_when_indexing_non_existent_page()
    {
        $result = $this->searchService->indexPage('test-document-123', 99, 'test content');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_can_remove_document_from_index()
    {
        $result = $this->searchService->removeFromIndex('test-document-123');

        $this->assertTrue($result);

        $this->document->refresh();
        $this->assertFalse($this->document->is_searchable);

        $this->page1->refresh();
        $this->page2->refresh();
        $this->assertNull($this->page1->content);
        $this->assertNull($this->page2->content);
        $this->assertFalse($this->page1->is_parsed);
        $this->assertFalse($this->page2->is_parsed);
    }

    /** @test */
    public function it_returns_false_when_removing_non_existent_document()
    {
        $result = $this->searchService->removeFromIndex('non-existent-hash');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_can_rebuild_search_index()
    {
        Log::spy();

        $result = $this->searchService->rebuildIndex();

        $this->assertTrue($result);

        $this->document->refresh();
        $this->assertFalse($this->document->is_searchable);

        Log::shouldHaveReceived('info')
            ->with('Search index rebuild initiated')
            ->once();
    }

    /** @test */
    public function it_can_get_search_statistics()
    {
        $stats = $this->searchService->getSearchStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_documents', $stats);
        $this->assertArrayHasKey('searchable_documents', $stats);
        $this->assertArrayHasKey('total_pages', $stats);
        $this->assertArrayHasKey('indexed_pages', $stats);
        $this->assertArrayHasKey('total_content_size', $stats);

        $this->assertEquals(1, $stats['total_documents']);
        $this->assertEquals(1, $stats['searchable_documents']);
        $this->assertEquals(2, $stats['total_pages']);
        $this->assertEquals(2, $stats['indexed_pages']);
        $this->assertGreaterThan(0, $stats['total_content_size']);
    }

    /** @test */
    public function it_sanitizes_search_queries()
    {
        // Test with malicious content
        $maliciousQuery = '<script>alert("xss")</script>fire safety';

        $reflection = new \ReflectionClass($this->searchService);
        $method = $reflection->getMethod('sanitizeQuery');
        $method->setAccessible(true);

        $sanitized = $method->invoke($this->searchService, $maliciousQuery);

        $this->assertStringNotContains('<script>', $sanitized);
        $this->assertStringNotContains('alert', $sanitized);
        $this->assertStringContains('fire safety', $sanitized);
    }

    /** @test */
    public function it_limits_query_length()
    {
        config(['pdf-viewer.search.max_query_length' => 10]);

        $longQuery = str_repeat('a', 20);

        $reflection = new \ReflectionClass($this->searchService);
        $method = $reflection->getMethod('sanitizeQuery');
        $method->setAccessible(true);

        $sanitized = $method->invoke($this->searchService, $longQuery);

        $this->assertEquals(10, strlen($sanitized));
    }

    /** @test */
    public function it_applies_search_filters()
    {
        // Skip if SQLite doesn't support fulltext
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        // Create additional document with different status
        $document2 = PdfDocument::create([
            'hash' => 'test-document-456',
            'title' => 'Test Document 2',
            'filename' => 'test2.pdf',
            'original_filename' => 'test2.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 512000,
            'file_path' => 'pdf-documents/test2.pdf',
            'page_count' => 1,
            'status' => 'processing',
            'is_searchable' => true,
        ]);

        // Enable full-text search for testing (on the new content table)
        DB::statement('ALTER TABLE pdf_page_content ADD FULLTEXT(content)');

        $filters = ['status' => 'completed'];
        $results = $this->searchService->searchDocuments('test', $filters);

        // Should only return completed documents
        $this->assertEquals(1, $results->total());
        $this->assertEquals('completed', $results->items()[0]->status);
    }

    /** @test */
    public function it_uses_cached_search_results_when_available()
    {
        $cachedData = [
            'data' => [['id' => 1, 'title' => 'Cached Result']],
            'total' => 1,
            'current_page' => 1,
        ];

        $this->cacheService->shouldReceive('getCachedSearchResults')
            ->once()
            ->andReturn($cachedData);

        $results = $this->searchService->searchDocuments('test query');

        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertEquals(1, $results->total());
    }

    /** @test */
    public function it_caches_search_results_after_execution()
    {
        // Skip if SQLite doesn't support fulltext
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        // Enable full-text search for testing (on the new content table)
        DB::statement('ALTER TABLE pdf_page_content ADD FULLTEXT(content)');

        $this->cacheService->shouldReceive('cacheSearchResults')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $this->searchService->searchDocuments('fire safety');
    }

    /** @test */
    public function it_logs_search_activity_when_enabled()
    {
        // Skip if SQLite doesn't support fulltext
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        config(['pdf-viewer.monitoring.log_search' => true]);
        Log::spy();

        // Enable full-text search for testing (on the new content table)
        DB::statement('ALTER TABLE pdf_page_content ADD FULLTEXT(content)');

        $this->searchService->searchDocuments('fire safety');

        Log::shouldHaveReceived('info')
            ->with('Document search performed', Mockery::type('array'))
            ->once();
    }

    /** @test */
    public function it_generates_document_snippets_from_pages()
    {
        $reflection = new \ReflectionClass($this->searchService);
        $method = $reflection->getMethod('generateDocumentSnippets');
        $method->setAccessible(true);

        $snippets = $method->invoke($this->searchService, $this->document, 'fire safety');

        $this->assertIsArray($snippets);
        $this->assertNotEmpty($snippets);
        $this->assertArrayHasKey('page_number', $snippets[0]);
        $this->assertArrayHasKey('snippet', $snippets[0]);
    }

    /** @test */
    public function it_handles_search_with_mysql_fulltext_features()
    {
        // Skip if MySQL doesn't support fulltext (like in memory SQLite)
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Fulltext search not available in SQLite');
        }

        // Enable full-text search
        DB::statement('ALTER TABLE pdf_document_pages ADD FULLTEXT(content)');

        // Test boolean mode search
        $results = $this->searchService->searchPages('test-document-123', '+fire +safety');
        $this->assertInstanceOf(LengthAwarePaginator::class, $results);

        // Test natural language mode
        $results = $this->searchService->searchDocuments('emergency procedures');
        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    }

    protected function tearDown(): void
    {
        // Clean up full-text indexes if they were added
        try {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE pdf_page_content DROP INDEX content');
            }
        } catch (\Exception $e) {
            // Ignore if index doesn't exist
        }

        parent::tearDown();
    }
}
