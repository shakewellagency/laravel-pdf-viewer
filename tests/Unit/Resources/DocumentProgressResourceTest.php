<?php

use Illuminate\Http\Request;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentProgressResource;

it('transforms document progress to array', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Progress Test Document',
        'status' => 'processing',
        'page_count' => 10,
        'is_searchable' => true,
        'processing_progress' => 45.5,
        'processing_started_at' => now()->subHours(2),
    ]);

    // Create some completed and failed pages with unique page numbers
    PdfDocumentPage::factory()->count(4)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
        ['page_number' => 3],
        ['page_number' => 4]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'completed',
    ]);

    PdfDocumentPage::factory()->count(1)->sequence(
        ['page_number' => 5]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'failed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['hash'])->toBe($document->hash);
    expect($result['title'])->toBe('Progress Test Document');
    expect($result['status'])->toBe('processing');
    expect($result['total_pages'])->toBe(10);
    expect($result['completed_pages'])->toBe(4);
    expect($result['failed_pages'])->toBe(1);
    expect($result['is_searchable'])->toBeTrue();
    expect($result['processing_progress'])->toBe(45.5);
});

it('includes processing error when failed', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'failed',
        'processing_error' => 'Failed to process document',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('processing_error');
    expect($result['processing_error'])->toBe('Failed to process document');
});

it('excludes processing error when not failed', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('processing_error');
});

it('includes estimated completion when processing', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
        'page_count' => 10,
        'processing_started_at' => now()->subMinutes(30),
    ]);

    // Create some completed pages to enable estimation
    PdfDocumentPage::factory()->count(3)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
        ['page_number' => 3]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'completed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('estimated_completion');
    expect($result['estimated_completion'])->toBeString();
});

it('excludes estimated completion when not processing', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('estimated_completion');
});

it('estimated completion returns null with no completed pages', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
        'page_count' => 10,
        'processing_started_at' => now()->subMinutes(30),
    ]);

    // No completed pages
    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('estimated_completion');
});

it('estimated completion returns null with no processing start time', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
        'page_count' => 10,
        'processing_started_at' => null,
    ]);

    PdfDocumentPage::factory()->count(3)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
        ['page_number' => 3]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'completed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('estimated_completion');
});

it('estimated completion returns null with zero page count', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'processing',
        'page_count' => 0,
        'processing_started_at' => now()->subMinutes(30),
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->not->toHaveKey('estimated_completion');
});

it('formats timestamps properly', function () {
    $startTime = now()->subHours(3);
    $endTime = now()->subHour();

    $document = PdfDocument::factory()->create([
        'processing_started_at' => $startTime,
        'processing_completed_at' => $endTime,
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['processing_started_at'])->toStartWith($startTime->format('Y-m-d\TH:i:s'));
    expect($result['processing_completed_at'])->toStartWith($endTime->format('Y-m-d\TH:i:s'));
});

it('handles null timestamps', function () {
    $document = PdfDocument::factory()->create([
        'processing_started_at' => null,
        'processing_completed_at' => null,
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['processing_started_at'])->toBeNull();
    expect($result['processing_completed_at'])->toBeNull();
});

it('includes progress percentage', function () {
    $document = PdfDocument::factory()->create([
        'page_count' => 10,
    ]);

    // Create 7 completed pages for 70% progress
    PdfDocumentPage::factory()->count(7)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
        ['page_number' => 3],
        ['page_number' => 4],
        ['page_number' => 5],
        ['page_number' => 6],
        ['page_number' => 7]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'completed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result)->toHaveKey('progress_percentage');
    expect($result['progress_percentage'])->toBe($document->getProcessingProgress());
});

it('handles complete document', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
        'page_count' => 5,
        'processing_started_at' => now()->subHours(2),
        'processing_completed_at' => now()->subHour(),
    ]);

    // Use sequence to ensure unique page numbers for the unique constraint
    PdfDocumentPage::factory()->count(5)->sequence(
        ['page_number' => 1, 'status' => 'completed'],
        ['page_number' => 2, 'status' => 'completed'],
        ['page_number' => 3, 'status' => 'completed'],
        ['page_number' => 4, 'status' => 'completed'],
        ['page_number' => 5, 'status' => 'completed']
    )->create(['pdf_document_id' => $document->id]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['status'])->toBe('completed');
    expect($result['total_pages'])->toBe(5);
    expect($result['completed_pages'])->toBe(5);
    expect($result['failed_pages'])->toBe(0);
    expect($result)->not->toHaveKey('estimated_completion');
    expect($result)->not->toHaveKey('processing_error');
});

it('handles failed document with partial processing', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'failed',
        'page_count' => 10,
        'processing_error' => 'Processing timeout',
        'processing_started_at' => now()->subHours(3),
    ]);

    // Use sequence to ensure unique page numbers
    PdfDocumentPage::factory()->count(3)->sequence(
        ['page_number' => 1],
        ['page_number' => 2],
        ['page_number' => 3]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'completed',
    ]);

    PdfDocumentPage::factory()->count(2)->sequence(
        ['page_number' => 4],
        ['page_number' => 5]
    )->create([
        'pdf_document_id' => $document->id,
        'status' => 'failed',
    ]);

    $resource = new DocumentProgressResource($document);
    $request = new Request();

    $result = $resource->toArray($request);

    expect($result['status'])->toBe('failed');
    expect($result['total_pages'])->toBe(10);
    expect($result['completed_pages'])->toBe(3);
    expect($result['failed_pages'])->toBe(2);
    expect($result['processing_error'])->toBe('Processing timeout');
    expect($result)->not->toHaveKey('estimated_completion');
});
