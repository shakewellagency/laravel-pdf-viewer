<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Models;

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PdfDocumentLinkTest extends TestCase
{
    protected function createTestDocument(): PdfDocument
    {
        return PdfDocument::create([
            'hash' => hash('sha256', uniqid() . time()),
            'title' => 'Test Document',
            'filename' => 'test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_path' => 'pdf-documents/test.pdf',
            'file_size' => 1024000,
            'page_count' => 10,
            'status' => 'completed',
            'is_searchable' => true,
        ]);
    }

    public function test_link_belongs_to_pdf_document(): void
    {
        $document = $this->createTestDocument();
        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        $this->assertInstanceOf(PdfDocument::class, $link->pdfDocument);
        $this->assertEquals($document->id, $link->pdfDocument->id);
    }

    public function test_is_internal_returns_true_for_internal_links(): void
    {
        $document = $this->createTestDocument();
        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        $this->assertTrue($link->isInternal());
        $this->assertFalse($link->isExternal());
    }

    public function test_is_external_returns_true_for_external_links(): void
    {
        $document = $this->createTestDocument();
        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => 'https://example.com',
        ]);

        $this->assertTrue($link->isExternal());
        $this->assertFalse($link->isInternal());
    }

    public function test_get_absolute_coordinates_attribute(): void
    {
        $document = $this->createTestDocument();
        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
            'source_rect_x' => 100.5,
            'source_rect_y' => 200.75,
            'source_rect_width' => 150.25,
            'source_rect_height' => 20.50,
        ]);

        $coords = $link->absolute_coordinates;

        $this->assertEquals(100.5, $coords['x']);
        $this->assertEquals(200.75, $coords['y']);
        $this->assertEquals(150.25, $coords['width']);
        $this->assertEquals(20.50, $coords['height']);
    }

    public function test_get_normalized_coordinates_attribute(): void
    {
        $document = $this->createTestDocument();
        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
            'coord_x_percent' => 16.34,
            'coord_y_percent' => 25.38,
            'coord_width_percent' => 24.51,
            'coord_height_percent' => 2.59,
        ]);

        $coords = $link->normalized_coordinates;

        $this->assertEquals(16.34, $coords['x_percent']);
        $this->assertEquals(25.38, $coords['y_percent']);
        $this->assertEquals(24.51, $coords['width_percent']);
        $this->assertEquals(2.59, $coords['height_percent']);
    }

    public function test_internal_scope_filters_internal_links(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => 'https://example.com',
        ]);

        $internalLinks = PdfDocumentLink::where('pdf_document_id', $document->id)
            ->internal()
            ->get();

        $this->assertCount(1, $internalLinks);
        $this->assertEquals(PdfDocumentLink::TYPE_INTERNAL, $internalLinks->first()->type);
    }

    public function test_external_scope_filters_external_links(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => 'https://example.com',
        ]);

        $externalLinks = PdfDocumentLink::where('pdf_document_id', $document->id)
            ->external()
            ->get();

        $this->assertCount(1, $externalLinks);
        $this->assertEquals(PdfDocumentLink::TYPE_EXTERNAL, $externalLinks->first()->type);
    }

    public function test_for_page_scope_filters_by_source_page(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 2,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 10,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => 'https://example.com',
        ]);

        $page1Links = PdfDocumentLink::where('pdf_document_id', $document->id)
            ->forPage(1)
            ->get();

        $this->assertCount(2, $page1Links);
    }

    public function test_pointing_to_page_scope_filters_internal_links_by_destination(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 2,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 3,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 10,
        ]);

        $linksToPage5 = PdfDocumentLink::where('pdf_document_id', $document->id)
            ->pointingToPage(5)
            ->get();

        $this->assertCount(2, $linksToPage5);
    }

    public function test_get_grouped_by_page_for_document(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 5,
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 1,
            'type' => PdfDocumentLink::TYPE_EXTERNAL,
            'destination_url' => 'https://example.com',
        ]);

        PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => 3,
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => 10,
        ]);

        $grouped = PdfDocumentLink::getGroupedByPageForDocument($document->id);

        $this->assertArrayHasKey(1, $grouped);
        $this->assertArrayHasKey(3, $grouped);
        $this->assertCount(2, $grouped[1]);
        $this->assertCount(1, $grouped[3]);
    }

    public function test_type_constants_are_defined(): void
    {
        $this->assertEquals('internal', PdfDocumentLink::TYPE_INTERNAL);
        $this->assertEquals('external', PdfDocumentLink::TYPE_EXTERNAL);
        $this->assertEquals('unknown', PdfDocumentLink::TYPE_UNKNOWN);
    }

    public function test_casts_are_applied_correctly(): void
    {
        $document = $this->createTestDocument();

        $link = PdfDocumentLink::create([
            'pdf_document_id' => $document->id,
            'source_page' => '1',
            'type' => PdfDocumentLink::TYPE_INTERNAL,
            'destination_page' => '5',
            'source_rect_x' => '100.5',
            'source_rect_y' => '200.75',
        ]);

        $this->assertIsInt($link->source_page);
        $this->assertIsInt($link->destination_page);
        $this->assertIsFloat($link->source_rect_x);
        $this->assertIsFloat($link->source_rect_y);
    }
}
