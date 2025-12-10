<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Exceptions\DocumentNotFoundException;
use Shakewellagency\LaravelPdfViewer\Exceptions\InvalidFileTypeException;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\DocumentService;

beforeEach(function () {
    $this->processingServiceMock = Mockery::mock(DocumentProcessingServiceInterface::class);
    $this->cacheServiceMock = Mockery::mock(CacheServiceInterface::class);

    $this->documentService = new DocumentService(
        $this->processingServiceMock,
        $this->cacheServiceMock
    );
});

afterEach(function () {
    Mockery::close();
});

it('uploads and creates document successfully', function () {
    $file = $this->createSamplePdfFile('test.pdf');
    Storage::disk('testing')->put('pdf-documents/test-file.pdf', 'test content');

    $metadata = [
        'title' => 'Test Document',
        'description' => 'Test Description',
    ];

    $document = $this->documentService->upload($file, $metadata);

    expect($document)->toBeInstanceOf(PdfDocument::class);
    expect($document->title)->toBe('Test Document');
    expect($document->original_filename)->toBe('test.pdf');
    expect($document->status)->toBe('uploaded');
    expect($document->hash)->not->toBeNull();
});

it('throws exception for invalid file type', function () {
    $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

    $this->documentService->upload($file, []);
})->throws(InvalidFileTypeException::class, 'Invalid file extension. Only PDF files are allowed.');

it('throws exception for oversized file', function () {
    // Create a file larger than the configured limit
    config(['pdf-viewer.processing.max_file_size' => 1024]); // 1KB limit

    $file = UploadedFile::fake()->create('large.pdf', 2048)->mimeType('application/pdf');

    $this->documentService->upload($file, []);
})->throws(InvalidFileTypeException::class, 'File size exceeds maximum allowed size.');

it('finds document by hash when exists', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->documentService->findByHash($document->hash);

    expect($result)->toBeInstanceOf(PdfDocument::class);
    expect($result->id)->toBe($document->id);
});

it('returns null when document not found by hash', function () {
    $result = $this->documentService->findByHash('non-existent-hash');

    expect($result)->toBeNull();
});

it('returns cached metadata when available', function () {
    $document = PdfDocument::factory()->create();
    $cachedMetadata = [
        'id' => $document->id,
        'hash' => $document->hash,
        'title' => $document->title,
    ];

    $this->cacheServiceMock
        ->shouldReceive('getCachedDocumentMetadata')
        ->with($document->hash)
        ->once()
        ->andReturn($cachedMetadata);

    $result = $this->documentService->getMetadata($document->hash);

    expect($result)->toBe($cachedMetadata);
});

it('caches metadata when not cached', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Test Document',
        'file_size' => 1024000,
    ]);

    $this->cacheServiceMock
        ->shouldReceive('getCachedDocumentMetadata')
        ->with($document->hash)
        ->once()
        ->andReturn(null);

    $this->cacheServiceMock
        ->shouldReceive('cacheDocumentMetadata')
        ->with($document->hash, Mockery::type('array'))
        ->once()
        ->andReturn(true);

    $result = $this->documentService->getMetadata($document->hash);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('hash');
    expect($result)->toHaveKey('title');
    expect($result['hash'])->toBe($document->hash);
    expect($result['title'])->toBe('Test Document');
});

it('throws exception when document not found for metadata', function () {
    $this->cacheServiceMock
        ->shouldReceive('getCachedDocumentMetadata')
        ->with('non-existent-hash')
        ->once()
        ->andReturn(null);

    $this->documentService->getMetadata('non-existent-hash');
})->throws(DocumentNotFoundException::class);

it('returns processing progress information', function () {
    $document = PdfDocument::factory()
        ->has(PdfDocumentPage::factory()->count(5)->state(['status' => 'completed'])->sequence(
            ['page_number' => 1],
            ['page_number' => 2],
            ['page_number' => 3],
            ['page_number' => 4],
            ['page_number' => 5]
        ), 'pages')
        ->has(PdfDocumentPage::factory()->count(2)->state(['status' => 'failed'])->sequence(
            ['page_number' => 6],
            ['page_number' => 7]
        ), 'pages')
        ->create([
            'page_count' => 10,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(30),
        ]);

    $progress = $this->documentService->getProgress($document->hash);

    expect($progress['status'])->toBe('processing');
    expect($progress['progress_percentage'])->toBe(50.0);
    expect($progress['total_pages'])->toBe(10);
    expect($progress['completed_pages'])->toBe(5);
    expect($progress['failed_pages'])->toBe(2);
});

it('updates metadata and invalidates cache', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Original Title',
    ]);

    $updateData = [
        'title' => 'Updated Title',
        'metadata' => ['subject' => 'Updated Subject'],
    ];

    $this->cacheServiceMock
        ->shouldReceive('invalidateDocumentCache')
        ->with($document->hash)
        ->once()
        ->andReturn(true);

    $result = $this->documentService->updateMetadata($document->hash, $updateData);

    expect($result)->toBeTrue();

    $document->refresh();
    expect($document->title)->toBe('Updated Title');
    // Metadata is stored in normalized metadataRecords, not the metadata column
    expect($document->getMetadataByKey('subject'))->toBe('Updated Subject');
});

it('deletes document and invalidates cache', function () {
    $document = PdfDocument::factory()->create();

    $this->cacheServiceMock
        ->shouldReceive('invalidateDocumentCache')
        ->with($document->hash)
        ->once()
        ->andReturn(true);

    $result = $this->documentService->delete($document->hash);

    expect($result)->toBeTrue();
    $this->assertSoftDeleted($document);
});

it('returns true when document exists', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->documentService->exists($document->hash);

    expect($result)->toBeTrue();
});

it('returns false when document does not exist', function () {
    $result = $this->documentService->exists('non-existent-hash');

    expect($result)->toBeFalse();
});

it('returns paginated list of documents', function () {
    PdfDocument::factory()->count(25)->create();

    $result = $this->documentService->list([], 10);

    expect($result->count())->toBe(10);
    expect($result->total())->toBe(25);
    expect($result->lastPage())->toBe(3);
});

it('applies filters to list correctly', function () {
    PdfDocument::factory()->create(['status' => 'completed']);
    PdfDocument::factory()->create(['status' => 'processing']);
    PdfDocument::factory()->create(['status' => 'failed']);

    $result = $this->documentService->list(['status' => 'completed']);

    expect($result->count())->toBe(1);
    expect($result->first()->status)->toBe('completed');
});
