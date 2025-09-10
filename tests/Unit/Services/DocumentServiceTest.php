<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

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
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    protected DocumentService $documentService;

    protected $processingServiceMock;

    protected $cacheServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processingServiceMock = Mockery::mock(DocumentProcessingServiceInterface::class);
        $this->cacheServiceMock = Mockery::mock(CacheServiceInterface::class);

        $this->documentService = new DocumentService(
            $this->processingServiceMock,
            $this->cacheServiceMock
        );
    }

    public function test_upload_creates_document_successfully(): void
    {
        $file = $this->createSamplePdfFile('test.pdf');
        Storage::disk('testing')->put('pdf-documents/test-file.pdf', 'test content');

        $metadata = [
            'title' => 'Test Document',
            'description' => 'Test Description',
        ];

        $document = $this->documentService->upload($file, $metadata);

        $this->assertInstanceOf(PdfDocument::class, $document);
        $this->assertEquals('Test Document', $document->title);
        $this->assertEquals('test.pdf', $document->original_filename);
        $this->assertEquals('uploaded', $document->status);
        $this->assertNotNull($document->hash);
    }

    public function test_upload_throws_exception_for_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage('Invalid file extension. Only PDF files are allowed.');

        $this->documentService->upload($file, []);
    }

    public function test_upload_throws_exception_for_oversized_file(): void
    {
        // Create a file larger than the configured limit
        config(['pdf-viewer.processing.max_file_size' => 1024]); // 1KB limit

        $file = UploadedFile::fake()->create('large.pdf', 2048); // 2KB file
        $file = $file->mimeType('application/pdf');

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage('File size exceeds maximum allowed size.');

        $this->documentService->upload($file, []);
    }

    public function test_find_by_hash_returns_document_when_exists(): void
    {
        $document = PdfDocument::factory()->create();

        $result = $this->documentService->findByHash($document->hash);

        $this->assertInstanceOf(PdfDocument::class, $result);
        $this->assertEquals($document->id, $result->id);
    }

    public function test_find_by_hash_returns_null_when_not_exists(): void
    {
        $result = $this->documentService->findByHash('non-existent-hash');

        $this->assertNull($result);
    }

    public function test_get_metadata_returns_cached_data_when_available(): void
    {
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

        $this->assertEquals($cachedMetadata, $result);
    }

    public function test_get_metadata_caches_data_when_not_cached(): void
    {
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

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals($document->hash, $result['hash']);
        $this->assertEquals('Test Document', $result['title']);
    }

    public function test_get_metadata_throws_exception_when_document_not_found(): void
    {
        $this->cacheServiceMock
            ->shouldReceive('getCachedDocumentMetadata')
            ->with('non-existent-hash')
            ->once()
            ->andReturn(null);

        $this->expectException(DocumentNotFoundException::class);

        $this->documentService->getMetadata('non-existent-hash');
    }

    public function test_get_progress_returns_processing_information(): void
    {
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

        $this->assertEquals('processing', $progress['status']);
        $this->assertEquals(50.0, $progress['progress_percentage']); // 5 completed out of 10 total
        $this->assertEquals(10, $progress['total_pages']);
        $this->assertEquals(5, $progress['completed_pages']);
        $this->assertEquals(2, $progress['failed_pages']);
    }

    public function test_update_metadata_updates_document_and_invalidates_cache(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Original Title',
            'metadata' => ['author' => 'Original Author'],
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

        $this->assertTrue($result);

        $document->refresh();
        $this->assertEquals('Updated Title', $document->title);
        $this->assertEquals(['author' => 'Original Author', 'subject' => 'Updated Subject'], $document->metadata);
    }

    public function test_delete_removes_document_and_invalidates_cache(): void
    {
        $document = PdfDocument::factory()->create();

        $this->cacheServiceMock
            ->shouldReceive('invalidateDocumentCache')
            ->with($document->hash)
            ->once()
            ->andReturn(true);

        $result = $this->documentService->delete($document->hash);

        $this->assertTrue($result);
        $this->assertSoftDeleted($document);
    }

    public function test_exists_returns_true_when_document_exists(): void
    {
        $document = PdfDocument::factory()->create();

        $result = $this->documentService->exists($document->hash);

        $this->assertTrue($result);
    }

    public function test_exists_returns_false_when_document_does_not_exist(): void
    {
        $result = $this->documentService->exists('non-existent-hash');

        $this->assertFalse($result);
    }

    public function test_list_returns_paginated_results(): void
    {
        PdfDocument::factory()->count(25)->create();

        $result = $this->documentService->list([], 10);

        $this->assertEquals(10, $result->count());
        $this->assertEquals(25, $result->total());
        $this->assertEquals(3, $result->lastPage());
    }

    public function test_list_applies_filters_correctly(): void
    {
        PdfDocument::factory()->create(['status' => 'completed']);
        PdfDocument::factory()->create(['status' => 'processing']);
        PdfDocument::factory()->create(['status' => 'failed']);

        $result = $this->documentService->list(['status' => 'completed']);

        $this->assertEquals(1, $result->count());
        $this->assertEquals('completed', $result->first()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
