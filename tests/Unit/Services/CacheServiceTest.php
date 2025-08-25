<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\CacheService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class CacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheService = new CacheService();
        
        // Set up cache configuration for testing
        Config::set('pdf-viewer.cache.enabled', true);
        Config::set('pdf-viewer.cache.store', 'array');
        Config::set('pdf-viewer.cache.prefix', 'test_pdf_viewer');
        Config::set('pdf-viewer.cache.ttl.document_metadata', 3600);
        Config::set('pdf-viewer.cache.ttl.page_content', 7200);
        Config::set('pdf-viewer.cache.ttl.search_results', 1800);
    }

    public function test_can_cache_document_metadata(): void
    {
        $documentHash = 'test-document-hash';
        $metadata = [
            'title' => 'Test Document',
            'page_count' => 10,
            'file_size' => 1024,
        ];

        $result = $this->cacheService->cacheDocumentMetadata($documentHash, $metadata);

        $this->assertTrue($result);
    }

    public function test_can_retrieve_cached_document_metadata(): void
    {
        $documentHash = 'test-document-hash';
        $metadata = [
            'title' => 'Test Document',
            'page_count' => 10,
            'file_size' => 1024,
        ];

        $this->cacheService->cacheDocumentMetadata($documentHash, $metadata);
        $cached = $this->cacheService->getCachedDocumentMetadata($documentHash);

        $this->assertEquals($metadata, $cached);
    }

    public function test_returns_null_when_document_metadata_not_cached(): void
    {
        $cached = $this->cacheService->getCachedDocumentMetadata('non-existent-hash');

        $this->assertNull($cached);
    }

    public function test_can_cache_page_content(): void
    {
        $documentHash = 'test-document-hash';
        $pageNumber = 1;
        $content = [
            'content' => 'Page content text',
            'word_count' => 15,
        ];

        $result = $this->cacheService->cachePageContent($documentHash, $pageNumber, $content);

        $this->assertTrue($result);
    }

    public function test_can_retrieve_cached_page_content(): void
    {
        $documentHash = 'test-document-hash';
        $pageNumber = 1;
        $content = [
            'content' => 'Page content text',
            'word_count' => 15,
        ];

        $this->cacheService->cachePageContent($documentHash, $pageNumber, $content);
        $cached = $this->cacheService->getCachedPageContent($documentHash, $pageNumber);

        $this->assertEquals($content, $cached);
    }

    public function test_returns_null_when_page_content_not_cached(): void
    {
        $cached = $this->cacheService->getCachedPageContent('non-existent-hash', 1);

        $this->assertNull($cached);
    }

    public function test_can_cache_search_results(): void
    {
        $queryHash = 'search-query-hash';
        $results = [
            'total' => 5,
            'results' => ['doc1', 'doc2'],
        ];

        $result = $this->cacheService->cacheSearchResults($queryHash, $results);

        $this->assertTrue($result);
    }

    public function test_can_retrieve_cached_search_results(): void
    {
        $queryHash = 'search-query-hash';
        $results = [
            'total' => 5,
            'results' => ['doc1', 'doc2'],
        ];

        $this->cacheService->cacheSearchResults($queryHash, $results);
        $cached = $this->cacheService->getCachedSearchResults($queryHash);

        $this->assertEquals($results, $cached);
    }

    public function test_returns_null_when_search_results_not_cached(): void
    {
        $cached = $this->cacheService->getCachedSearchResults('non-existent-query');

        $this->assertNull($cached);
    }

    public function test_cache_disabled_returns_false(): void
    {
        Config::set('pdf-viewer.cache.enabled', false);

        $result = $this->cacheService->cacheDocumentMetadata('hash', ['test' => 'data']);

        $this->assertFalse($result);
    }

    public function test_cache_disabled_returns_null_for_retrieval(): void
    {
        Config::set('pdf-viewer.cache.enabled', false);

        $cached = $this->cacheService->getCachedDocumentMetadata('hash');

        $this->assertNull($cached);
    }

    public function test_can_invalidate_document_cache(): void
    {
        $documentHash = 'test-document-hash';
        
        // Cache some data first
        $this->cacheService->cacheDocumentMetadata($documentHash, ['test' => 'data']);
        $this->cacheService->cachePageContent($documentHash, 1, ['content' => 'test']);

        // Verify it's cached
        $this->assertNotNull($this->cacheService->getCachedDocumentMetadata($documentHash));

        // Invalidate
        $result = $this->cacheService->invalidateDocumentCache($documentHash);

        $this->assertTrue($result);
    }

    public function test_can_invalidate_search_cache(): void
    {
        // Cache some search results
        $this->cacheService->cacheSearchResults('query1', ['results' => 'test']);

        $result = $this->cacheService->invalidateSearchCache();

        $this->assertTrue($result);
    }

    public function test_can_warm_document_cache(): void
    {
        $document = PdfDocument::factory()->create([
            'page_count' => 3,
        ]);

        // Create some completed pages
        PdfDocumentPage::factory()->count(2)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
            'content' => 'Test page content',
        ]);

        $result = $this->cacheService->warmDocumentCache($document->hash);

        $this->assertTrue($result);
        
        // Verify metadata is cached
        $cached = $this->cacheService->getCachedDocumentMetadata($document->hash);
        $this->assertNotNull($cached);
        $this->assertEquals($document->title, $cached['title']);
    }

    public function test_warm_cache_returns_false_for_nonexistent_document(): void
    {
        $result = $this->cacheService->warmDocumentCache('non-existent-hash');

        $this->assertFalse($result);
    }

    public function test_can_clear_all_cache(): void
    {
        // Cache some data
        $this->cacheService->cacheDocumentMetadata('hash1', ['test' => 'data']);
        $this->cacheService->cacheSearchResults('query1', ['results' => 'test']);

        $result = $this->cacheService->clearAllCache();

        $this->assertTrue($result);
    }

    public function test_can_get_cache_stats(): void
    {
        $stats = $this->cacheService->getCacheStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('cache_enabled', $stats);
        $this->assertArrayHasKey('cache_store', $stats);
        $this->assertArrayHasKey('tags_supported', $stats);
        $this->assertArrayHasKey('prefix', $stats);
        $this->assertTrue($stats['cache_enabled']);
        $this->assertEquals('array', $stats['cache_store']);
        $this->assertFalse($stats['tags_supported']); // Array driver doesn't support tags
    }

    public function test_generates_consistent_cache_keys(): void
    {
        $key1 = $this->cacheService->generateCacheKey('test', ['param1' => 'value1', 'param2' => 'value2']);
        $key2 = $this->cacheService->generateCacheKey('test', ['param2' => 'value2', 'param1' => 'value1']);

        $this->assertEquals($key1, $key2);
    }

    public function test_generates_different_cache_keys_for_different_params(): void
    {
        $key1 = $this->cacheService->generateCacheKey('test', ['param1' => 'value1']);
        $key2 = $this->cacheService->generateCacheKey('test', ['param1' => 'value2']);

        $this->assertNotEquals($key1, $key2);
    }

    public function test_cache_key_includes_prefix(): void
    {
        $key = $this->cacheService->generateCacheKey('test', ['param' => 'value']);

        $this->assertStringContainsString('test_pdf_viewer:', $key);
    }

    public function test_supports_tags_returns_false_for_array_driver(): void
    {
        Config::set('pdf-viewer.cache.store', 'array');
        $service = new CacheService();
        
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('supportsTags');
        $method->setAccessible(true);
        
        $result = $method->invoke($service);

        $this->assertFalse($result);
    }

    public function test_supports_tags_returns_true_for_redis_driver(): void
    {
        Config::set('pdf-viewer.cache.store', 'redis');
        $service = new CacheService();
        
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('supportsTags');
        $method->setAccessible(true);
        
        $result = $method->invoke($service);

        $this->assertTrue($result);
    }

    public function test_caching_with_custom_ttl(): void
    {
        $documentHash = 'test-hash';
        $metadata = ['test' => 'data'];
        $customTtl = 1200;

        $result = $this->cacheService->cacheDocumentMetadata($documentHash, $metadata, $customTtl);

        $this->assertTrue($result);
        
        // Verify it's cached
        $cached = $this->cacheService->getCachedDocumentMetadata($documentHash);
        $this->assertEquals($metadata, $cached);
    }

    public function test_handles_cache_exceptions_gracefully(): void
    {
        // Mock Cache to throw exception
        Cache::shouldReceive('store')
            ->andThrow(new \Exception('Cache error'));

        $result = $this->cacheService->cacheDocumentMetadata('hash', ['test' => 'data']);

        $this->assertFalse($result);
    }

    public function test_handles_retrieval_exceptions_gracefully(): void
    {
        // First cache some data
        $this->cacheService->cacheDocumentMetadata('hash', ['test' => 'data']);

        // Mock Cache to throw exception on retrieval
        Cache::shouldReceive('store')
            ->andThrow(new \Exception('Cache retrieval error'));

        $result = $this->cacheService->getCachedDocumentMetadata('hash');

        $this->assertNull($result);
    }
}