<?php

use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentSearchResource;

it('transforms search result to array', function () {
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

    expect($result['id'])->toBe($document->id);
    expect($result['hash'])->toBe($document->hash);
    expect($result['title'])->toBe('Search Test Document');
    expect($result['filename'])->toBe('search-test.pdf');
    expect($result['file_size'])->toBe(2048);
    expect($result['page_count'])->toBe(10);
    expect($result['status'])->toBe('completed');
    expect($result['is_searchable'])->toBeTrue();
    expect($result['metadata'])->toBe(['author' => 'Search Author']);
});

it('includes relevance score when present', function () {
    $document = PdfDocument::factory()->create();
    $document->relevance_score = 0.8765;

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('relevance_score');
    expect($result['relevance_score'])->toBe(0.8765);
});

it('rounds relevance score to four decimals', function () {
    $document = PdfDocument::factory()->create();
    $document->relevance_score = 0.876543;

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['relevance_score'])->toBe(0.8765);
});

it('excludes relevance score when not present', function () {
    $document = PdfDocument::factory()->create();

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('relevance_score');
});

it('includes search snippets when present', function () {
    $document = PdfDocument::factory()->create();
    $document->search_snippets = [
        'Page 1: ...found aviation safety...',
        'Page 3: ...aviation regulations...',
    ];

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('search_snippets');
    expect($result['search_snippets'])->toBe([
        'Page 1: ...found aviation safety...',
        'Page 3: ...aviation regulations...',
    ]);
});

it('excludes search snippets when not present', function () {
    $document = PdfDocument::factory()->create();

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('search_snippets');
});

it('includes matching pages when pages loaded', function () {
    $document = PdfDocument::factory()
        ->has(PdfDocumentPage::factory()->count(3), 'pages')
        ->create();

    // Load the relationship
    $document->load('pages');

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('matching_pages');
    expect($result['matching_pages'])->toBe(3);
});

it('excludes matching pages when pages not loaded', function () {
    $document = PdfDocument::factory()->create();

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('matching_pages');
});

it('formats timestamps properly', function () {
    $document = PdfDocument::factory()->create();

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['created_at'])->toBeString();
    expect($result['updated_at'])->toBeString();
    expect($result['created_at'])->toStartWith($document->created_at->format('Y-m-d\TH:i:s'));
    expect($result['updated_at'])->toStartWith($document->updated_at->format('Y-m-d\TH:i:s'));
});

it('includes formatted file size', function () {
    $document = PdfDocument::factory()->create([
        'file_size' => 1048576, // 1MB
    ]);

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('formatted_file_size');
    expect($result['formatted_file_size'])->toBe($document->formatted_file_size);
});

it('handles complex search data', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Aviation Manual',
        'is_searchable' => true,
    ]);

    // Simulate search result data
    $document->relevance_score = 0.95;
    $document->search_snippets = [
        'Page 5: ...aircraft maintenance procedures...',
        'Page 12: ...safety protocols for aviation...',
        'Page 18: ...flight operations manual...',
    ];

    $document->load('pages');

    $resource = new DocumentSearchResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['is_searchable'])->toBeTrue();
    expect($result['relevance_score'])->toBe(0.95);
    expect($result['search_snippets'])->toHaveCount(3);
    expect($result)->toHaveKey('matching_pages');
});
