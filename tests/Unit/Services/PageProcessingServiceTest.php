<?php

use Illuminate\Support\Facades\Storage;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\PageProcessingService;

beforeEach(function () {
    $this->pageProcessingService = app(PageProcessingService::class);

    // Create a test document
    $this->document = PdfDocument::create([
        'hash' => 'test-hash-123',
        'title' => 'Test PDF Document',
        'filename' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'file_path' => 'pdf-documents/test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'page_count' => 3,
        'status' => 'uploaded',
    ]);

    // Create a test page
    $this->page = PdfDocumentPage::create([
        'pdf_document_id' => $this->document->id,
        'page_number' => 1,
        'status' => 'pending',
        'content' => '',
        'is_parsed' => false,
    ]);

    // Create test PDF file in storage
    $pdfContent = generateTestPdfWithText();
    Storage::disk('testing')->put($this->document->file_path, $pdfContent);
});

it('can extract page locally with pdftk available')
    ->skip('Requires pdftk and FPDI dependencies not available in CI');

it('can extract page locally without pdftk')
    ->skip('Requires FPDI library and PDF file parsing not available in CI');

it('can extract text from page path')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('extracts text from specific page number')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('handles invalid page file path')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('handles non existent document hash')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('handles invalid page number in filename')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('returns empty for page number beyond document pages')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('cleans extracted text properly', function () {
    // Create PDF with problematic characters (null bytes and excessive whitespace)
    $textWithIssues = "Test content\x00 with   excessive   whitespace\n\nand\r\nline breaks";
    $reflection = new \ReflectionClass($this->pageProcessingService);
    $method = $reflection->getMethod('cleanExtractedText');
    $method->setAccessible(true);

    $cleanedText = $method->invoke($this->pageProcessingService, $textWithIssues);

    // Implementation removes null bytes and normalizes whitespace
    expect($cleanedText)->not->toContain("\x00");
    expect($cleanedText)->toBe('Test content with excessive whitespace and line breaks');
});

it('can update page content with metadata', function () {
    $content = 'Extracted text content for testing';

    $result = $this->pageProcessingService->updatePageContent($this->page, $content);

    expect($result)->toBeTrue();

    $this->page->refresh();
    expect($this->page->content)->toBe($content);
    expect($this->page->status)->toBe('completed');
    expect($this->page->is_parsed)->toBeTrue();

    // Note: updatePageContent sets content, status, is_parsed
    // Metadata like content_length and word_count are computed properties on the model
    expect($this->page->content_length)->toBeGreaterThan(0);
    expect($this->page->word_count)->toBeGreaterThan(0);
});

it('can mark page as processed', function () {
    expect($this->page->status)->toBe('pending');

    $this->pageProcessingService->markPageProcessed($this->page);

    $this->page->refresh();
    expect($this->page->status)->toBe('completed');
    // Note: markPageProcessed only sets status, use updatePageContent for is_parsed
});

it('can handle page failure', function () {
    $errorMessage = 'Test error message';

    $this->pageProcessingService->handlePageFailure($this->page, $errorMessage);

    $this->page->refresh();
    expect($this->page->status)->toBe('failed');
    expect($this->page->processing_error)->toBe($errorMessage);
});

it('can create page record', function () {
    $content = 'Initial content';

    $newPage = $this->pageProcessingService->createPage($this->document, 2, $content);

    expect($newPage->page_number)->toBe(2);
    expect($newPage->content)->toBe($content);
    expect($newPage->status)->toBe('pending');
    // Note: createPage doesn't set is_parsed - use updatePageContent for that
    expect($newPage->pdf_document_id)->toBe($this->document->id);
});

it('generates correct page file path', function () {
    $path = $this->pageProcessingService->getPageFilePath('test-hash-456', 3);

    expect($path)->toBe('pdf-pages/test-hash-456/page_3.pdf');
});

it('validates page file existence')
    ->skip('Requires FPDI page extraction not available in CI');

it('can cleanup page files')
    ->skip('Requires FPDI page extraction not available in CI');

it('logs extraction steps for debugging')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('handles pdf parsing errors gracefully')
    ->skip('Requires pdftotext binary (poppler-utils) not available in CI');

it('handles s3 storage detection', function () {
    // Test S3 disk detection
    $reflection = new \ReflectionClass($this->pageProcessingService);
    $method = $reflection->getMethod('isS3Disk');
    $method->setAccessible(true);

    $testDisk = Storage::disk('testing');
    $isS3 = $method->invoke($this->pageProcessingService, $testDisk);

    expect($isS3)->toBeFalse(); // Testing disk is not S3
});

