<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Mockery;
use Shakewellagency\LaravelPdfViewer\Services\PDFLinkExtractor;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class PDFLinkExtractorTest extends TestCase
{
    protected PDFLinkExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new PDFLinkExtractor();
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

    public function test_extract_for_page_returns_empty_for_nonexistent_file(): void
    {
        $result = $this->extractor->extractForPage('/nonexistent/path/to/file.pdf', 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extract_for_page_returns_empty_for_invalid_page_number(): void
    {
        $pdfContent = $this->generateMinimalPdfContent();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($tempFile, $pdfContent);

        try {
            // Request page 100 from a 1-page PDF
            $result = $this->extractor->extractForPage($tempFile, 100);
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_get_internal_links_filters_correctly(): void
    {
        $allLinks = [
            1 => [
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_INTERNAL,
                    'destination_page' => 5,
                    'destination_url' => null,
                    'coordinates' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 0, 'y_percent' => 0, 'width_percent' => 16.34, 'height_percent' => 2.53],
                ],
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_EXTERNAL,
                    'destination_page' => null,
                    'destination_url' => 'https://example.com',
                    'coordinates' => ['x' => 100, 'y' => 100, 'width' => 150, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 16.34, 'y_percent' => 12.63, 'width_percent' => 24.51, 'height_percent' => 2.53],
                ],
            ],
            2 => [
                [
                    'source_page' => 2,
                    'type' => PDFLinkExtractor::LINK_TYPE_INTERNAL,
                    'destination_page' => 10,
                    'destination_url' => null,
                    'coordinates' => ['x' => 50, 'y' => 50, 'width' => 80, 'height' => 15],
                    'normalized_coordinates' => ['x_percent' => 8.17, 'y_percent' => 6.31, 'width_percent' => 13.07, 'height_percent' => 1.89],
                ],
            ],
        ];

        $result = $this->extractor->getInternalLinks($allLinks);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);

        // Check page 1 only has internal link
        $this->assertCount(1, $result[1]);
        $this->assertEquals(PDFLinkExtractor::LINK_TYPE_INTERNAL, $result[1][0]['type']);
        $this->assertEquals(5, $result[1][0]['destination_page']);

        // Check page 2 has internal link
        $this->assertCount(1, $result[2]);
        $this->assertEquals(10, $result[2][0]['destination_page']);
    }

    public function test_get_external_links_filters_correctly(): void
    {
        $allLinks = [
            1 => [
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_INTERNAL,
                    'destination_page' => 5,
                    'destination_url' => null,
                    'coordinates' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 0, 'y_percent' => 0, 'width_percent' => 16.34, 'height_percent' => 2.53],
                ],
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_EXTERNAL,
                    'destination_page' => null,
                    'destination_url' => 'https://example.com',
                    'coordinates' => ['x' => 100, 'y' => 100, 'width' => 150, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 16.34, 'y_percent' => 12.63, 'width_percent' => 24.51, 'height_percent' => 2.53],
                ],
            ],
        ];

        $result = $this->extractor->getExternalLinks($allLinks);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertCount(1, $result[1]);
        $this->assertEquals(PDFLinkExtractor::LINK_TYPE_EXTERNAL, $result[1][0]['type']);
        $this->assertEquals('https://example.com', $result[1][0]['destination_url']);
    }

    public function test_get_internal_links_returns_empty_when_no_internal_links(): void
    {
        $allLinks = [
            1 => [
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_EXTERNAL,
                    'destination_page' => null,
                    'destination_url' => 'https://example.com',
                    'coordinates' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 0, 'y_percent' => 0, 'width_percent' => 16.34, 'height_percent' => 2.53],
                ],
            ],
        ];

        $result = $this->extractor->getInternalLinks($allLinks);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_flatten_returns_all_links_in_flat_array(): void
    {
        $allLinks = [
            1 => [
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_INTERNAL,
                    'destination_page' => 5,
                    'destination_url' => null,
                    'coordinates' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 0, 'y_percent' => 0, 'width_percent' => 16.34, 'height_percent' => 2.53],
                ],
                [
                    'source_page' => 1,
                    'type' => PDFLinkExtractor::LINK_TYPE_EXTERNAL,
                    'destination_page' => null,
                    'destination_url' => 'https://example.com',
                    'coordinates' => ['x' => 100, 'y' => 100, 'width' => 150, 'height' => 20],
                    'normalized_coordinates' => ['x_percent' => 16.34, 'y_percent' => 12.63, 'width_percent' => 24.51, 'height_percent' => 2.53],
                ],
            ],
            3 => [
                [
                    'source_page' => 3,
                    'type' => PDFLinkExtractor::LINK_TYPE_INTERNAL,
                    'destination_page' => 10,
                    'destination_url' => null,
                    'coordinates' => ['x' => 50, 'y' => 50, 'width' => 80, 'height' => 15],
                    'normalized_coordinates' => ['x_percent' => 8.17, 'y_percent' => 6.31, 'width_percent' => 13.07, 'height_percent' => 1.89],
                ],
            ],
        ];

        $result = $this->extractor->flatten($allLinks);

        $this->assertCount(3, $result);

        // Check first link
        $this->assertEquals(1, $result[0]['source_page']);
        $this->assertEquals(PDFLinkExtractor::LINK_TYPE_INTERNAL, $result[0]['type']);

        // Check second link
        $this->assertEquals(1, $result[1]['source_page']);
        $this->assertEquals(PDFLinkExtractor::LINK_TYPE_EXTERNAL, $result[1]['type']);

        // Check third link
        $this->assertEquals(3, $result[2]['source_page']);
        $this->assertEquals(10, $result[2]['destination_page']);
    }

    public function test_flatten_returns_empty_array_for_empty_input(): void
    {
        $result = $this->extractor->flatten([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_link_type_constants_are_defined(): void
    {
        $this->assertEquals('internal', PDFLinkExtractor::LINK_TYPE_INTERNAL);
        $this->assertEquals('external', PDFLinkExtractor::LINK_TYPE_EXTERNAL);
        $this->assertEquals('unknown', PDFLinkExtractor::LINK_TYPE_UNKNOWN);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
