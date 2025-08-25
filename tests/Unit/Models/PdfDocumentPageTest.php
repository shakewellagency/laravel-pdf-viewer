<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PdfDocumentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_pdf_document_page(): void
    {
        $document = PdfDocument::factory()->create();
        
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'content' => 'Test page content',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('pdf_document_pages', [
            'id' => $page->id,
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'content' => 'Test page content',
            'status' => 'completed',
        ]);
    }

    public function test_belongs_to_document(): void
    {
        $document = PdfDocument::factory()->create();
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
        ]);

        $this->assertInstanceOf(PdfDocument::class, $page->document);
        $this->assertEquals($document->id, $page->document->id);
    }

    public function test_parsed_scope(): void
    {
        PdfDocumentPage::factory()->create(['is_parsed' => true]);
        PdfDocumentPage::factory()->create(['is_parsed' => false]);

        $parsedPages = PdfDocumentPage::parsed()->get();

        $this->assertCount(1, $parsedPages);
        $this->assertTrue($parsedPages->first()->is_parsed);
    }

    public function test_completed_scope(): void
    {
        PdfDocumentPage::factory()->create(['status' => 'completed']);
        PdfDocumentPage::factory()->create(['status' => 'processing']);

        $completedPages = PdfDocumentPage::completed()->get();

        $this->assertCount(1, $completedPages);
        $this->assertEquals('completed', $completedPages->first()->status);
    }

    public function test_failed_scope(): void
    {
        PdfDocumentPage::factory()->create(['status' => 'failed']);
        PdfDocumentPage::factory()->create(['status' => 'completed']);

        $failedPages = PdfDocumentPage::failed()->get();

        $this->assertCount(1, $failedPages);
        $this->assertEquals('failed', $failedPages->first()->status);
    }

    public function test_with_content_scope(): void
    {
        PdfDocumentPage::factory()->create(['content' => 'Some content']);
        PdfDocumentPage::factory()->create(['content' => null]);
        PdfDocumentPage::factory()->create(['content' => '']);

        $pagesWithContent = PdfDocumentPage::withContent()->get();

        $this->assertCount(1, $pagesWithContent);
        $this->assertEquals('Some content', $pagesWithContent->first()->content);
    }

    public function test_for_document_scope(): void
    {
        $document1 = PdfDocument::factory()->create();
        $document2 = PdfDocument::factory()->create();
        
        PdfDocumentPage::factory()->create(['pdf_document_id' => $document1->id]);
        PdfDocumentPage::factory()->create(['pdf_document_id' => $document2->id]);

        $pagesForDocument1 = PdfDocumentPage::forDocument($document1->hash)->get();

        $this->assertCount(1, $pagesForDocument1);
        $this->assertEquals($document1->id, $pagesForDocument1->first()->pdf_document_id);
    }

    public function test_get_search_snippet_method(): void
    {
        $content = 'This is a long piece of content with the word aviation in it for testing search snippets.';
        $page = PdfDocumentPage::factory()->create(['content' => $content]);

        $snippet = $page->getSearchSnippet('aviation', 50);

        $this->assertStringContainsString('aviation', $snippet);
        $this->assertLessThanOrEqual(53, strlen($snippet)); // 50 + "..." = 53
    }

    public function test_get_search_snippet_with_no_match(): void
    {
        $content = 'This is some content without the search term.';
        $page = PdfDocumentPage::factory()->create(['content' => $content]);

        $snippet = $page->getSearchSnippet('nonexistent', 20);

        $this->assertEquals('This is some content...', $snippet);
    }

    public function test_highlight_content_method(): void
    {
        $page = PdfDocumentPage::factory()->create([
            'content' => 'This content has aviation safety information.'
        ]);

        $highlighted = $page->highlightContent('aviation');

        $this->assertEquals(
            'This content has <mark>aviation</mark> safety information.',
            $highlighted
        );
    }

    public function test_highlight_content_with_custom_tag(): void
    {
        $page = PdfDocumentPage::factory()->create([
            'content' => 'This content has aviation safety information.'
        ]);

        $highlighted = $page->highlightContent('aviation', 'strong');

        $this->assertEquals(
            'This content has <strong>aviation</strong> safety information.',
            $highlighted
        );
    }

    public function test_get_content_length_attribute(): void
    {
        $page = PdfDocumentPage::factory()->create([
            'content' => '<p>This is <strong>HTML</strong> content.</p>'
        ]);

        $this->assertEquals(25, $page->getContentLengthAttribute()); // Length without HTML tags
    }

    public function test_get_word_count_attribute(): void
    {
        $page = PdfDocumentPage::factory()->create([
            'content' => '<p>This is a test content with HTML tags.</p>'
        ]);

        $this->assertEquals(9, $page->getWordCountAttribute());
    }

    public function test_has_content_method(): void
    {
        $pageWithContent = PdfDocumentPage::factory()->create(['content' => 'Some content']);
        $pageWithoutContent = PdfDocumentPage::factory()->create(['content' => null]);
        $pageWithEmptyContent = PdfDocumentPage::factory()->create(['content' => '   ']);

        $this->assertTrue($pageWithContent->hasContent());
        $this->assertFalse($pageWithoutContent->hasContent());
        $this->assertFalse($pageWithEmptyContent->hasContent());
    }

    public function test_has_thumbnail_method(): void
    {
        $pageWithThumbnail = PdfDocumentPage::factory()->create([
            'thumbnail_path' => 'thumbnails/test.jpg'
        ]);
        $pageWithoutThumbnail = PdfDocumentPage::factory()->create([
            'thumbnail_path' => null
        ]);

        // Note: This will return false since we're not actually creating files
        $this->assertFalse($pageWithThumbnail->hasThumbnail());
        $this->assertFalse($pageWithoutThumbnail->hasThumbnail());
    }

    public function test_should_be_searchable_method(): void
    {
        $document = PdfDocument::factory()->create(['is_searchable' => true]);
        $searchablePage = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'is_parsed' => true,
            'content' => 'Some content',
            'status' => 'completed',
        ]);

        $nonSearchablePage = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'is_parsed' => false,
        ]);

        $this->assertTrue($searchablePage->shouldBeSearchable());
        $this->assertFalse($nonSearchablePage->shouldBeSearchable());
    }

    public function test_to_searchable_array_method(): void
    {
        $document = PdfDocument::factory()->create([
            'hash' => 'test-hash',
            'title' => 'Test Document',
        ]);
        
        $page = PdfDocumentPage::factory()->create([
            'pdf_document_id' => $document->id,
            'page_number' => 1,
            'content' => 'Test content',
        ]);

        $searchableArray = $page->toSearchableArray();

        $this->assertArrayHasKey('id', $searchableArray);
        $this->assertArrayHasKey('document_hash', $searchableArray);
        $this->assertArrayHasKey('document_title', $searchableArray);
        $this->assertArrayHasKey('page_number', $searchableArray);
        $this->assertArrayHasKey('content', $searchableArray);
        
        $this->assertEquals('test-hash', $searchableArray['document_hash']);
        $this->assertEquals('Test Document', $searchableArray['document_title']);
        $this->assertEquals(1, $searchableArray['page_number']);
        $this->assertEquals('Test content', $searchableArray['content']);
    }

    public function test_casts_metadata_as_array(): void
    {
        $metadata = ['width' => 800, 'height' => 600];
        $page = PdfDocumentPage::factory()->create(['metadata' => $metadata]);

        $this->assertIsArray($page->metadata);
        $this->assertEquals(800, $page->metadata['width']);
        $this->assertEquals(600, $page->metadata['height']);
    }

    public function test_soft_deletes(): void
    {
        $page = PdfDocumentPage::factory()->create();
        
        $page->delete();

        $this->assertSoftDeleted($page);
        $this->assertCount(0, PdfDocumentPage::all());
        $this->assertCount(1, PdfDocumentPage::withTrashed()->get());
    }

    public function test_uses_uuid_for_primary_key(): void
    {
        $page = PdfDocumentPage::factory()->create();

        $this->assertIsString($page->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $page->id
        );
    }
}