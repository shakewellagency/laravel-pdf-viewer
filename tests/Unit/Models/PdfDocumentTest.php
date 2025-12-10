<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

it('can create pdf document', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Test Document',
        'status' => 'uploaded',
    ]);

    $this->assertDatabaseHas('pdf_documents', [
        'id' => $document->id,
        'title' => 'Test Document',
        'status' => 'uploaded',
    ]);
});

it('has pages relationship', function () {
    $document = PdfDocument::factory()
        ->has(PdfDocumentPage::factory()->count(3), 'pages')
        ->create();

    expect($document->pages)->toHaveCount(3);
    expect($document->pages->first())->toBeInstanceOf(PdfDocumentPage::class);
});

it('has searchable scope', function () {
    PdfDocument::factory()->create(['is_searchable' => true]);
    PdfDocument::factory()->create(['is_searchable' => false]);

    $searchableDocuments = PdfDocument::searchable()->get();

    expect($searchableDocuments)->toHaveCount(1);
    expect($searchableDocuments->first()->is_searchable)->toBeTrue();
});

it('has completed scope', function () {
    PdfDocument::factory()->create(['status' => 'completed']);
    PdfDocument::factory()->create(['status' => 'processing']);

    $completedDocuments = PdfDocument::completed()->get();

    expect($completedDocuments)->toHaveCount(1);
    expect($completedDocuments->first()->status)->toBe('completed');
});

it('has processing scope', function () {
    PdfDocument::factory()->create(['status' => 'processing']);
    PdfDocument::factory()->create(['status' => 'completed']);

    $processingDocuments = PdfDocument::processing()->get();

    expect($processingDocuments)->toHaveCount(1);
    expect($processingDocuments->first()->status)->toBe('processing');
});

it('has failed scope', function () {
    PdfDocument::factory()->create(['status' => 'failed']);
    PdfDocument::factory()->create(['status' => 'completed']);

    $failedDocuments = PdfDocument::failed()->get();

    expect($failedDocuments)->toHaveCount(1);
    expect($failedDocuments->first()->status)->toBe('failed');
});

it('gets file size in mb attribute', function () {
    $document = PdfDocument::factory()->create([
        'file_size' => 2097152, // 2MB in bytes
    ]);

    expect($document->getFileSizeInMbAttribute())->toBe(2.0);
});

it('checks if is processing method works', function () {
    $processingDocument = PdfDocument::factory()->create(['status' => 'processing']);
    $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);

    expect($processingDocument->isProcessing())->toBeTrue();
    expect($completedDocument->isProcessing())->toBeFalse();
});

it('checks if is completed method works', function () {
    $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);
    $processingDocument = PdfDocument::factory()->create(['status' => 'processing']);

    expect($completedDocument->isCompleted())->toBeTrue();
    expect($processingDocument->isCompleted())->toBeFalse();
});

it('checks if has failed method works', function () {
    $failedDocument = PdfDocument::factory()->create(['status' => 'failed']);
    $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);

    expect($failedDocument->hasFailed())->toBeTrue();
    expect($completedDocument->hasFailed())->toBeFalse();
});

it('gets progress percentage method', function () {
    $document = PdfDocument::factory()->create(['page_count' => 10]);

    // Create pages with specific page numbers to avoid unique constraint violations
    PdfDocumentPage::factory()->count(5)->sequence(
        ['page_number' => 1, 'status' => 'completed'],
        ['page_number' => 2, 'status' => 'completed'],
        ['page_number' => 3, 'status' => 'completed'],
        ['page_number' => 4, 'status' => 'completed'],
        ['page_number' => 5, 'status' => 'completed']
    )->create(['pdf_document_id' => $document->id]);

    PdfDocumentPage::factory()->count(3)->sequence(
        ['page_number' => 6, 'status' => 'processing'],
        ['page_number' => 7, 'status' => 'processing'],
        ['page_number' => 8, 'status' => 'processing']
    )->create(['pdf_document_id' => $document->id]);

    PdfDocumentPage::factory()->count(2)->sequence(
        ['page_number' => 9, 'status' => 'pending'],
        ['page_number' => 10, 'status' => 'pending']
    )->create(['pdf_document_id' => $document->id]);

    // 5 completed out of 10 = 50%
    expect($document->getProgressPercentage())->toBe(50.0);
});

it('gets processing time method', function () {
    $now = now();
    $document = PdfDocument::factory()->create([
        'processing_started_at' => $now->copy()->subMinutes(30),
        'processing_completed_at' => $now,
    ]);

    $processingTime = $document->getProcessingTime();
    expect((int) $processingTime->totalMinutes)->toBe(30);
});

it('gets processing time with ongoing processing', function () {
    $document = PdfDocument::factory()->create([
        'processing_started_at' => now()->subMinutes(15),
        'processing_completed_at' => null,
    ]);

    $processingTime = $document->getProcessingTime();
    expect($processingTime->totalMinutes)->toBeGreaterThanOrEqual(14);
    expect($processingTime->totalMinutes)->toBeLessThanOrEqual(16);
});

it('casts metadata as array', function () {
    $metadata = ['author' => 'Test Author', 'subject' => 'Test Subject'];
    $document = PdfDocument::factory()->create(['metadata' => $metadata]);

    expect($document->metadata)->toBeArray();
    expect($document->metadata['author'])->toBe('Test Author');
});

it('soft deletes', function () {
    $document = PdfDocument::factory()->create();

    $document->delete();

    $this->assertSoftDeleted($document);
    expect(PdfDocument::all())->toHaveCount(0);
    expect(PdfDocument::withTrashed()->get())->toHaveCount(1);
});

it('uses uuid for primary key', function () {
    $document = PdfDocument::factory()->create();

    expect($document->id)->toBeString();
    // Accept any valid UUID format (v4, v7, etc.)
    expect($document->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});
