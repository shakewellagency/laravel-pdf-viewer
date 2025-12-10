<?php

use Illuminate\Support\Facades\Cache;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\CacheService;

beforeEach(function () {
    $this->cacheService = new CacheService;
    Cache::flush();
});

afterEach(function () {
    Cache::flush();
});

it('caches document metadata stores data', function () {
    $hash = 'test-document-hash';
    $metadata = [
        'id' => 1,
        'title' => 'Test Document',
        'page_count' => 10,
    ];

    $result = $this->cacheService->cacheDocumentMetadata($hash, $metadata);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedDocumentMetadata($hash);
    expect($cached)->toBe($metadata);
});

it('returns null when document metadata not cached', function () {
    $result = $this->cacheService->getCachedDocumentMetadata('non-existent-hash');

    expect($result)->toBeNull();
});

it('caches page content stores data', function () {
    $hash = 'test-document-hash';
    $pageNumber = 1;
    $content = [
        'text' => 'Page content',
        'thumbnail_path' => 'path/to/thumbnail.jpg',
    ];

    $result = $this->cacheService->cachePageContent($hash, $pageNumber, $content);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedPageContent($hash, $pageNumber);
    expect($cached)->toBe($content);
});

it('caches search results stores data', function () {
    $query = 'aviation safety';
    $results = [
        ['id' => 1, 'title' => 'Safety Manual'],
        ['id' => 2, 'title' => 'Aviation Guide'],
    ];

    $result = $this->cacheService->cacheSearchResults($query, $results);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedSearchResults($query);
    expect($cached)->toBe($results);
});

it('invalidates document cache removes all related data', function () {
    // Create a real document so the service can find page count
    $document = PdfDocument::factory()->create([
        'hash' => 'test-document-hash',
        'page_count' => 1,
    ]);
    $hash = $document->hash;

    // Cache some data
    $this->cacheService->cacheDocumentMetadata($hash, ['title' => 'Test']);
    $this->cacheService->cachePageContent($hash, 1, ['text' => 'Content']);

    $result = $this->cacheService->invalidateDocumentCache($hash);

    expect($result)->toBeTrue();

    // Verify data is removed
    expect($this->cacheService->getCachedDocumentMetadata($hash))->toBeNull();
    expect($this->cacheService->getCachedPageContent($hash, 1))->toBeNull();
});

it('warms document cache preloads data', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Test Document',
        'page_count' => 3,
    ]);

    // Create pages with unique page numbers using sequence
    PdfDocumentPage::factory()
        ->sequence(
            ['page_number' => 1],
            ['page_number' => 2],
            ['page_number' => 3],
        )
        ->count(3)
        ->for($document, 'document')
        ->create();

    $result = $this->cacheService->warmDocumentCache($document->hash);

    expect($result)->toBeTrue();

    // Verify metadata is cached
    $cached = $this->cacheService->getCachedDocumentMetadata($document->hash);
    expect($cached)->not->toBeNull();
    expect($cached['title'])->toBe($document->title);
});

it('gets cache stats returns metrics', function () {
    // Add some cache data
    $this->cacheService->cacheDocumentMetadata('hash1', ['title' => 'Doc1']);
    $this->cacheService->cacheDocumentMetadata('hash2', ['title' => 'Doc2']);

    $stats = $this->cacheService->getCacheStats();

    expect($stats)->toBeArray();
    expect($stats)->toHaveKey('total_keys');
    expect($stats)->toHaveKey('memory_usage');
});

it('clears all cache removes all data', function () {
    // Add some cache data
    $this->cacheService->cacheDocumentMetadata('hash1', ['title' => 'Doc1']);
    $this->cacheService->cacheSearchResults('query', ['results']);

    $result = $this->cacheService->clearAllCache();

    expect($result)->toBeTrue();

    // Verify all data is removed
    expect($this->cacheService->getCachedDocumentMetadata('hash1'))->toBeNull();
    expect($this->cacheService->getCachedSearchResults('query'))->toBeNull();
});

