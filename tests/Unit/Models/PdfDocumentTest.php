<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PdfDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_pdf_document(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Test Document',
            'status' => 'uploaded',
        ]);

        $this->assertDatabaseHas('pdf_documents', [
            'id' => $document->id,
            'title' => 'Test Document',
            'status' => 'uploaded',
        ]);
    }

    public function test_has_pages_relationship(): void
    {
        $document = PdfDocument::factory()
            ->has(PdfDocumentPage::factory()->count(3), 'pages')
            ->create();

        $this->assertCount(3, $document->pages);
        $this->assertInstanceOf(PdfDocumentPage::class, $document->pages->first());
    }

    public function test_searchable_scope(): void
    {
        PdfDocument::factory()->create(['is_searchable' => true]);
        PdfDocument::factory()->create(['is_searchable' => false]);

        $searchableDocuments = PdfDocument::searchable()->get();

        $this->assertCount(1, $searchableDocuments);
        $this->assertTrue($searchableDocuments->first()->is_searchable);
    }

    public function test_completed_scope(): void
    {
        PdfDocument::factory()->create(['status' => 'completed']);
        PdfDocument::factory()->create(['status' => 'processing']);

        $completedDocuments = PdfDocument::completed()->get();

        $this->assertCount(1, $completedDocuments);
        $this->assertEquals('completed', $completedDocuments->first()->status);
    }

    public function test_processing_scope(): void
    {
        PdfDocument::factory()->create(['status' => 'processing']);
        PdfDocument::factory()->create(['status' => 'completed']);

        $processingDocuments = PdfDocument::processing()->get();

        $this->assertCount(1, $processingDocuments);
        $this->assertEquals('processing', $processingDocuments->first()->status);
    }

    public function test_failed_scope(): void
    {
        PdfDocument::factory()->create(['status' => 'failed']);
        PdfDocument::factory()->create(['status' => 'completed']);

        $failedDocuments = PdfDocument::failed()->get();

        $this->assertCount(1, $failedDocuments);
        $this->assertEquals('failed', $failedDocuments->first()->status);
    }

    public function test_get_file_size_in_mb_attribute(): void
    {
        $document = PdfDocument::factory()->create([
            'file_size' => 2097152, // 2MB in bytes
        ]);

        $this->assertEquals(2.0, $document->getFileSizeInMbAttribute());
    }

    public function test_is_processing_method(): void
    {
        $processingDocument = PdfDocument::factory()->create(['status' => 'processing']);
        $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);

        $this->assertTrue($processingDocument->isProcessing());
        $this->assertFalse($completedDocument->isProcessing());
    }

    public function test_is_completed_method(): void
    {
        $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);
        $processingDocument = PdfDocument::factory()->create(['status' => 'processing']);

        $this->assertTrue($completedDocument->isCompleted());
        $this->assertFalse($processingDocument->isCompleted());
    }

    public function test_has_failed_method(): void
    {
        $failedDocument = PdfDocument::factory()->create(['status' => 'failed']);
        $completedDocument = PdfDocument::factory()->create(['status' => 'completed']);

        $this->assertTrue($failedDocument->hasFailed());
        $this->assertFalse($completedDocument->hasFailed());
    }

    public function test_get_progress_percentage_method(): void
    {
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
        $this->assertEquals(50.0, $document->getProgressPercentage());
    }

    public function test_get_processing_time_method(): void
    {
        $now = now();
        $document = PdfDocument::factory()->create([
            'processing_started_at' => $now->copy()->subMinutes(30),
            'processing_completed_at' => $now,
        ]);

        $processingTime = $document->getProcessingTime();
        $this->assertEquals(30, $processingTime->totalMinutes);
    }

    public function test_get_processing_time_with_ongoing_processing(): void
    {
        $document = PdfDocument::factory()->create([
            'processing_started_at' => now()->subMinutes(15),
            'processing_completed_at' => null,
        ]);

        $processingTime = $document->getProcessingTime();
        $this->assertGreaterThanOrEqual(14, $processingTime->totalMinutes);
        $this->assertLessThanOrEqual(16, $processingTime->totalMinutes);
    }

    public function test_casts_metadata_as_array(): void
    {
        $metadata = ['author' => 'Test Author', 'subject' => 'Test Subject'];
        $document = PdfDocument::factory()->create(['metadata' => $metadata]);

        $this->assertIsArray($document->metadata);
        $this->assertEquals('Test Author', $document->metadata['author']);
    }

    public function test_soft_deletes(): void
    {
        $document = PdfDocument::factory()->create();
        
        $document->delete();

        $this->assertSoftDeleted($document);
        $this->assertCount(0, PdfDocument::all());
        $this->assertCount(1, PdfDocument::withTrashed()->get());
    }

    public function test_uses_uuid_for_primary_key(): void
    {
        $document = PdfDocument::factory()->create();

        $this->assertIsString($document->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $document->id
        );
    }
}