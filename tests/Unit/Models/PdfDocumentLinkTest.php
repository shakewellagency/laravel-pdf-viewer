<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;

beforeEach(function () {
    $this->document = PdfDocument::create([
        'hash' => hash('sha256', uniqid().time()),
        'title' => 'Test Document',
        'filename' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/test.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'completed',
        'is_searchable' => true,
    ]);
});

it('link belongs to pdf document', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    expect($link->pdfDocument)->toBeInstanceOf(PdfDocument::class);
    expect($link->pdfDocument->id)->toBe($this->document->id);
});

it('is internal returns true for internal links', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    expect($link->isInternal())->toBeTrue();
    expect($link->isExternal())->toBeFalse();
});

it('is external returns true for external links', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => 'https://example.com',
    ]);

    expect($link->isExternal())->toBeTrue();
    expect($link->isInternal())->toBeFalse();
});

it('gets absolute coordinates attribute', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
        'source_rect_x' => 100.5,
        'source_rect_y' => 200.75,
        'source_rect_width' => 150.25,
        'source_rect_height' => 20.50,
    ]);

    $coords = $link->absolute_coordinates;

    expect($coords['x'])->toBe(100.5);
    expect($coords['y'])->toBe(200.75);
    expect($coords['width'])->toBe(150.25);
    expect($coords['height'])->toBe(20.50);
});

it('gets normalized coordinates attribute', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.38,
        'coord_width_percent' => 24.51,
        'coord_height_percent' => 2.59,
    ]);

    $coords = $link->normalized_coordinates;

    expect($coords['x_percent'])->toBe(16.34);
    expect($coords['y_percent'])->toBe(25.38);
    expect($coords['width_percent'])->toBe(24.51);
    expect($coords['height_percent'])->toBe(2.59);
});

it('internal scope filters internal links', function () {
    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => 'https://example.com',
    ]);

    $internalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->internal()
        ->get();

    expect($internalLinks)->toHaveCount(1);
    expect($internalLinks->first()->type)->toBe(PdfDocumentLink::TYPE_INTERNAL);
});

it('external scope filters external links', function () {
    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => 'https://example.com',
    ]);

    $externalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->external()
        ->get();

    expect($externalLinks)->toHaveCount(1);
    expect($externalLinks->first()->type)->toBe(PdfDocumentLink::TYPE_EXTERNAL);
});

it('for page scope filters by source page', function () {
    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 2,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 10,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => 'https://example.com',
    ]);

    $page1Links = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->forPage(1)
        ->get();

    expect($page1Links)->toHaveCount(2);
});

it('pointing to page scope filters internal links by destination', function () {
    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 2,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 3,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 10,
    ]);

    $linksToPage5 = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->pointingToPage(5)
        ->get();

    expect($linksToPage5)->toHaveCount(2);
});

it('gets grouped by page for document', function () {
    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => 'https://example.com',
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 3,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 10,
    ]);

    $grouped = PdfDocumentLink::getGroupedByPageForDocument($this->document->id);

    expect($grouped)->toHaveKey(1);
    expect($grouped)->toHaveKey(3);
    expect($grouped[1])->toHaveCount(2);
    expect($grouped[3])->toHaveCount(1);
});

it('type constants are defined', function () {
    expect(PdfDocumentLink::TYPE_INTERNAL)->toBe('internal');
    expect(PdfDocumentLink::TYPE_EXTERNAL)->toBe('external');
    expect(PdfDocumentLink::TYPE_UNKNOWN)->toBe('unknown');
});

it('casts are applied correctly', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => '1',
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => '5',
        'source_rect_x' => '100.5',
        'source_rect_y' => '200.75',
    ]);

    expect($link->source_page)->toBeInt();
    expect($link->destination_page)->toBeInt();
    expect($link->source_rect_x)->toBeFloat();
    expect($link->source_rect_y)->toBeFloat();
});

// ========== Edge Case Tests ==========

