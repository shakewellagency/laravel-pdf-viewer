<?php

namespace Shakewellagency\LaravelPdfViewer\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

/**
 * Document Service Interface - Single Responsibility: Document management operations
 */
interface DocumentServiceInterface
{
    /**
     * Upload and create a new PDF document
     */
    public function upload(UploadedFile $file, array $metadata = []): PdfDocument;

    /**
     * Find document by hash
     */
    public function findByHash(string $hash): ?PdfDocument;

    /**
     * Get document metadata
     */
    public function getMetadata(string $documentHash): array;

    /**
     * Get document processing progress
     */
    public function getProgress(string $documentHash): array;

    /**
     * List documents with pagination
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Update document metadata
     */
    public function updateMetadata(string $documentHash, array $metadata): bool;

    /**
     * Delete document and all associated data
     */
    public function delete(string $documentHash): bool;

    /**
     * Check if document exists
     */
    public function exists(string $documentHash): bool;

    /**
     * Get a temporary signed URL for S3 files
     */
    public function getSignedUrl(string $filePath, ?int $expiresIn = null): string;

    /**
     * Initiate multipart upload for large files
     */
    public function initiateMultipartUpload(array $metadata): array;

    /**
     * Generate signed URLs for multipart upload parts
     */
    public function getMultipartUploadUrls(string $documentHash, int $totalParts): array;

    /**
     * Complete multipart upload
     */
    public function completeMultipartUpload(string $documentHash, array $parts): bool;

    /**
     * Abort multipart upload
     */
    public function abortMultipartUpload(string $documentHash): bool;
}