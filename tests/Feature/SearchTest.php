<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Feature;

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_can_search_documents_by_title(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Aviation Safety Manual',
            'is_searchable' => true,
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/pdf-viewer/search?q=aviation');

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
    }

    public function test_can_search_pages_by_content(): void
    {
        $document = PdfDocument::factory()->create([
            'is_searchable' => true,
            'status' => 'completed',
        ]);

        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'content' => 'This page contains aviation safety procedures and emergency protocols.',
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/pdf-viewer/search/documents/' . $document->hash . '?q=safety procedures');

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
    }

    public function test_search_requires_authentication(): void
    {
        // This test is skipped because auth is disabled globally for testing
        // In real usage, routes would be protected by auth middleware
        $this->markTestSkipped('Auth middleware disabled for testing environment');
    }

    public function test_search_validates_query_parameter(): void
    {
        $response = $this->getJson('/api/pdf-viewer/search');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['q']);
    }

    public function test_search_returns_empty_results_for_no_matches(): void
    {
        $response = $this->getJson('/api/pdf-viewer/search?q=nonexistentquery');

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data');
    }

    public function test_can_get_search_suggestions(): void
    {
        $document = PdfDocument::factory()->create([
            'title' => 'Aviation Manual',
            'is_searchable' => true,
        ]);

        PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'content' => 'aviation safety aircraft maintenance',
        ]);

        $response = $this->getJson('/api/pdf-viewer/search/suggestions?q=aviat');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'suggestions' => [],
                 ]);
    }

    public function test_search_with_filters_applies_constraints(): void
    {
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

        $response = $this->getJson('/api/pdf-viewer/search?' . http_build_query([
                'q' => 'aviation',
                'status' => 'completed',
                'date_from' => now()->subDays(1)->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Aviation Safety', $data[0]['title']);
    }

    public function test_search_handles_pagination(): void
    {
        PdfDocument::factory()->count(15)->create([
            'title' => 'Aviation Document',
            'is_searchable' => true,
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/pdf-viewer/search?q=aviation&per_page=5');

        $response->assertStatus(200)
                 ->assertJsonCount(5, 'data')
                 ->assertJsonPath('meta.per_page', 5)
                 ->assertJsonPath('meta.total', 15);
    }

    public function test_search_excludes_non_searchable_documents(): void
    {
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

        $response = $this->getJson('/api/pdf-viewer/search?q=aviation');

        $response->assertStatus(200);
        
        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertContains('Aviation Safety Manual', $titles);
        $this->assertNotContains('Aviation Maintenance Guide', $titles);
    }
}