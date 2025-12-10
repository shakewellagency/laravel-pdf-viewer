<?php

use Mockery;
use Shakewellagency\LaravelPdfViewer\Services\PDFLinkExtractor;

beforeEach(function () {
    $this->extractor = new PDFLinkExtractor();
});

afterEach(function () {
    Mockery::close();
});

it('extract returns empty array for nonexistent file', function () {
    $result = $this->extractor->extract('/nonexistent/path/to/file.pdf');

    expect($result)->toBeArray()->toBeEmpty();
});

it('extract returns empty array for invalid pdf', function () {
    // Create a temp file with invalid PDF content
    $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
    file_put_contents($tempFile, 'This is not a PDF file');

    try {
        $result = $this->extractor->extract($tempFile);
        expect($result)->toBeArray()->toBeEmpty();
    } finally {
        unlink($tempFile);
    }
});

it('extract for page returns empty for nonexistent file', function () {
    $result = $this->extractor->extractForPage('/nonexistent/path/to/file.pdf', 1);

    expect($result)->toBeArray()->toBeEmpty();
});

it('extract for page returns empty for invalid page number', function () {
    $pdfContent = generateMinimalPdfContent();
    $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
    file_put_contents($tempFile, $pdfContent);

    try {
        // Request page 100 from a 1-page PDF
        $result = $this->extractor->extractForPage($tempFile, 100);
        expect($result)->toBeArray()->toBeEmpty();
    } finally {
        unlink($tempFile);
    }
});

it('get internal links filters correctly', function () {
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

    expect($result)->toHaveCount(2);
    expect($result)->toHaveKey(1);
    expect($result)->toHaveKey(2);

    // Check page 1 only has internal link
    expect($result[1])->toHaveCount(1);
    expect($result[1][0]['type'])->toBe(PDFLinkExtractor::LINK_TYPE_INTERNAL);
    expect($result[1][0]['destination_page'])->toBe(5);

    // Check page 2 has internal link
    expect($result[2])->toHaveCount(1);
    expect($result[2][0]['destination_page'])->toBe(10);
});

it('get external links filters correctly', function () {
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

    expect($result)->toHaveCount(1);
    expect($result)->toHaveKey(1);
    expect($result[1])->toHaveCount(1);
    expect($result[1][0]['type'])->toBe(PDFLinkExtractor::LINK_TYPE_EXTERNAL);
    expect($result[1][0]['destination_url'])->toBe('https://example.com');
});

it('get internal links returns empty when no internal links', function () {
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

    expect($result)->toBeArray()->toBeEmpty();
});

it('flatten returns all links in flat array', function () {
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

    expect($result)->toHaveCount(3);

    // Check first link
    expect($result[0]['source_page'])->toBe(1);
    expect($result[0]['type'])->toBe(PDFLinkExtractor::LINK_TYPE_INTERNAL);

    // Check second link
    expect($result[1]['source_page'])->toBe(1);
    expect($result[1]['type'])->toBe(PDFLinkExtractor::LINK_TYPE_EXTERNAL);

    // Check third link
    expect($result[2]['source_page'])->toBe(3);
    expect($result[2]['destination_page'])->toBe(10);
});

it('flatten returns empty array for empty input', function () {
    $result = $this->extractor->flatten([]);

    expect($result)->toBeArray()->toBeEmpty();
});

it('link type constants are defined', function () {
    expect(PDFLinkExtractor::LINK_TYPE_INTERNAL)->toBe('internal');
    expect(PDFLinkExtractor::LINK_TYPE_EXTERNAL)->toBe('external');
    expect(PDFLinkExtractor::LINK_TYPE_UNKNOWN)->toBe('unknown');
});
