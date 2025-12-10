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