it('caches document outline stores data', function () {
    $hash = 'test-document-hash';
    $outline = [
        'data' => [
            ['id' => 'uuid-1', 'title' => 'Chapter 1', 'level' => 0, 'destination_page' => 1],
            ['id' => 'uuid-2', 'title' => 'Chapter 2', 'level' => 0, 'destination_page' => 10],
        ],
        'meta' => [
            'document_hash' => $hash,
            'has_outline' => true,
            'total_entries' => 2,
        ],
    ];

    $result = $this->cacheService->cacheOutline($hash, $outline);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedOutline($hash);
    expect($cached)->toBe($outline);
});

it('returns null when outline not cached', function () {
    $result = $this->cacheService->getCachedOutline('non-existent-hash');

    expect($result)->toBeNull();
});

it('caches document links stores data', function () {
    $hash = 'test-document-hash';
    $links = [
        'data' => [
            'summary' => [
                'total_links' => 10,
                'internal_links' => 7,
                'external_links' => 3,
            ],
            'links_by_page' => [],
        ],
        'meta' => [
            'document_hash' => $hash,
            'has_links' => true,
        ],
    ];

    $result = $this->cacheService->cacheLinks($hash, $links);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedLinks($hash);
    expect($cached)->toBe($links);
});

it('returns null when links not cached', function () {
    $result = $this->cacheService->getCachedLinks('non-existent-hash');

    expect($result)->toBeNull();
});

it('caches page links stores data', function () {
    $hash = 'test-document-hash';
    $pageNumber = 5;
    $pageLinks = [
        'data' => [
            ['id' => 'uuid-1', 'type' => 'internal', 'destination_page' => 10],
            ['id' => 'uuid-2', 'type' => 'external', 'destination_url' => 'https://example.com'],
        ],
        'meta' => [
            'document_hash' => $hash,
            'page_number' => $pageNumber,
            'total_links' => 2,
        ],
    ];

    $result = $this->cacheService->cachePageLinks($hash, $pageNumber, $pageLinks);

    expect($result)->toBeTrue();

    $cached = $this->cacheService->getCachedPageLinks($hash, $pageNumber);
    expect($cached)->toBe($pageLinks);
});

it('returns null when page links not cached', function () {
    $result = $this->cacheService->getCachedPageLinks('non-existent-hash', 1);

    expect($result)->toBeNull();
});

it('caches different pages separately', function () {
    $hash = 'test-document-hash';
    $page1Links = ['data' => [['id' => 'page1-link']], 'meta' => ['page_number' => 1]];
    $page2Links = ['data' => [['id' => 'page2-link']], 'meta' => ['page_number' => 2]];

    $this->cacheService->cachePageLinks($hash, 1, $page1Links);
    $this->cacheService->cachePageLinks($hash, 2, $page2Links);

    $cached1 = $this->cacheService->getCachedPageLinks($hash, 1);
    $cached2 = $this->cacheService->getCachedPageLinks($hash, 2);

    expect($cached1)->toBe($page1Links);
    expect($cached2)->toBe($page2Links);
});

it('respects cache disabled config for outline', function () {
    config(['pdf-viewer.cache.enabled' => false]);

    $result = $this->cacheService->cacheOutline('hash', ['data' => []]);
    expect($result)->toBeFalse();

    $cached = $this->cacheService->getCachedOutline('hash');
    expect($cached)->toBeNull();

    config(['pdf-viewer.cache.enabled' => true]);
});

it('respects cache disabled config for links', function () {
    config(['pdf-viewer.cache.enabled' => false]);

    $result = $this->cacheService->cacheLinks('hash', ['data' => []]);
    expect($result)->toBeFalse();

    $cached = $this->cacheService->getCachedLinks('hash');
    expect($cached)->toBeNull();

    config(['pdf-viewer.cache.enabled' => true]);
});
