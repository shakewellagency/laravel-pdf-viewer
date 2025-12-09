<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Models;

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PdfDocumentOutlineTest extends TestCase
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

    public function test_outline_belongs_to_pdf_document(): void
    {
        $document = $this->createTestDocument();
        $outline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Chapter 1',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        $this->assertInstanceOf(PdfDocument::class, $outline->pdfDocument);
        $this->assertEquals($document->id, $outline->pdfDocument->id);
    }

    public function test_outline_can_have_parent(): void
    {
        $document = $this->createTestDocument();

        $parentOutline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Parent Chapter',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        $childOutline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $parentOutline->id,
            'title' => 'Child Section',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        $this->assertInstanceOf(PdfDocumentOutline::class, $childOutline->parent);
        $this->assertEquals($parentOutline->id, $childOutline->parent->id);
    }

    public function test_outline_can_have_children(): void
    {
        $document = $this->createTestDocument();

        $parentOutline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Parent Chapter',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $parentOutline->id,
            'title' => 'Child Section 1',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $parentOutline->id,
            'title' => 'Child Section 2',
            'level' => 1,
            'destination_page' => 10,
            'order_index' => 1,
        ]);

        $this->assertCount(2, $parentOutline->children);
    }

    public function test_children_are_ordered_by_order_index(): void
    {
        $document = $this->createTestDocument();

        $parentOutline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Parent',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        // Create children in reverse order
        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $parentOutline->id,
            'title' => 'Second',
            'level' => 1,
            'destination_page' => 10,
            'order_index' => 1,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $parentOutline->id,
            'title' => 'First',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        $children = $parentOutline->children;

        $this->assertEquals('First', $children[0]->title);
        $this->assertEquals('Second', $children[1]->title);
    }

    public function test_root_scope_returns_only_top_level_entries(): void
    {
        $document = $this->createTestDocument();

        $rootOutline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Root',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $rootOutline->id,
            'title' => 'Child',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        $rootEntries = PdfDocumentOutline::where('pdf_document_id', $document->id)
            ->root()
            ->get();

        $this->assertCount(1, $rootEntries);
        $this->assertEquals('Root', $rootEntries->first()->title);
    }

    public function test_at_level_scope_filters_by_level(): void
    {
        $document = $this->createTestDocument();

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Level 0',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Level 1 A',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Level 1 B',
            'level' => 1,
            'destination_page' => 10,
            'order_index' => 1,
        ]);

        $level1Entries = PdfDocumentOutline::where('pdf_document_id', $document->id)
            ->atLevel(1)
            ->get();

        $this->assertCount(2, $level1Entries);
    }

    public function test_get_title_path_attribute(): void
    {
        $document = $this->createTestDocument();

        $level0 = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Chapter 1',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        $level1 = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $level0->id,
            'title' => 'Section 1.1',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        $level2 = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $level1->id,
            'title' => 'Subsection 1.1.1',
            'level' => 2,
            'destination_page' => 7,
            'order_index' => 0,
        ]);

        $path = $level2->title_path;

        $this->assertCount(3, $path);
        $this->assertEquals('Chapter 1', $path[0]);
        $this->assertEquals('Section 1.1', $path[1]);
        $this->assertEquals('Subsection 1.1.1', $path[2]);
    }

    public function test_get_tree_for_document_returns_hierarchical_structure(): void
    {
        $document = $this->createTestDocument();

        $chapter1 = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Chapter 1',
            'level' => 0,
            'destination_page' => 1,
            'order_index' => 0,
        ]);

        PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'parent_id' => $chapter1->id,
            'title' => 'Section 1.1',
            'level' => 1,
            'destination_page' => 5,
            'order_index' => 0,
        ]);

        $chapter2 = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Chapter 2',
            'level' => 0,
            'destination_page' => 20,
            'order_index' => 1,
        ]);

        $tree = PdfDocumentOutline::getTreeForDocument($document->id);

        $this->assertCount(2, $tree);
        $this->assertEquals('Chapter 1', $tree[0]['title']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertEquals('Section 1.1', $tree[0]['children'][0]['title']);
        $this->assertEquals('Chapter 2', $tree[1]['title']);
        $this->assertEmpty($tree[1]['children']);
    }

    public function test_casts_are_applied_correctly(): void
    {
        $document = $this->createTestDocument();

        $outline = PdfDocumentOutline::create([
            'pdf_document_id' => $document->id,
            'title' => 'Test',
            'level' => '2',
            'destination_page' => '10',
            'order_index' => '5',
        ]);

        $this->assertIsInt($outline->level);
        $this->assertIsInt($outline->destination_page);
        $this->assertIsInt($outline->order_index);
    }
}
