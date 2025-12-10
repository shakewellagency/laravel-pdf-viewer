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

// ========== Edge Case Tests ==========

it('handles very deep nesting (5+ levels)', function () {
    $levels = [];
    $previousLevel = null;

    // Create 6 levels of nesting (0-5)
    for ($i = 0; $i <= 5; $i++) {
        $levels[$i] = PdfDocumentOutline::create([
            'pdf_document_id' => $this->document->id,
            'parent_id' => $previousLevel?->id,
            'title' => "Level $i",
            'level' => $i,
            'destination_page' => $i + 1,
            'order_index' => 0,
        ]);
        $previousLevel = $levels[$i];
    }

    // Verify the deepest level can access its path
    $deepestLevel = $levels[5];
    $path = $deepestLevel->title_path;

    expect($path)->toHaveCount(6);
    expect($path[0])->toBe('Level 0');
    expect($path[5])->toBe('Level 5');

    // Verify tree structure builds correctly
    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);

    expect($tree)->toHaveCount(1);
    expect($tree[0]['title'])->toBe('Level 0');
    expect($tree[0]['children'])->toHaveCount(1);

    // Navigate through all levels
    $current = $tree[0];
    for ($i = 1; $i <= 5; $i++) {
        expect($current['children'][0]['title'])->toBe("Level $i");
        $current = $current['children'][0];
    }
});

it('handles document with no outline entries', function () {
    // Document has no outlines
    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);

    expect($tree)->toBeArray();
    expect($tree)->toBeEmpty();
});

it('handles multiple root level entries', function () {
    // Create 5 root level entries
    for ($i = 0; $i < 5; $i++) {
        PdfDocumentOutline::create([
            'pdf_document_id' => $this->document->id,
            'title' => "Chapter $i",
            'level' => 0,
            'destination_page' => $i * 10 + 1,
            'order_index' => $i,
        ]);
    }

    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);

    expect($tree)->toHaveCount(5);
    expect($tree[0]['title'])->toBe('Chapter 0');
    expect($tree[4]['title'])->toBe('Chapter 4');
});

it('handles complex mixed hierarchy', function () {
    // Create a complex structure:
    // Chapter 1 -> Section 1.1, Section 1.2 -> Subsection 1.2.1
    // Chapter 2 -> Section 2.1

    $ch1 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $ch1->id,
        'title' => 'Section 1.1',
        'level' => 1,
        'destination_page' => 5,
        'order_index' => 0,
    ]);

    $sec12 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $ch1->id,
        'title' => 'Section 1.2',
        'level' => 1,
        'destination_page' => 10,
        'order_index' => 1,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $sec12->id,
        'title' => 'Subsection 1.2.1',
        'level' => 2,
        'destination_page' => 12,
        'order_index' => 0,
    ]);

    $ch2 = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => 'Chapter 2',
        'level' => 0,
        'destination_page' => 20,
        'order_index' => 1,
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'parent_id' => $ch2->id,
        'title' => 'Section 2.1',
        'level' => 1,
        'destination_page' => 25,
        'order_index' => 0,
    ]);

    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);

    expect($tree)->toHaveCount(2);
    expect($tree[0]['children'])->toHaveCount(2);
    expect($tree[0]['children'][1]['children'])->toHaveCount(1);
    expect($tree[0]['children'][1]['children'][0]['title'])->toBe('Subsection 1.2.1');
    expect($tree[1]['children'])->toHaveCount(1);
});

it('handles special characters in title', function () {
    $specialTitle = "Chapter & Section <test> \"quotes\" 'apostrophes' </end>";

    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => $specialTitle,
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    expect($outline->title)->toBe($specialTitle);

    $tree = PdfDocumentOutline::getTreeForDocument($this->document->id);
    expect($tree[0]['title'])->toBe($specialTitle);
});

it('handles unicode characters in title', function () {
    $unicodeTitle = "章节 1: Введение - المقدمة - 日本語タイトル";

    $outline = PdfDocumentOutline::create([
        'pdf_document_id' => $this->document->id,
        'title' => $unicodeTitle,
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    expect($outline->title)->toBe($unicodeTitle);
});