it('processes utf8 text correctly', function () {
    // Test with Unicode content
    $unicodeContent = 'Test with émojis 🚀 and special characters: café, naïve, résumé';

    $reflection = new \ReflectionClass($this->pageProcessingService);
    $method = $reflection->getMethod('cleanExtractedText');
    $method->setAccessible(true);

    $cleanedText = $method->invoke($this->pageProcessingService, $unicodeContent);

    expect(mb_check_encoding($cleanedText, 'UTF-8'))->toBeTrue();
    expect($cleanedText)->toContain('émojis');
    expect($cleanedText)->toContain('🚀');
});

/**
 * Generate a test PDF with readable text content
 */
function generateTestPdfWithText(): string
{
    // This generates a more complex PDF for text extraction testing
    return "%PDF-1.4\n".
           "1 0 obj\n".
           "<<\n".
           "/Type /Catalog\n".
           "/Pages 2 0 R\n".
           ">>\n".
           "endobj\n".
           "2 0 obj\n".
           "<<\n".
           "/Type /Pages\n".
           "/Kids [3 0 R]\n".
           "/Count 1\n".
           ">>\n".
           "endobj\n".
           "3 0 obj\n".
           "<<\n".
           "/Type /Page\n".
           "/Parent 2 0 R\n".
           "/MediaBox [0 0 612 792]\n".
           "/Contents 4 0 R\n".
           "/Resources <<\n".
           "  /Font <<\n".
           "    /F1 5 0 R\n".
           "  >>\n".
           ">>\n".
           ">>\n".
           "endobj\n".
           "4 0 obj\n".
           "<<\n".
           "/Length 125\n".
           ">>\n".
           "stream\n".
           "BT\n".
           "/F1 12 Tf\n".
           "100 700 Td\n".
           "(Test PDF Content Page 1) Tj\n".
           "0 -20 Td\n".
           "(This is a test document for text extraction) Tj\n".
           "0 -20 Td\n".
           "(With multiple lines of content) Tj\n".
           "ET\n".
           "endstream\n".
           "endobj\n".
           "5 0 obj\n".
           "<<\n".
           "/Type /Font\n".
           "/Subtype /Type1\n".
           "/BaseFont /Helvetica\n".
           ">>\n".
           "endobj\n".
           "xref\n".
           "0 6\n".
           "0000000000 65535 f \n".
           "0000000009 65535 n \n".
           "0000000074 65535 n \n".
           "0000000131 65535 n \n".
           "0000000331 65535 n \n".
           "0000000504 65535 n \n".
           "trailer\n".
           "<<\n".
           "/Size 6\n".
           "/Root 1 0 R\n".
           ">>\n".
           "startxref\n".
           "590\n".
           "%%EOF\n";
}

/**
 * Generate a test PDF with multiple pages
 */
function generateTestPdfWithMultiplePages(): string
{
    // Simplified multi-page PDF structure
    return "%PDF-1.4\n".
           "1 0 obj\n".
           "<< /Type /Catalog /Pages 2 0 R >>\n".
           "endobj\n".
           "2 0 obj\n".
           "<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>\n".
           "endobj\n".
           "3 0 obj\n".
           "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 5 0 R >>\n".
           "endobj\n".
           "4 0 obj\n".
           "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 6 0 R >>\n".
           "endobj\n".
           "5 0 obj\n".
           "<< /Length 40 >>\n".
           "stream\n".
           "BT\n".
           "/F1 12 Tf\n".
           "100 700 Td\n".
           "(Page 1 Content) Tj\n".
           "ET\n".
           "endstream\n".
           "endobj\n".
           "6 0 obj\n".
           "<< /Length 40 >>\n".
           "stream\n".
           "BT\n".
           "/F1 12 Tf\n".
           "100 700 Td\n".
           "(Page 2 Content) Tj\n".
           "ET\n".
           "endstream\n".
           "endobj\n".
           "xref\n".
           "0 7\n".
           "0000000000 65535 f \n".
           "0000000009 65535 n \n".
           "0000000058 65535 n \n".
           "0000000115 65535 n \n".
           "0000000201 65535 n \n".
           "0000000287 65535 n \n".
           "0000000376 65535 n \n".
           "trailer\n".
           "<< /Size 7 /Root 1 0 R >>\n".
           "startxref\n".
           "465\n".
           "%%EOF\n";
}
