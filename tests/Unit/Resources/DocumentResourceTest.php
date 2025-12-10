<?php

use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentResource;

it('transforms document to array', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'page_count' => 5,
        'status' => 'completed',
        'is_searchable' => true,
        'metadata' => ['author' => 'Test Author'],
        'created_by' => 'user-123',
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['id'])->toBe($document->id);
    expect($result['hash'])->toBe($document->hash);
    expect($result['title'])->toBe('Test Document');
    expect($result['filename'])->toBe('test.pdf');
    expect($result['file_size'])->toBe(1024);
    expect($result['mime_type'])->toBe('application/pdf');
    expect($result['page_count'])->toBe(5);
    expect($result['status'])->toBe('completed');
    expect($result['is_searchable'])->toBeTrue();
    expect($result['metadata'])->toBe(['author' => 'Test Author']);
    expect($result['created_by'])->toBe('user-123');
});

it('includes processing progress when processing', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('processing_progress');
});

it('includes processing progress when failed', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'failed',
        'processing_error' => 'Failed to process PDF',
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('processing_progress');
    expect($result)->toHaveKey('processing_error');
    expect($result['processing_error'])->toBe('Failed to process PDF');
});

it('excludes processing progress when completed', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('processing_progress');
    expect($result)->not->toHaveKey('processing_error');
});

it('includes processing timestamps', function () {
    $startTime = now()->subHours(2);
    $endTime = now()->subHour();

    $document = PdfDocument::factory()->create([
        'processing_started_at' => $startTime,
        'processing_completed_at' => $endTime,
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['processing_started_at'])->toStartWith($startTime->format('Y-m-d\TH:i:s'));
    expect($result['processing_completed_at'])->toStartWith($endTime->format('Y-m-d\TH:i:s'));
});

it('handles null processing timestamps', function () {
    $document = PdfDocument::factory()->create([
        'processing_started_at' => null,
        'processing_completed_at' => null,
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['processing_started_at'])->toBeNull();
    expect($result['processing_completed_at'])->toBeNull();
});

it('formats timestamps properly', function () {
    $document = PdfDocument::factory()->create();

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['created_at'])->toBeString();
    expect($result['updated_at'])->toBeString();
    expect($result['created_at'])->toStartWith($document->created_at->format('Y-m-d\TH:i:s'));
    expect($result['updated_at'])->toStartWith($document->updated_at->format('Y-m-d\TH:i:s'));
});

it('includes formatted file size', function () {
    $document = PdfDocument::factory()->create([
        'file_size' => 2097152, // 2MB
    ]);

    $resource = new DocumentResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('formatted_file_size');
    expect($result['formatted_file_size'])->toBe($document->formatted_file_size);
});
