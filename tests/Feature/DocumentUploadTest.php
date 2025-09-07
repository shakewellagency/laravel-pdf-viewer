<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    public function test_can_upload_pdf_document_successfully(): void
    {
        $file = $this->createSamplePdfFile('aviation-manual.pdf');

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $file,
                'title' => 'Aviation Safety Manual',
                'description' => 'Comprehensive aviation safety procedures',
                'metadata' => [
                    'author' => 'Aviation Authority',
                    'subject' => 'Safety Procedures',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'hash',
                    'title',
                    'filename',
                    'file_size',
                    'status',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('pdf_documents', [
            'title' => 'Aviation Safety Manual',
            'original_filename' => 'aviation-manual.pdf',
            'status' => 'uploaded',
        ]);
    }

    public function test_can_upload_real_sample_pdf(): void
    {
        $samplePdf = $this->createRealSamplePdf();

        if (! $samplePdf) {
            $this->markTestSkipped('No sample PDF files found in SamplePDF directory');
        }

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $samplePdf,
                'title' => 'Real Aviation Document',
                'description' => 'Testing with real aviation PDF',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pdf_documents', [
            'title' => 'Real Aviation Document',
            'status' => 'uploaded',
        ]);
    }

    public function test_upload_fails_with_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.txt', 100);

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $file,
                'title' => 'Test Document',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_fails_with_oversized_file(): void
    {
        // Set a very small file size limit for testing
        config(['pdf-viewer.processing.max_file_size' => 1024]); // 1KB

        $file = UploadedFile::fake()->create('large.pdf', 2048) // 2KB
            ->mimeType('application/pdf');

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $file,
                'title' => 'Large Document',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_requires_authentication(): void
    {
        $file = $this->createSamplePdfFile();

        $response = $this->postJson('/api/pdf-viewer/documents', [
            'file' => $file,
            'title' => 'Test Document',
        ]);

        $response->assertStatus(401);
    }

    public function test_upload_validates_required_fields(): void
    {
        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'title' => 'Test Document',
                // Missing file
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_validates_metadata_structure(): void
    {
        $file = $this->createSamplePdfFile();

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $file,
                'title' => 'Test Document',
                'metadata' => [
                    'author' => str_repeat('a', 300), // Too long
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['metadata.author']);
    }

    public function test_upload_sets_default_title_from_filename(): void
    {
        $file = $this->createSamplePdfFile('my-aviation-document.pdf');

        $response = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', [
                'file' => $file,
                // No title provided
            ]);

        $response->assertStatus(201);

        $document = PdfDocument::latest()->first();
        $this->assertEquals('my-aviation-document', $document->title);
    }

    public function test_upload_generates_unique_hash(): void
    {
        $file1 = $this->createSamplePdfFile('doc1.pdf');
        $file2 = $this->createSamplePdfFile('doc2.pdf');

        $response1 = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', ['file' => $file1]);

        $response2 = $this->actingAsUser()
            ->postJson('/api/pdf-viewer/documents', ['file' => $file2]);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $hash1 = $response1->json('data.hash');
        $hash2 = $response2->json('data.hash');

        $this->assertNotEquals($hash1, $hash2);
        $this->assertNotNull($hash1);
        $this->assertNotNull($hash2);
    }
}
