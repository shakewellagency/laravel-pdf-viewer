<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Mockery;
use Shakewellagency\LaravelPdfViewer\Services\PDFOutlineExtractor;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\Header;

class PDFOutlineExtractorTest extends TestCase
{
    protected PDFOutlineExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new PDFOutlineExtractor();
    }

    public function test_extract_returns_empty_array_for_nonexistent_file(): void
    {
        $result = $this->extractor->extract('/nonexistent/path/to/file.pdf');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extract_returns_empty_array_for_invalid_pdf(): void
    {
        // Create a temp file with invalid PDF content
        $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($tempFile, 'This is not a PDF file');

        try {
            $result = $this->extractor->extract($tempFile);
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_extract_handles_pdf_without_outline(): void
    {
        // Create a minimal PDF without outline
        $pdfContent = $this->generateMinimalPdfContent();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($tempFile, $pdfContent);

        try {
            $result = $this->extractor->extract($tempFile);
            $this->assertIsArray($result);
            // A minimal PDF without outline should return empty array
            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_flatten_returns_flat_array_with_levels(): void
    {
        $hierarchicalOutline = [
            [
                'title' => 'Chapter 1',
                'level' => 0,
                'destination_page' => 1,
                'children' => [
                    [
                        'title' => 'Section 1.1',
                        'level' => 1,
                        'destination_page' => 5,
                        'children' => [],
                    ],
                    [
                        'title' => 'Section 1.2',
                        'level' => 1,
                        'destination_page' => 10,
                        'children' => [],
                    ],
                ],
            ],
            [
                'title' => 'Chapter 2',
                'level' => 0,
                'destination_page' => 15,
                'children' => [],
            ],
        ];

        $result = $this->extractor->flatten($hierarchicalOutline);

        $this->assertCount(4, $result);

        // Check first item
        $this->assertEquals('Chapter 1', $result[0]['title']);
        $this->assertEquals(0, $result[0]['level']);
        $this->assertEquals(1, $result[0]['destination_page']);

        // Check nested items
        $this->assertEquals('Section 1.1', $result[1]['title']);
        $this->assertEquals(1, $result[1]['level']);
        $this->assertEquals(5, $result[1]['destination_page']);

        $this->assertEquals('Section 1.2', $result[2]['title']);
        $this->assertEquals(1, $result[2]['level']);

        // Check last item
        $this->assertEquals('Chapter 2', $result[3]['title']);
        $this->assertEquals(0, $result[3]['level']);
    }

    public function test_flatten_returns_empty_array_for_empty_outline(): void
    {
        $result = $this->extractor->flatten([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_flatten_handles_deeply_nested_structure(): void
    {
        $deepOutline = [
            [
                'title' => 'Level 0',
                'level' => 0,
                'destination_page' => 1,
                'children' => [
                    [
                        'title' => 'Level 1',
                        'level' => 1,
                        'destination_page' => 2,
                        'children' => [
                            [
                                'title' => 'Level 2',
                                'level' => 2,
                                'destination_page' => 3,
                                'children' => [
                                    [
                                        'title' => 'Level 3',
                                        'level' => 3,
                                        'destination_page' => 4,
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->extractor->flatten($deepOutline);

        $this->assertCount(4, $result);

        // Verify levels are correct
        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals($i, $result[$i]['level']);
            $this->assertEquals("Level $i", $result[$i]['title']);
            $this->assertEquals($i + 1, $result[$i]['destination_page']);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
