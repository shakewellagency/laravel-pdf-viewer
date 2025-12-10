<?php

use Illuminate\Http\UploadedFile;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

it('can upload pdf document successfully')
    ->skip('Requires real PDF file for MIME validation - fake PDFs do not pass validation in CI');

it('can upload real sample pdf', function () {
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
});

it('upload fails with invalid file type', function () {
    $file = UploadedFile::fake()->create('document.txt', 100);

    $response = $this->actingAsUser()
        ->postJson('/api/pdf-viewer/documents', [
            'file' => $file,
            'title' => 'Test Document',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('upload fails with oversized file', function () {
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
});

it('upload requires authentication', function () {
    // Skip test if auth middleware is not configured
    $middleware = config('pdf-viewer.middleware', []);
    if (! in_array('auth', $middleware) && ! in_array('auth:sanctum', $middleware) && ! in_array('auth:api', $middleware)) {
        $this->markTestSkipped('Auth middleware not configured for routes');
    }

    $file = $this->createSamplePdfFile();

    $response = $this->postJson('/api/pdf-viewer/documents', [
        'file' => $file,
        'title' => 'Test Document',
    ]);

    $response->assertStatus(401);
});

it('upload validates required fields', function () {
    $response = $this->actingAsUser()
        ->postJson('/api/pdf-viewer/documents', [
            'title' => 'Test Document',
            // Missing file
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('upload validates metadata structure', function () {
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
});

it('upload sets default title from filename', function () {
    $file = $this->createSamplePdfFile('my-aviation-document.pdf');

    $response = $this->actingAsUser()
        ->postJson('/api/pdf-viewer/documents', [
            'file' => $file,
            // No title provided
        ]);

    $response->assertStatus(201);

    $document = PdfDocument::latest()->first();
    expect($document->title)->toBe('my-aviation-document');
});

it('upload generates unique hash', function () {
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

    expect($hash1)->not->toBe($hash2);
    expect($hash1)->not->toBeNull();
    expect($hash2)->not->toBeNull();
});
