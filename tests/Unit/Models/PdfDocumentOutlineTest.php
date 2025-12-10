<?php

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;

beforeEach(function () {
    $this->document = PdfDocument::create([
        'hash' => hash('sha256', uniqid().time()),
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
});

it('outline belongs to pdf document', function () {
    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    expect($outline->pdfDocument)->toBeInstanceOf(PdfDocument::class);
    expect($outline->pdfDocument->id)->toBe($this->document->id);
});

it('outline can have parent', function () {
    $parentOutline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Parent Chapter',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    $childOutline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $parentOutline->id,
        'title' => 'Child Section',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    expect($childOutline->parent)->toBeInstanceOf(PdfDocumentOutline::class);
    expect($childOutline->parent->id)->toBe($parentOutline->id);
});

it('outline can have children', function () {
    $parentOutline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Parent Chapter',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $parentOutline->id,
        'title' => 'Child Section 1',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $parentOutline->id,
        'title' => 'Child Section 2',
        'level' => 1,
        'destination_page' => 10,
        'order_index' => 1,
    ]);

    expect($parentOutline->children)->toHaveCount(2);
});

it('children are ordered by order index', function () {
    $parentOutline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Parent',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    // Create children in reverse order
    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $parentOutline->id,
        'title' => 'Second',
        'level' => 1,
        'destination_page' => 10,
        'order_index' => 1,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $parentOutline->id,
        'title' => 'First',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    $children = $parentOutline->children;

    expect($children[0]->title)->toBe('First');
    expect($children[1]->title)->toBe('Second');
});

it('root scope returns only top level entries', function () {
    $rootOutline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Root',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $rootOutline->id,
        'title' => 'Child',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    $rootEntries = PdfDocumentOutline::where('pdf_document_id', $this->document->id)
        ->root()
        ->get();

    expect($rootEntries)->toHaveCount(1);
    expect($rootEntries->first()->title)->toBe('Root');
});

it('at level scope filters by level', function () {
    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Level 0',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Level 1 A',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Level 1 B',
        'level' => 1,
        'destination_page' => 10,
        'order_index' => 1,
    ]);

    $level1Entries = PdfDocumentOutline::where('pdf_document_id', $this->document->id)
        ->atLevel(1)
        ->get();

    expect($level1Entries)->toHaveCount(2);
});

it('gets title path attribute', function () {
    $level0 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    $level1 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $level0->id,
        'title' => 'Section 1.1',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    $level2 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $level1->id,
        'title' => 'Subsection 1.1.1',
        'level' => 2,
        'destination_page' => 7,
        'order_index' => 0,
    ]);

    $path = $level2->title_path;

    expect($path)->toHaveCount(3);
    expect($path[0])->toBe('Chapter 1');
    expect($path[1])->toBe('Section 1.1');
    expect($path[2])->toBe('Subsection 1.1.1');
});

it('gets tree for document returns hierarchical structure', function () {
    $chapter1 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $chapter1->id,
        'title' => 'Section 1.1',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    $chapter2 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 2',
        'level' => 0,
        'destination_page' => 20,
        'order_index' => 1,
    ]);

    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);

    expect($tree)->toHaveCount(2);
    expect($tree[0]['title'])->toBe('Chapter 1');
    expect($tree[0]['children'])->toHaveCount(1);
    expect($tree[0]['children'][0]['title'])->toBe('Section 1.1');
    expect($tree[1]['title'])->toBe('Chapter 2');
    expect($tree[1]['children'])->toBeEmpty();
});

it('casts are applied correctly', function () {
    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Test',
        'level' => '2',
        'destination_page' => '10',
        'order_index' => '5',
    ]);

    expect($outline->level)->toBeInt();
    expect($outline->destination_page)->toBeInt();
    expect($outline->order_index)->toBeInt();
});
