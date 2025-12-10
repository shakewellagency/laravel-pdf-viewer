<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\PageSearchResource;

beforeEach(function () {
    // Mock the routes that the resource uses
    Route::get('/documents/{document_hash}/pages/{page_number}/thumbnail', function () {
        return response()->json(['url' => 'thumbnail-url']);
    })->name('pdf-viewer.documents.pages.thumbnail');

    Route::get('/documents/{document_hash}/pages/{page_number}', function () {
        return response()->json(['url' => 'page-url']);
    })->name('pdf-viewer.documents.pages.show');
});

it('transforms search page to array', function () {
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

    expect($result['id'])->toBe($page->id);
    expect($result['page_number'])->toBe(3);
    expect($result['content_length'])->toBe($page->content_length);
    expect($result['word_count'])->toBe($page->word_count);
    expect($result['has_thumbnail'])->toBeFalse();

    // Document data
    expect($result['document']['hash'])->toBe('test-search-hash');
    expect($result['document']['title'])->toBe('Search Document');
    expect($result['document']['filename'])->toBe('search-doc.pdf');

    // URLs
    expect($result)->toHaveKey('page_url');
});

it('includes relevance score when present', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $page->relevance_score = 0.7654321;

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('relevance_score');
    expect($result['relevance_score'])->toBe(0.7654);
});

it('excludes relevance score when not present', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    // Laravel's when() method will include the key but set it to null when condition is false
    expect($result)->not->toHaveKey('relevance_score');
});

it('includes search snippet when present', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $page->search_snippet = '...found aviation safety protocols...';

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('search_snippet');
    expect($result['search_snippet'])->toBe('...found aviation safety protocols...');
});

it('excludes search snippet when not present', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('search_snippet');
});

it('includes highlighted content when present and highlighting enabled', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $page->highlighted_content = 'Content with <mark>highlighted</mark> terms';

    $request = new Request(['highlight' => true]);
    $resource = new PageSearchResource($page);

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('highlighted_content');
    expect($result['highlighted_content'])->toBe('Content with <mark>highlighted</mark> terms');
});

it('excludes highlighted content when highlighting disabled', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $page->highlighted_content = 'Content with <mark>highlighted</mark> terms';

    $request = new Request(['highlight' => false]);
    $resource = new PageSearchResource($page);

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('highlighted_content');
});

it('includes full content when requested', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'content' => 'Full page content for search results',
    ]);

    $request = new Request(['include_full_content' => true]);
    $resource = new PageSearchResource($page);

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('content');
    expect($result['content'])->toBe('Full page content for search results');
});

it('excludes full content by default', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'content' => 'Full page content for search results',
    ]);

    $request = new Request();
    $resource = new PageSearchResource($page);

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('content');
});

it('includes thumbnail url when has thumbnail', function () {
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

    expect($result)->toHaveKey('thumbnail_url');
    expect($result['has_thumbnail'])->toBeTrue();
});

it('excludes thumbnail url when no thumbnail', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('thumbnail_url');
    expect($result['has_thumbnail'])->toBeFalse();
});

it('formats timestamps properly', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    $resource = new PageSearchResource($page);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['created_at'])->toBeString();
    expect($result['updated_at'])->toBeString();
    expect($result['created_at'])->toStartWith($page->created_at->format('Y-m-d\TH:i:s'));
    expect($result['updated_at'])->toStartWith($page->updated_at->format('Y-m-d\TH:i:s'));
});

it('handles complex search scenario', function () {
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
        'include_full_content' => true,
    ]);
    $resource = new PageSearchResource($page);

    $result = $resource->toArray($request);

    expect($result['relevance_score'])->toBe(0.89);
    expect($result['search_snippet'])->toBe('...Aviation safety protocols...');
    expect($result['highlighted_content'])->toBe('<mark>Aviation</mark> safety protocols and emergency procedures.');
    expect($result['content'])->toBe('Aviation safety protocols and emergency procedures.');
    expect($result['document']['title'])->toBe('Aviation Safety Manual');
});
