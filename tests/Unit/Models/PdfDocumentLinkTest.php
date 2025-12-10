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
