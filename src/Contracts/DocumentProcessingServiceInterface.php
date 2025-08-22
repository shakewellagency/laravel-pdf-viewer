<?php

namespace Shakewellagency\LaravelPdfViewer\Contracts;

use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

/**
 * Document Processing Service Interface - Single Responsibility: PDF processing orchestration
 */
interface DocumentProcessingServiceInterface
{
    /**
     * Start document processing workflow
     */
    public function process(PdfDocument $document): void;

    /**
     * Get total page count from PDF
     */
    public function getPageCount(string $filePath): int;

    /**
     * Extract metadata from PDF
     */
    public function extractMetadata(string $filePath): array;

    /**
     * Validate PDF file
     */
    public function validatePdf(string $filePath): bool;

    /**
     * Get processing status
     */
    public function getProcessingStatus(string $documentHash): array;

    /**
     * Cancel processing
     */
    public function cancelProcessing(string $documentHash): bool;

    /**
     * Retry failed processing
     */
    public function retryProcessing(string $documentHash): bool;

    /**
     * Mark processing as complete
     */
    public function markComplete(string $documentHash): void;

    /**
     * Handle processing failure
     */
    public function handleFailure(string $documentHash, string $error): void;
}