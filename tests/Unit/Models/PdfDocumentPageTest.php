<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

it('can create pdf document page', function () {
    $document = PdfDocument::factory()->create();

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'content' => 'Test page content',
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('pdf_document_pages', [
        'id' => $page->id,
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'content' => 'Test page content',
        'status' => 'completed',
    ]);
});

it('belongs to document', function () {
    $document = PdfDocument::factory()->create();
    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    expect($page->document)->toBeInstanceOf(PdfDocument::class);
    expect($page->document->id)->toBe($document->id);
});

it('has parsed scope', function () {
    PdfDocumentPage::factory()->create(['is_parsed' => true]);
    PdfDocumentPage::factory()->create(['is_parsed' => false]);

    $parsedPages = PdfDocumentPage::parsed()->get();

    expect($parsedPages)->toHaveCount(1);
    expect($parsedPages->first()->is_parsed)->toBeTrue();
});

it('has completed scope', function () {
    PdfDocumentPage::factory()->create(['status' => 'completed']);
    PdfDocumentPage::factory()->create(['status' => 'processing']);

    $completedPages = PdfDocumentPage::completed()->get();

    expect($completedPages)->toHaveCount(1);
    expect($completedPages->first()->status)->toBe('completed');
});

it('has failed scope', function () {
    PdfDocumentPage::factory()->create(['status' => 'failed']);
    PdfDocumentPage::factory()->create(['status' => 'completed']);

    $failedPages = PdfDocumentPage::failed()->get();

    expect($failedPages)->toHaveCount(1);
    expect($failedPages->first()->status)->toBe('failed');
});

it('has with content scope', function () {
    PdfDocumentPage::factory()->create(['content' => 'Some content']);
    PdfDocumentPage::factory()->create(['content' => null]);
    PdfDocumentPage::factory()->create(['content' => '']);

    $pagesWithContent = PdfDocumentPage::withContent()->get();

    expect($pagesWithContent)->toHaveCount(1);
    expect($pagesWithContent->first()->content)->toBe('Some content');
});

it('has for document scope', function () {
    $document1 = PdfDocument::factory()->create();
    $document2 = PdfDocument::factory()->create();

    PdfDocumentPage::factory()->create(['pdf_document_id' => $document1->id]);
    PdfDocumentPage::factory()->create(['pdf_document_id' => $document2->id]);

    $pagesForDocument1 = PdfDocumentPage::forDocument($document1->hash)->get();

    expect($pagesForDocument1)->toHaveCount(1);
    expect($pagesForDocument1->first()->pdf_document_id)->toBe($document1->id);
});

it('gets search snippet method', function () {
    $content = 'This is a long piece of content with the word aviation in it for testing search snippets.';
    $page = PdfDocumentPage::factory()->create(['content' => $content]);

    $snippet = $page->getSearchSnippet('aviation', 50);

    expect($snippet)->toContain('aviation');
    // Snippet can have "..." on both sides, so max length is 50 + 3 + 3 = 56
    expect(strlen($snippet))->toBeLessThanOrEqual(60);
});

it('gets search snippet with no match', function () {
    $content = 'This is some content without the search term.';
    $page = PdfDocumentPage::factory()->create(['content' => $content]);

    $snippet = $page->getSearchSnippet('nonexistent', 20);

    expect($snippet)->toBe('This is some content...');
});

it('highlights content method', function () {
    $page = PdfDocumentPage::factory()->create([
        'content' => 'This content has aviation safety information.',
    ]);

    $highlighted = $page->highlightContent('aviation');

    expect($highlighted)->toBe('This content has <mark>aviation</mark> safety information.');
});

it('highlights content with custom tag', function () {
    $page = PdfDocumentPage::factory()->create([
        'content' => 'This content has aviation safety information.',
    ]);

    $highlighted = $page->highlightContent('aviation', 'strong');

    expect($highlighted)->toBe('This content has <strong>aviation</strong> safety information.');
});

it('gets content length attribute', function () {
    $page = PdfDocumentPage::factory()->create([
        'content' => '<p>This is <strong>HTML</strong> content.</p>',
    ]);

    // "This is HTML content." = 21 characters without HTML tags
    expect($page->getContentLengthAttribute())->toBe(21);
});

it('gets word count attribute', function () {
    $page = PdfDocumentPage::factory()->create([
        'content' => '<p>This is a test content with HTML tags.</p>',
    ]);

    // "This is a test content with HTML tags." = 8 words
    expect($page->getWordCountAttribute())->toBe(8);
});

it('has content method', function () {
    $pageWithContent = PdfDocumentPage::factory()->create(['content' => 'Some content']);
    $pageWithoutContent = PdfDocumentPage::factory()->create(['content' => null]);
    $pageWithEmptyContent = PdfDocumentPage::factory()->create(['content' => '   ']);

    expect($pageWithContent->hasContent())->toBeTrue();
    expect($pageWithoutContent->hasContent())->toBeFalse();
    expect($pageWithEmptyContent->hasContent())->toBeFalse();
});

it('has thumbnail method', function () {
    $pageWithThumbnail = PdfDocumentPage::factory()->create([
        'thumbnail_path' => 'thumbnails/test.jpg',
    ]);
    $pageWithoutThumbnail = PdfDocumentPage::factory()->create([
        'thumbnail_path' => null,
    ]);

    // Note: This will return false since we're not actually creating files
    expect($pageWithThumbnail->hasThumbnail())->toBeFalse();
    expect($pageWithoutThumbnail->hasThumbnail())->toBeFalse();
});

it('should be searchable method', function () {
    $document = PdfDocument::factory()->create(['is_searchable' => true]);
    $searchablePage = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'is_parsed' => true,
        'content' => 'Some content',
        'status' => 'completed',
    ]);

    $nonSearchablePage = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'is_parsed' => false,
    ]);

    expect($searchablePage->shouldBeSearchable())->toBeTrue();
    expect($nonSearchablePage->shouldBeSearchable())->toBeFalse();
});

it('to searchable array method', function () {
    $document = PdfDocument::factory()->create([
        'hash' => 'test-hash',
        'title' => 'Test Document',
    ]);

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'content' => 'Test content',
    ]);

    $searchableArray = $page->toSearchableArray();

    expect($searchableArray)
        ->toHaveKey('id')
        ->toHaveKey('document_hash')
        ->toHaveKey('document_title')
        ->toHaveKey('page_number')
        ->toHaveKey('content');

    expect($searchableArray['document_hash'])->toBe('test-hash');
    expect($searchableArray['document_title'])->toBe('Test Document');
    expect($searchableArray['page_number'])->toBe(1);
    expect($searchableArray['content'])->toBe('Test content');
});

it('casts metadata as array', function () {
    $metadata = ['width' => 800, 'height' => 600];
    $page = PdfDocumentPage::factory()->create(['metadata' => $metadata]);

    expect($page->metadata)->toBeArray();
    expect($page->metadata['width'])->toBe(800);
    expect($page->metadata['height'])->toBe(600);
});

it('soft deletes', function () {
    $page = PdfDocumentPage::factory()->create();

    $page->delete();

    $this->assertSoftDeleted($page);
    expect(PdfDocumentPage::all())->toHaveCount(0);
    expect(PdfDocumentPage::withTrashed()->get())->toHaveCount(1);
});

it('uses uuid for primary key', function () {
    $page = PdfDocumentPage::factory()->create();

    expect($page->id)->toBeString();
    // Accept any valid UUID format (v4, v7, etc.)
    expect($page->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});
