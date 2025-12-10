<?php

use Shakewellagency\LaravelPdfViewer\Services\PDFOutlineExtractor;

beforeEach(function () {
    $this->extractor = new PDFOutlineExtractor();
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

it('extract handles pdf without outline', function () {
    // Create a minimal PDF without outline
    $pdfContent = generateMinimalPdfContent();
    $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
    file_put_contents($tempFile, $pdfContent);

    try {
        $result = $this->extractor->extract($tempFile);
        expect($result)->toBeArray();
        // A minimal PDF without outline should return empty array
        expect($result)->toBeEmpty();
    } finally {
        unlink($tempFile);
    }
});

it('flatten returns flat array with levels', function () {
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

    expect($result)->toHaveCount(4);

    // Check first item
    expect($result[0]['title'])->toBe('Chapter 1');
    expect($result[0]['level'])->toBe(0);
    expect($result[0]['destination_page'])->toBe(1);

    // Check nested items
    expect($result[1]['title'])->toBe('Section 1.1');
    expect($result[1]['level'])->toBe(1);
    expect($result[1]['destination_page'])->toBe(5);

    expect($result[2]['title'])->toBe('Section 1.2');
    expect($result[2]['level'])->toBe(1);

    // Check last item
    expect($result[3]['title'])->toBe('Chapter 2');
    expect($result[3]['level'])->toBe(0);
});

it('flatten returns empty array for empty outline', function () {
    $result = $this->extractor->flatten([]);

    expect($result)->toBeArray()->toBeEmpty();
});

it('flatten handles deeply nested structure', function () {
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

    expect($result)->toHaveCount(4);

    // Verify levels are correct
    for ($i = 0; $i < 4; $i++) {
        expect($result[$i]['level'])->toBe($i);
        expect($result[$i]['title'])->toBe("Level $i");
        expect($result[$i]['destination_page'])->toBe($i + 1);
    }
});
