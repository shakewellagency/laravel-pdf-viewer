<?php

use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;
use Shakewellagency\LaravelPdfViewer\Resources\LinkResource;

it('transforms link to array', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.5,
        'source_rect_y' => 200.5,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_page' => 5,
        'destination_type' => 'page',
        'type' => 'internal',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array)->toHaveKeys(['id', 'type', 'source_page', 'rect', 'normalized_rect', 'destination_type']);
    expect($array['type'])->toBe('internal');
    expect($array['source_page'])->toBe(1);
    expect($array['destination_page'])->toBe(5);
});

it('formats rect coordinates as floats', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100,
        'source_rect_y' => 200,
        'source_rect_width' => 50,
        'source_rect_height' => 20,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'page',
        'type' => 'internal',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array['rect']['x'])->toBeFloat();
    expect($array['rect']['y'])->toBeFloat();
    expect($array['rect']['width'])->toBeFloat();
    expect($array['rect']['height'])->toBeFloat();
});

it('includes normalized rect percentages', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'page',
        'type' => 'internal',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array['normalized_rect'])->toHaveKeys(['x_percent', 'y_percent', 'width_percent', 'height_percent']);
    expect($array['normalized_rect']['x_percent'])->toBe(16.34);
    expect($array['normalized_rect']['y_percent'])->toBe(25.31);
});

it('handles null optional fields properly', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'page',
        'destination_page' => null,
        'destination_url' => null,
        'link_text' => null,
        'type' => 'internal',
    ]);

    $resource = new LinkResource($link);
    $response = $resource->response()->getData(true);

    // When serialized to JSON, null values via when() are excluded
    expect($response['data']['destination_url'] ?? null)->toBeNull();
    expect($response['data']['link_text'] ?? null)->toBeNull();
});

it('includes destination_url for external links', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'external',
        'destination_url' => 'https://example.com',
        'type' => 'external',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array)->toHaveKey('destination_url');
    expect($array['destination_url'])->toBe('https://example.com');
    expect($array['type'])->toBe('external');
});

it('uses type field when provided', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'external',
        'destination_url' => 'https://example.com',
        'type' => 'external',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array['type'])->toBe('external');
});

it('maps destination_type page to internal type', function () {
    $document = PdfDocument::factory()->create();

    $link = PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_type' => 'page',
        'destination_page' => 5,
        'type' => 'internal',
    ]);

    $resource = new LinkResource($link);
    $array = $resource->toArray(request());

    expect($array['type'])->toBe('internal');
    expect($array['destination_type'])->toBe('page');
});
