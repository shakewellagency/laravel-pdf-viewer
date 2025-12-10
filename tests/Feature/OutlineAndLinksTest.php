<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;

// ========== Outline Tests ==========

it('can retrieve document outline', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    // Create outline entries
    $chapter1 = PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'destination_type' => 'page',
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $document->id,
        'parent_id' => $chapter1->id,
        'title' => 'Section 1.1',
        'level' => 1,
        'destination_page' => 5,
        'destination_type' => 'page',
        'order_index' => 0,
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/outline");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'level',
                    'destination_page',
                    'destination_type',
                ],
            ],
            'meta' => [
                'document_hash',
                'has_outline',
                'total_entries',
                'max_depth',
            ],
        ]);

    expect($response->json('meta.has_outline'))->toBeTrue();
    expect($response->json('meta.total_entries'))->toBe(2);
});

it('returns empty outline for document without TOC', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/outline");

    $response->assertStatus(200);
    expect($response->json('meta.has_outline'))->toBeFalse();
    expect($response->json('data'))->toBeEmpty();
});

it('outline returns 404 for nonexistent document', function () {
    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/documents/nonexistent-hash/outline');

    $response->assertStatus(404);
});

// ========== Document Links Tests ==========

it('can retrieve document links', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    // Create some links
    PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_page' => 5,
        'destination_type' => 'page',
        'type' => 'internal',
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 2,
        'source_rect_x' => 150.0,
        'source_rect_y' => 300.0,
        'source_rect_width' => 100.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 24.51,
        'coord_y_percent' => 37.89,
        'coord_width_percent' => 16.34,
        'coord_height_percent' => 2.53,
        'destination_url' => 'https://example.com',
        'destination_type' => 'external',
        'type' => 'external',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/links");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'summary' => [
                    'total_links',
                    'internal_links',
                    'external_links',
                    'pages_with_links',
                ],
                'links_by_page',
            ],
            'meta' => [
                'document_hash',
                'has_links',
            ],
        ]);

    expect($response->json('data.summary.total_links'))->toBe(2);
    expect($response->json('data.summary.internal_links'))->toBe(1);
    expect($response->json('data.summary.external_links'))->toBe(1);
    expect($response->json('meta.has_links'))->toBeTrue();
});

it('returns empty links for document without links', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/links");

    $response->assertStatus(200);
    expect($response->json('meta.has_links'))->toBeFalse();
    expect($response->json('data.summary.total_links'))->toBe(0);
});

it('document links returns 404 for nonexistent document', function () {
    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/documents/nonexistent-hash/links');

    $response->assertStatus(404);
});

// ========== Page Links Tests ==========

it('can retrieve page links', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'status' => 'completed',
    ]);

    // Create links for page 1
    PdfDocumentLink::create([
        'pdf_document_id' => $document->id,
        'source_page' => 1,
        'source_rect_x' => 100.0,
        'source_rect_y' => 200.0,
        'source_rect_width' => 50.0,
        'source_rect_height' => 20.0,
        'coord_x_percent' => 16.34,
        'coord_y_percent' => 25.31,
        'coord_width_percent' => 8.17,
        'coord_height_percent' => 2.53,
        'destination_page' => 5,
        'destination_type' => 'page',
        'type' => 'internal',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/1/links");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'source_page',
                    'rect',
                    'normalized_rect',
                ],
            ],
            'meta' => [
                'document_hash',
                'page_number',
                'total_links',
            ],
        ]);

    expect($response->json('meta.page_number'))->toBe(1);
    expect($response->json('meta.total_links'))->toBe(1);
});

it('returns empty links for page without links', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $page = PdfDocumentPage::factory()->create([
        'pdf_document_id' => $document->id,
        'page_number' => 1,
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/1/links");

    $response->assertStatus(200);
    expect($response->json('meta.total_links'))->toBe(0);
    expect($response->json('data'))->toBeEmpty();
});

it('page links returns 404 for nonexistent document', function () {
    $response = $this->actingAsUser()
        ->getJson('/api/pdf-viewer/documents/nonexistent-hash/pages/1/links');

    $response->assertStatus(404);
});

it('page links returns 404 for nonexistent page', function () {
    $document = PdfDocument::factory()->create([
        'status' => 'completed',
    ]);

    $response = $this->actingAsUser()
        ->getJson("/api/pdf-viewer/documents/{$document->hash}/pages/999/links");

    $response->assertStatus(404);
});
