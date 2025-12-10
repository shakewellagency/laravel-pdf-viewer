<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfPageContent;

it('can search documents by title', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Aviation Safety Manual',
        'is_searchable' => true,
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search?q=aviation');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'hash',
                    'title',
                    'relevance_score',
                ],
            ],
            'meta' => ['total', 'per_page'],
        ]);
});

it('can search pages by content', function () {
    $document = PdfDocument::factory()->create([
        'is_searchable' => true,
        'status' => 'completed',
    ]);

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'status' => 'completed',
    ]);

    // Create page content in the separate content table
    PdfPageContent::createOrUpdateForPage(
        $page,
        'This page contains aviation safety procedures and emergency protocols.'
    );

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/search/documents/{$document->hash}?q=safety procedures");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'page_number',
                    'content_length',
                    'relevance_score',
                    'search_snippet',
                    'document' => [
                        'hash',
                        'title',
                    ],
                ],
            ],
            'meta' => ['total', 'per_page'],
        ]);
});

it('search requires authentication', function () {
    // Skip test if auth middleware is not configured
    // Auth is configurable by consuming application via pdf-viewer.middleware config
    $middleware = config('pdf-viewer.middleware', []);
    if (! in_array('auth', $middleware) && ! in_array('auth:sanctum', $middleware) && ! in_array('auth:api', $middleware)) {
        $this->markTestSkipped('Auth middleware not configured for routes');
    }

    $response = $this->getJson('/api/pdf-viewer/search?q=aviation');

    $response->assertStatus(401);
});

it('search validates query parameter', function () {
    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

it('search returns empty results for no matches', function () {
    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search?q=nonexistentquery');

    $response->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('can get search suggestions', function () {
    $document = PdfDocument::factory()->create([
        'title' => 'Aviation Manual',
        'is_searchable' => true,
    ]);

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
    ]);

    // Create page content in the separate content table
    PdfPageContent::createOrUpdateForPage(
        $page,
        'aviation safety aircraft maintenance'
    );

    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search/suggestions?q=aviat');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'meta' => ['query', 'limit', 'count'],
        ]);
});

it('search with filters applies constraints', function () {
    $document1 = PdfDocument::factory()->create([
        'title' => 'Aviation Safety',
        'status' => 'completed',
        'is_searchable' => true,
        'created_at' => now()->subDays(1),
    ]);

    $document2 = PdfDocument::factory()->create([
        'title' => 'Aviation Maintenance',
        'status' => 'processing',
        'is_searchable' => true,
        'created_at' => now()->subDays(2),
    ]);

    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search?'.http_build_query([
            'q' => 'aviation',
            'status' => 'completed',
            'date_from' => now()->subDays(1)->format('Y-m-d'),
        ]));

    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['title'])->toBe('Aviation Safety');
});

it('search handles pagination', function () {
    PdfDocument::factory()->count(15)->create([
        'title' => 'Aviation Document',
        'is_searchable' => true,
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search?q=aviation&per_page=5');

    $response->assertStatus(200)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 15);
});

it('search excludes non searchable documents', function () {
    $searchableDoc = PdfDocument::factory()->create([
        'title' => 'Aviation Safety Manual',
        'is_searchable' => true,
        'status' => 'completed',
    ]);

    $nonSearchableDoc = PdfDocument::factory()->create([
        'title' => 'Aviation Maintenance Guide',
        'is_searchable' => false,
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/search?q=aviation');

    $response->assertStatus(200);

    $titles = collect($response->json('data'))->pluck('title')->toArray();
    expect($titles)->toContain('Aviation Safety Manual');
    expect($titles)->not->toContain('Aviation Maintenance Guide');
});