it('handles many links per document (500+)', function () {
    // Create 500 links
    $links = [];
    for ($i = 0; $i < 500; $i++) {
        $links[] = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'pdf_document_id' => $this->document->id,
            'source_page' => ($i % 10) + 1,
            'type' => $i % 2 === 0 ? PdfDocumentLink::TYPE_INTERNAL : PdfDocumentLink::TYPE_EXTERNAL,
            'destination_page' => $i % 2 === 0 ? ($i % 50) + 1 : null,
            'destination_url' => $i % 2 === 1 ? "https://example.com/link-$i" : null,
            'source_rect_x' => $i * 10.0,
            'source_rect_y' => $i * 5.0,
            'source_rect_width' => 100.0,
            'source_rect_height' => 20.0,
            'created_at' => now(),
        ];
    }

    // Batch insert
    PdfDocumentLink::insert($links);

    $totalLinks = PdfDocumentLink::where('pdf_document_id', $this->document->id)->count();
    expect($totalLinks)->toBe(500);

    // Verify grouped by page works with many links
    $grouped = PdfDocumentLink::getGroupedByPageForDocument($this->document->id);
    expect($grouped)->toHaveCount(10);

    // Each page should have 50 links
    foreach ($grouped as $pageNumber => $pageLinks) {
        expect($pageLinks)->toHaveCount(50);
    }
});

it('handles document with no links', function () {
    // Document has no links
    $grouped = PdfDocumentLink::getGroupedByPageForDocument($this->document->id);

    expect($grouped)->toBeArray();
    expect($grouped)->toBeEmpty();
});

it('handles links with zero coordinates', function () {
    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_INTERNAL,
        'destination_page' => 5,
        'source_rect_x' => 0.0,
        'source_rect_y' => 0.0,
        'source_rect_width' => 0.0,
        'source_rect_height' => 0.0,
        'coord_x_percent' => 0.0,
        'coord_y_percent' => 0.0,
        'coord_width_percent' => 0.0,
        'coord_height_percent' => 0.0,
    ]);

    $coords = $link->absolute_coordinates;
    expect($coords['x'])->toBe(0.0);
    expect($coords['y'])->toBe(0.0);
    expect($coords['width'])->toBe(0.0);
    expect($coords['height'])->toBe(0.0);
});

it('handles very long destination URLs', function () {
    $longUrl = 'https://example.com/' . str_repeat('a', 1000);

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => $longUrl,
    ]);

    expect($link->destination_url)->toBe($longUrl);
});

it('handles links spanning multiple pages to same destination', function () {
    // Create links from pages 1, 2, 3 all pointing to page 10
    for ($page = 1; $page <= 3; $page++) {
        PdfDocumentLink::create([
            'pdf_document_id' => $this->document->id,
            'source_page' => $page,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 10,
        ]);
    }

    $linksToPage10 = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->pointingToPage(10)
        ->get();

    expect($linksToPage10)->toHaveCount(3);
});

it('handles mixed internal and external links on same page', function () {
    // Create 3 internal and 2 external links on page 1
    for ($i = 0; $i < 3; $i++) {
        PdfDocumentLink::create([
            'pdf_document_id' => $this->document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => ($i + 1) * 5,
        ]);
    }

    for ($i = 0; $i < 2; $i++) {
        PdfDocumentLink::create([
            'pdf_document_id' => $this->document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => "https://example-$i.com",
        ]);
    }

    $page1Links = PdfDocumentLink::where('pdf_document_id', $this->document->id)
        ->forPage(1)
        ->get();

    expect($page1Links)->toHaveCount(5);

    $internalCount = $page1Links->where('type', PdfDocumentLink::TYPE_INTERNAL)->count();
    $externalCount = $page1Links->where('type', PdfDocumentLink::TYPE_EXTERNAL)->count();

    expect($internalCount)->toBe(3);
    expect($externalCount)->toBe(2);
});

it('handles special characters in destination URL', function () {
    $specialUrl = 'https://example.com/path?query=hello&foo=bar#section-1';

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => $specialUrl,
    ]);

    expect($link->destination_url)->toBe($specialUrl);
});

it('handles unicode in destination URL', function () {
    $unicodeUrl = 'https://example.com/путь/页面';

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $this->document->id,
        'source_page' => 1,
        'type' => PdfDocumentLink::TYPE_EXTERNAL,
        'destination_url' => $unicodeUrl,
    ]);

    expect($link->destination_url)->toBe($unicodeUrl);
});
