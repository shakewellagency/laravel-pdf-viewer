<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\PageProcessingService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PageProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PageProcessingService $pageProcessingService;

    protected PdfDocument $document;

    protected PdfDocumentPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageProcessingService = new PageProcessingService;

        // Create a test document
        $this->document = PdfDocument::create([
            'hash' => 'test-hash-123',
            'title' => 'Test PDF Document',
            'filename' => 'test.pdf',
            'file_path' => 'pdf-documents/test.pdf',
            'page_count' => 3,
            'status' => 'uploaded',
        ]);

        // Create a test page
        $this->page = PdfDocumentPage::create([
            'pdf_document_id' => $this->document->id,
            'page_number' => 1,
            'status' => 'pending',
            'content' => '',
        ]);

        // Create test PDF file in storage
        $pdfContent = $this->generateTestPdfWithText();
        Storage::disk('testing')->put($this->document->file_path, $pdfContent);
    }

    /** @test */
    public function it_can_extract_page_locally_with_pdftk_available()
    {
        // Mock pdftk availability
        $service = Mockery::mock(PageProcessingService::class)->makePartial();
        $service->shouldReceive('isPdftkAvailable')->andReturn(true);
        $service->shouldReceive('exec')->andReturn(0); // Success return code

        $pagePath = $service->extractPage($this->document, 1);

        $this->assertStringContains('pdf-pages/test-hash-123/page_1.pdf', $pagePath);
    }

    /** @test */
    public function it_can_extract_page_locally_without_pdftk()
    {
        // Test the fallback method when pdftk is not available
        $pagePath = $this->pageProcessingService->extractPage($this->document, 1);

        $this->assertStringContains('pdf-pages/test-hash-123/page_1.pdf', $pagePath);

        // Check that the page file was created (fallback copies entire PDF)
        $pageFilePath = storage_path('app/pdf-pages/test-hash-123/page_1.pdf');
        $this->assertTrue(file_exists($pageFilePath));

        // Check that reference file was created
        $referenceFilePath = $pageFilePath.'.ref';
        $this->assertTrue(file_exists($referenceFilePath));

        $referenceData = json_decode(file_get_contents($referenceFilePath), true);
        $this->assertEquals('page_reference', $referenceData['type']);
        $this->assertEquals('test-hash-123', $referenceData['document_hash']);
        $this->assertEquals(1, $referenceData['page_number']);
    }

    /** @test */
    public function it_can_extract_text_from_page_path()
    {
        // First extract the page
        $pagePath = $this->pageProcessingService->extractPage($this->document, 1);

        // Now extract text from the page
        $text = $this->pageProcessingService->extractText($pagePath);

        $this->assertNotEmpty($text);
        $this->assertStringContains('Test PDF Content Page 1', $text);
        $this->assertGreaterThan(10, strlen($text));
    }

    /** @test */
    public function it_extracts_text_from_specific_page_number()
    {
        // Create multi-page document
        $multiPagePdf = $this->generateTestPdfWithMultiplePages();
        Storage::disk('testing')->put($this->document->file_path, $multiPagePdf);

        // Extract text from page 2
        $pagePath = $this->pageProcessingService->getPageFilePath('test-hash-123', 2);
        $text = $this->pageProcessingService->extractText($pagePath);

        $this->assertNotEmpty($text);
        $this->assertStringContains('Page 2', $text);
    }

    /** @test */
    public function it_handles_invalid_page_file_path()
    {
        $text = $this->pageProcessingService->extractText('invalid/path/format');

        $this->assertEmpty($text);
    }

    /** @test */
    public function it_handles_non_existent_document_hash()
    {
        $text = $this->pageProcessingService->extractText('pdf-pages/non-existent-hash/page_1.pdf');

        $this->assertEmpty($text);
    }

    /** @test */
    public function it_handles_invalid_page_number_in_filename()
    {
        $text = $this->pageProcessingService->extractText('pdf-pages/test-hash-123/invalid_filename.pdf');

        $this->assertEmpty($text);
    }

    /** @test */
    public function it_returns_empty_for_page_number_beyond_document_pages()
    {
        // Document has 3 pages, try to extract page 5
        $pagePath = $this->pageProcessingService->getPageFilePath('test-hash-123', 5);
        $text = $this->pageProcessingService->extractText($pagePath);

        $this->assertEmpty($text);
    }

    /** @test */
    public function it_cleans_extracted_text_properly()
    {
        // Create PDF with problematic characters
        $textWithIssues = "Test content\x00\xEF\xBF\xBD with   excessive   whitespace\n\nand\r\nline breaks";
        $reflection = new \ReflectionClass($this->pageProcessingService);
        $method = $reflection->getMethod('cleanExtractedText');
        $method->setAccessible(true);

        $cleanedText = $method->invoke($this->pageProcessingService, $textWithIssues);

        $this->assertStringNotContains("\x00", $cleanedText);
        $this->assertStringNotContains("\xEF\xBF\xBD", $cleanedText);
        $this->assertEquals('Test content with excessive whitespace and line breaks', $cleanedText);
    }

    /** @test */
    public function it_can_update_page_content_with_metadata()
    {
        $content = 'Extracted text content for testing';

        $result = $this->pageProcessingService->updatePageContent($this->page, $content);

        $this->assertTrue($result);

        $this->page->refresh();
        $this->assertEquals($content, $this->page->content);
        $this->assertEquals('completed', $this->page->status);
        $this->assertTrue($this->page->is_parsed);

        // Check metadata
        $this->assertArrayHasKey('text_extracted_at', $this->page->metadata);
        $this->assertArrayHasKey('content_length', $this->page->metadata);
        $this->assertArrayHasKey('word_count', $this->page->metadata);
        $this->assertEquals(strlen($content), $this->page->metadata['content_length']);
        $this->assertEquals(str_word_count($content), $this->page->metadata['word_count']);
    }

    /** @test */
    public function it_can_mark_page_as_processed()
    {
        $this->assertEquals('pending', $this->page->status);
        $this->assertFalse($this->page->is_parsed);

        $this->pageProcessingService->markPageProcessed($this->page);

        $this->page->refresh();
        $this->assertEquals('completed', $this->page->status);
        $this->assertTrue($this->page->is_parsed);
    }

    /** @test */
    public function it_can_handle_page_failure()
    {
        $errorMessage = 'Test error message';

        $this->pageProcessingService->handlePageFailure($this->page, $errorMessage);

        $this->page->refresh();
        $this->assertEquals('failed', $this->page->status);
        $this->assertEquals($errorMessage, $this->page->processing_error);
    }

    /** @test */
    public function it_can_create_page_record()
    {
        $content = 'Initial content';

        $newPage = $this->pageProcessingService->createPage($this->document, 2, $content);

        $this->assertEquals(2, $newPage->page_number);
        $this->assertEquals($content, $newPage->content);
        $this->assertEquals('pending', $newPage->status);
        $this->assertTrue($newPage->is_parsed); // Because content is provided
        $this->assertEquals($this->document->id, $newPage->pdf_document_id);
    }

    /** @test */
    public function it_generates_correct_page_file_path()
    {
        $path = $this->pageProcessingService->getPageFilePath('test-hash-456', 3);

        $this->assertEquals('pdf-pages/test-hash-456/page_3.pdf', $path);
    }

    /** @test */
    public function it_validates_page_file_existence()
    {
        // Create actual page file for validation
        $pagePath = $this->pageProcessingService->extractPage($this->document, 1);

        $isValid = $this->pageProcessingService->validatePageFile($pagePath);
        $this->assertTrue($isValid);

        // Test non-existent file
        $isValidNonExistent = $this->pageProcessingService->validatePageFile('pdf-pages/fake/page_99.pdf');
        $this->assertFalse($isValidNonExistent);
    }

    /** @test */
    public function it_can_cleanup_page_files()
    {
        // Create some page files
        $this->pageProcessingService->extractPage($this->document, 1);
        $this->pageProcessingService->extractPage($this->document, 2);

        $pageDir = storage_path('app/pdf-pages/test-hash-123');
        $this->assertTrue(is_dir($pageDir));

        $result = $this->pageProcessingService->cleanupPageFiles('test-hash-123');

        $this->assertTrue($result);
        $this->assertFalse(is_dir($pageDir));
    }

    /** @test */
    public function it_logs_extraction_steps_for_debugging()
    {
        Log::spy();

        $pagePath = $this->pageProcessingService->getPageFilePath('test-hash-123', 1);
        $this->pageProcessingService->extractText($pagePath);

        Log::shouldHaveReceived('info')
            ->with('Starting extractText with improved page-aware parsing', Mockery::type('array'))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Path parsing', Mockery::type('array'))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Page number extracted', Mockery::type('array'))
            ->once();
    }

    /** @test */
    public function it_handles_pdf_parsing_errors_gracefully()
    {
        // Create invalid PDF content
        Storage::disk('testing')->put($this->document->file_path, 'Invalid PDF content');

        $pagePath = $this->pageProcessingService->getPageFilePath('test-hash-123', 1);
        $text = $this->pageProcessingService->extractText($pagePath);

        $this->assertEmpty($text);
    }

    /** @test */
    public function it_handles_s3_storage_detection()
    {
        // Test S3 disk detection
        $reflection = new \ReflectionClass($this->pageProcessingService);
        $method = $reflection->getMethod('isS3Disk');
        $method->setAccessible(true);

        $testDisk = Storage::disk('testing');
        $isS3 = $method->invoke($this->pageProcessingService, $testDisk);

        $this->assertFalse($isS3); // Testing disk is not S3
    }

    /** @test */
    public function it_processes_utf8_text_correctly()
    {
        // Test with Unicode content
        $unicodeContent = 'Test with émojis 🚀 and special characters: café, naïve, résumé';

        $reflection = new \ReflectionClass($this->pageProcessingService);
        $method = $reflection->getMethod('cleanExtractedText');
        $method->setAccessible(true);

        $cleanedText = $method->invoke($this->pageProcessingService, $unicodeContent);

        $this->assertTrue(mb_check_encoding($cleanedText, 'UTF-8'));
        $this->assertStringContains('émojis', $cleanedText);
        $this->assertStringContains('🚀', $cleanedText);
    }

    /**
     * Generate a test PDF with readable text content
     */
    protected function generateTestPdfWithText(): string
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
    protected function generateTestPdfWithMultiplePages(): string
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
}
