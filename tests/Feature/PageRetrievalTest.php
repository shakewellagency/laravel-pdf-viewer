<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PageRetrievalTest extends TestCase
{
    public function test_can_retrieve_specific_page(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'completed',
        ]);

        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'status' => 'completed',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/1");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'page_number',
                    'content',
                    'content_length',
                    'word_count',
                    'status',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertEquals(1, $response->json('data.page_number'));
        $this->assertEquals('This is the content of page 1', $response->json('data.content'));
    }

    public function test_can_retrieve_page_list(): void
    {
        $document = PdfDocument::factory()->create([
            'page_count' => 3,
            'status' => 'completed',
        ]);

        PdfDocumentPage::factory()->count(3)->create([
            'pdf_document_id' => $document->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'page_number',
                        'content_length',
                        'word_count',
                        'status',
                    ],
                ],
                'meta' => ['total', 'per_page'],
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_page_retrieval_requires_authentication(): void
    {
        $document = PdfDocument::factory()->create();

        $response = $this->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/1");

        $response->assertStatus(401);
    }

    public function test_returns_404_for_nonexistent_document(): void
    {
        $response = $this->actingAsUser()
            ->getJson('/api/pdf-viewer/documents/nonexistent-hash/pages/1');

        $response->assertStatus(404);
    }

    public function test_returns_404_for_nonexistent_page(): void
    {
        $document = PdfDocument::factory()->create([
            'page_count' => 5,
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/10");

        $response->assertStatus(404);
    }

    public function test_can_retrieve_page_thumbnail(): void
    {
        Storage::fake('testing');

        $document = PdfDocument::factory()->create();

        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'status' => 'completed',
        ]);

        // Create a fake thumbnail
        Storage::disk('testing')->put(
            "thumbnails/{$document->hash}/page-1.jpg",
            'fake thumbnail content'
        );

        $response = $this->actingAsUser()
            ->get("/api/pdf-viewer/documents/{$document->hash}/pages/1/thumbnail");

        $response->assertStatus(200);
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_thumbnail_returns_404_when_not_exists(): void
    {
        $document = PdfDocument::factory()->create();

        PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'status' => 'completed',
        ]);

        $response = $this->actingAsUser()
            ->get("/api/pdf-viewer/documents/{$document->hash}/pages/1/thumbnail");

        $response->assertStatus(404);
    }

    public function test_page_list_supports_pagination(): void
    {
        $document = PdfDocument::factory()->create([
            'page_count' => 25,
        ]);

        PdfDocumentPage::factory()->count(25)->create([
            'pdf_document_id' => $document->id,
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages?per_page=10");

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25);
    }

    public function test_page_list_can_filter_by_status(): void
    {
        $document = PdfDocument::factory()->create();

        PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'status' => 'completed',
        ]);

        PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 2,
            'status' => 'processing',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages?status=completed");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertEquals('completed', $response->json('data.0.status'));
    }

    public function test_returns_processing_status_for_incomplete_pages(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'processing',
        ]);

        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'status' => 'processing',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/1");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'processing');
    }
}
