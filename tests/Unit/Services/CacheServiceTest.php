<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Services\CacheService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class CacheServiceTest extends TestCase
{
    protected CacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = new CacheService();
        Cache::flush();
    }

    public function test_cache_document_metadata_stores_data(): void
    {
        $hash = 'test-document-hash';
        $metadata = [
            'id' => 1,
            'title' => 'Test Document',
            'page_count' => 10,
        ];

        $result = $this->cacheService->cacheDocumentMetadata($hash, $metadata);

        $this->assertTrue($result);
        
        $cached = $this->cacheService->getCachedDocumentMetadata($hash);
        $this->assertEquals($metadata, $cached);
    }

    public function test_get_cached_document_metadata_returns_null_when_not_exists(): void
    {
        $result = $this->cacheService->getCachedDocumentMetadata('non-existent-hash');

        $this->assertNull($result);
    }

    public function test_cache_page_content_stores_data(): void
    {
        $hash = 'test-document-hash';
        $pageNumber = 1;
        $content = [
            'text' => 'Page content',
            'thumbnail_path' => 'path/to/thumbnail.jpg',
        ];

        $result = $this->cacheService->cachePageContent($hash, $pageNumber, $content);

        $this->assertTrue($result);
        
        $cached = $this->cacheService->getCachedPageContent($hash, $pageNumber);
        $this->assertEquals($content, $cached);
    }

    public function test_cache_search_results_stores_data(): void
    {
        $query = 'aviation safety';
        $results = [
            ['id' => 1, 'title' => 'Safety Manual'],
            ['id' => 2, 'title' => 'Aviation Guide'],
        ];

        $result = $this->cacheService->cacheSearchResults($query, $results);

        $this->assertTrue($result);
        
        $cached = $this->cacheService->getCachedSearchResults($query);
        $this->assertEquals($results, $cached);
    }

    public function test_invalidate_document_cache_removes_all_related_data(): void
    {
        $hash = 'test-document-hash';
        
        // Cache some data
        $this->cacheService->cacheDocumentMetadata($hash, ['title' => 'Test']);
        $this->cacheService->cachePageContent($hash, 1, ['text' => 'Content']);

        $result = $this->cacheService->invalidateDocumentCache($hash);

        $this->assertTrue($result);
        
        // Verify data is removed
        $this->assertNull($this->cacheService->getCachedDocumentMetadata($hash));
        $this->assertNull($this->cacheService->getCachedPageContent($hash, 1));
    }

    public function test_warm_document_cache_preloads_data(): void
    {
        $document = PdfDocument::factory()
            ->has(\Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage::class, 3)
            ->create([
                'title' => 'Test Document',
                'page_count' => 3,
            ]);

        $result = $this->cacheService->warmDocumentCache($document->hash);

        $this->assertTrue($result);
        
        // Verify metadata is cached
        $cached = $this->cacheService->getCachedDocumentMetadata($document->hash);
        $this->assertNotNull($cached);
        $this->assertEquals($document->title, $cached['title']);
    }

    public function test_get_cache_stats_returns_metrics(): void
    {
        // Add some cache data
        $this->cacheService->cacheDocumentMetadata('hash1', ['title' => 'Doc1']);
        $this->cacheService->cacheDocumentMetadata('hash2', ['title' => 'Doc2']);

        $stats = $this->cacheService->getCacheStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
    }

    public function test_clear_all_cache_removes_all_data(): void
    {
        // Add some cache data
        $this->cacheService->cacheDocumentMetadata('hash1', ['title' => 'Doc1']);
        $this->cacheService->cacheSearchResults('query', ['results']);

        $result = $this->cacheService->clearAllCache();

        $this->assertTrue($result);
        
        // Verify all data is removed
        $this->assertNull($this->cacheService->getCachedDocumentMetadata('hash1'));
        $this->assertNull($this->cacheService->getCachedSearchResults('query'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}