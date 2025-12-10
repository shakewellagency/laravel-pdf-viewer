<?php

use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Resources\OutlineResource;

it('transforms outline to array', function () {
    $document = PdfDocument::factory()->create();

    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'destination_type' => 'page',
        'order_index' => 0,
    ]);

    $resource = new OutlineResource($outline);
    $array = $resource->toArray(request());

    expect($array)->toHaveKeys(['id', 'title', 'level', 'destination_page', 'destination_type', 'order_index']);
    expect($array['title'])->toBe('Chapter 1');
    expect($array['level'])->toBe(0);
    expect($array['destination_page'])->toBe(1);
    expect($array['destination_type'])->toBe('page');
});

it('includes children when loaded', function () {
    $document = PdfDocument::factory()->create();

    $parent = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'destination_type' => 'page',
        'order_index' => 0,
    ]);

    $child = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'parent_id' => $parent->id,
        'title' => 'Section 1.1',
        'level' => 1,
        'destination_page' => 5,
        'destination_type' => 'page',
        'order_index' => 0,
    ]);

    $parent->load('children');
    $resource = new OutlineResource($parent);
    $array = $resource->toArray(request());

    expect($array['children'])->toBeInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class);
});

it('handles null destination_name properly', function () {
    $document = PdfDocument::factory()->create();

    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'destination_type' => 'page',
        'destination_name' => null,
        'order_index' => 0,
    ]);

    $resource = new OutlineResource($outline);
    $response = $resource->response()->getData(true);

    // When serialized to JSON, null values via when() are excluded
    expect($response['data']['destination_name'] ?? null)->toBeNull();
});

it('includes destination_name when set', function () {
    $document = PdfDocument::factory()->create();

    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => null,
        'destination_type' => 'named',
        'destination_name' => 'chapter1',
        'order_index' => 0,
    ]);

    $resource = new OutlineResource($outline);
    $array = $resource->toArray(request());

    expect($array)->toHaveKey('destination_name');
    expect($array['destination_name'])->toBe('chapter1');
});
