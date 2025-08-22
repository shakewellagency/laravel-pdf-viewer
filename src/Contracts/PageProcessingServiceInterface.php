<?php

namespace Shakewellagency\LaravelPdfViewer\Contracts;

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

/**
 * Page Processing Service Interface - Single Responsibility: Individual page processing
 */
interface PageProcessingServiceInterface
{
    /**
     * Extract single page from PDF
     */
    public function extractPage(PdfDocument $document, int $pageNumber): string;

    /**
     * Extract text content from page
     */
    public function extractText(string $pageFilePath): string;

    /**
     * Create page record
     */
    public function createPage(PdfDocument $document, int $pageNumber, string $content = ''): PdfDocumentPage;

    /**
     * Update page content
     */
    public function updatePageContent(PdfDocumentPage $page, string $content): bool;

    /**
     * Generate page thumbnail
     */
    public function generateThumbnail(string $pageFilePath, int $width = 300, int $height = 400): string;

    /**
     * Get page file path
     */
    public function getPageFilePath(string $documentHash, int $pageNumber): string;

    /**
     * Validate page file
     */
    public function validatePageFile(string $pageFilePath): bool;

    /**
     * Clean up page files
     */
    public function cleanupPageFiles(string $documentHash): bool;

    /**
     * Mark page as processed
     */
    public function markPageProcessed(PdfDocumentPage $page): void;

    /**
     * Handle page processing failure
     */
    public function handlePageFailure(PdfDocumentPage $page, string $error): void;
}