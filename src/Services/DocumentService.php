<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Exceptions\DocumentNotFoundException;
use Shakewellagency\LaravelPdfViewer\Exceptions\InvalidFileTypeException;

class DocumentService implements DocumentServiceInterface
{
    public function __construct(
        protected DocumentProcessingServiceInterface $processingService,
        protected CacheServiceInterface $cacheService
    ) {}

    public function upload(UploadedFile $file, array $metadata = []): PdfDocument
    {
        $this->validateFile($file);

        $disk = $this->getStorageDisk();
        $path = config('pdf-viewer.storage.path');
        
        $filename = uniqid() . '_' . time() . '.pdf';
        
        try {
            // For S3/Vapor compatibility, handle large files with streaming
            if ($this->isS3Disk($disk) && $file->getSize() > config('pdf-viewer.storage.multipart_threshold', 52428800)) {
                $filePath = $this->uploadLargeFileToS3($disk, $path, $file, $filename);
            } else {
                $filePath = $disk->putFileAs($path, $file, $filename);
            }

            $document = PdfDocument::create([
                'title' => $metadata['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'file_path' => $filePath,
                'metadata' => $metadata,
                'status' => 'uploaded',
                'created_by' => auth()->id(),
            ]);

            Log::info('Document uploaded successfully', [
                'document_hash' => $document->hash,
                'file_size' => $file->getSize(),
                'storage_disk' => config('pdf-viewer.storage.disk'),
                'file_path' => $filePath,
            ]);

            return $document;
        } catch (\Exception $e) {
            Log::error('Document upload failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'file_size' => $file->getSize(),
            ]);
            throw $e;
        }
    }

    public function findByHash(string $hash): ?PdfDocument
    {
        return PdfDocument::findByHash($hash);
    }

    public function getMetadata(string $documentHash): array
    {
        $cached = $this->cacheService->getCachedDocumentMetadata($documentHash);
        
        if ($cached) {
            return $cached;
        }

        $document = $this->findByHashOrFail($documentHash);
        
        $metadata = [
            'id' => $document->id,
            'hash' => $document->hash,
            'title' => $document->title,
            'filename' => $document->original_filename,
            'file_size' => $document->file_size,
            'formatted_file_size' => $document->formatted_file_size,
            'page_count' => $document->page_count,
            'status' => $document->status,
            'is_searchable' => $document->is_searchable,
            'processing_progress' => $document->getProcessingProgress(),
            'metadata' => $document->metadata,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];

        $this->cacheService->cacheDocumentMetadata($documentHash, $metadata);

        return $metadata;
    }

    public function getProgress(string $documentHash): array
    {
        $document = $this->findByHashOrFail($documentHash);

        return [
            'status' => $document->status,
            'progress_percentage' => $document->getProcessingProgress(),
            'total_pages' => $document->page_count,
            'completed_pages' => $document->completedPages()->count(),
            'failed_pages' => $document->failedPages()->count(),
            'processing_started_at' => $document->processing_started_at,
            'processing_completed_at' => $document->processing_completed_at,
            'processing_error' => $document->processing_error,
            'processing_progress' => $document->processing_progress,
        ];
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PdfDocument::query();

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['is_searchable'])) {
            $query->where('is_searchable', $filters['is_searchable']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('original_filename', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function updateMetadata(string $documentHash, array $metadata): bool
    {
        $document = $this->findByHashOrFail($documentHash);
        
        $updateData = [];
        
        if (isset($metadata['title'])) {
            $updateData['title'] = $metadata['title'];
        }
        
        if (isset($metadata['metadata'])) {
            $updateData['metadata'] = array_merge($document->metadata ?? [], $metadata['metadata']);
        }

        $result = $document->update($updateData);

        if ($result) {
            $this->cacheService->invalidateDocumentCache($documentHash);
        }

        return $result;
    }

    public function delete(string $documentHash): bool
    {
        $document = $this->findByHashOrFail($documentHash);

        // Delete associated files
        $this->deleteDocumentFiles($document);

        // Soft delete the document (will cascade to pages)
        $result = $document->delete();

        if ($result) {
            $this->cacheService->invalidateDocumentCache($documentHash);
        }

        return $result;
    }

    public function exists(string $documentHash): bool
    {
        return PdfDocument::where('hash', $documentHash)->exists();
    }

    protected function findByHashOrFail(string $hash): PdfDocument
    {
        $document = $this->findByHash($hash);
        
        if (!$document) {
            throw new DocumentNotFoundException("Document with hash {$hash} not found");
        }

        return $document;
    }

    protected function validateFile(UploadedFile $file): void
    {
        $allowedExtensions = config('pdf-viewer.processing.allowed_extensions', ['pdf']);
        $allowedMimeTypes = config('pdf-viewer.processing.allowed_mime_types', ['application/pdf']);
        $maxFileSize = config('pdf-viewer.processing.max_file_size', 104857600); // 100MB

        if (!in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions)) {
            throw new InvalidFileTypeException('Invalid file extension. Only PDF files are allowed.');
        }

        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            throw new InvalidFileTypeException('Invalid MIME type. Only PDF files are allowed.');
        }

        if ($file->getSize() > $maxFileSize) {
            throw new InvalidFileTypeException('File size exceeds maximum allowed size.');
        }
    }

    protected function deleteDocumentFiles(PdfDocument $document): void
    {
        $disk = $this->getStorageDisk();

        try {
            // Delete main PDF file
            if ($disk->exists($document->file_path)) {
                $disk->delete($document->file_path);
            }

            // Delete page files and thumbnails
            foreach ($document->pages as $page) {
                if ($page->page_file_path && $disk->exists($page->page_file_path)) {
                    $disk->delete($page->page_file_path);
                }
                
                if ($page->thumbnail_path && $disk->exists($page->thumbnail_path)) {
                    $disk->delete($page->thumbnail_path);
                }
            }

            // For S3, also try to delete directory prefixes if they're empty
            if ($this->isS3Disk($disk)) {
                $this->cleanupS3Directories($disk, $document);
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete document files', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the configured storage disk with package-specific S3 configuration
     */
    protected function getStorageDisk()
    {
        $diskName = config('pdf-viewer.storage.disk');
        
        // If using S3, create a custom S3 disk with package-specific credentials
        if ($diskName === 's3') {
            $awsConfig = config('pdf-viewer.storage.aws');
            
            if (!empty($awsConfig['key']) && !empty($awsConfig['secret']) && !empty($awsConfig['bucket'])) {
                return Storage::build([
                    'driver' => 's3',
                    'key' => $awsConfig['key'],
                    'secret' => $awsConfig['secret'],
                    'region' => $awsConfig['region'] ?? 'ap-southeast-2',
                    'bucket' => $awsConfig['bucket'],
                    'url' => null,
                    'endpoint' => null,
                    'use_path_style_endpoint' => $awsConfig['use_path_style_endpoint'] ?? false,
                    'throw' => false,
                    'visibility' => 'public',
                ]);
            }
        }
        
        // Fall back to configured disk
        return Storage::disk($diskName);
    }

    /**
     * Check if the storage disk is S3
     */
    protected function isS3Disk($disk): bool
    {
        return method_exists($disk->getAdapter(), 'getBucket') || 
               (config('pdf-viewer.storage.disk') === 's3');
    }

    /**
     * Upload large files to S3 using multipart upload
     */
    protected function uploadLargeFileToS3($disk, string $path, UploadedFile $file, string $filename): string
    {
        $filePath = $path . '/' . $filename;
        
        // Stream the file to S3 in chunks for better memory management
        $stream = fopen($file->getPathname(), 'r');
        
        if (!$stream) {
            throw new \Exception('Cannot open uploaded file for reading');
        }

        $success = $disk->put($filePath, $stream, 'public');
        
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$success) {
            throw new \Exception('Failed to upload file to S3');
        }

        return $filePath;
    }

    /**
     * Clean up empty S3 directory prefixes
     */
    protected function cleanupS3Directories($disk, PdfDocument $document): void
    {
        try {
            $pagesPath = config('pdf-viewer.storage.pages_path') . '/' . $document->hash;
            $thumbnailsPath = config('pdf-viewer.storage.thumbnails_path') . '/' . $document->hash;
            
            // List objects with the prefix and delete if empty
            $pageFiles = $disk->files($pagesPath);
            $thumbnailFiles = $disk->files($thumbnailsPath);
            
            if (empty($pageFiles)) {
                // S3 doesn't have real directories, but we can try to clean up empty "directories"
                $disk->delete($pagesPath . '/.gitkeep');
            }
            
            if (empty($thumbnailFiles)) {
                $disk->delete($thumbnailsPath . '/.gitkeep');
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup S3 directories', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a temporary signed URL for S3 files
     */
    public function getSignedUrl(string $filePath, ?int $expiresIn = null): string
    {
        $disk = $this->getStorageDisk();
        
        if (!$this->isS3Disk($disk)) {
            // For non-S3 disks, return the regular URL
            return $disk->url($filePath);
        }

        $expiresIn = $expiresIn ?? config('pdf-viewer.storage.signed_url_expires', 3600);
        
        return $disk->temporaryUrl($filePath, now()->addSeconds($expiresIn));
    }

    /**
     * Initiate multipart upload for large files
     */
    public function initiateMultipartUpload(array $metadata): array
    {
        $disk = $this->getStorageDisk();
        
        if (!$this->isS3Disk($disk)) {
            throw new \Exception('Multipart upload is only supported for S3 storage');
        }

        // Generate unique filename
        $filename = uniqid() . '_' . time() . '.pdf';
        $path = config('pdf-viewer.storage.path');
        $filePath = $path . '/' . $filename;

        // Create document record in pending state
        $document = PdfDocument::create([
            'title' => $metadata['title'] ?? 'Untitled Document',
            'filename' => $filename,
            'original_filename' => $metadata['original_filename'] ?? 'document.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => $metadata['file_size'] ?? 0,
            'file_path' => $filePath,
            'metadata' => $metadata,
            'status' => 'pending_upload',
            'created_by' => auth()->id(),
        ]);

        // Get S3 client for multipart upload using reflection
        $adapter = $disk->getAdapter();
        $reflection = new \ReflectionClass($adapter);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $s3Client = $clientProperty->getValue($adapter);
        
        $bucketProperty = $reflection->getProperty('bucket');
        $bucketProperty->setAccessible(true);
        $bucketName = $bucketProperty->getValue($adapter);
        
        try {
            // Initiate multipart upload
            $result = $s3Client->createMultipartUpload([
                'Bucket' => $bucketName,
                'Key' => $filePath,
                'ContentType' => 'application/pdf',
                'ACL' => 'public-read',
            ]);

            $uploadId = $result['UploadId'];

            // Store upload ID in document metadata
            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    'multipart_upload_id' => $uploadId,
                    'upload_initiated_at' => now()->toISOString(),
                ])
            ]);

            Log::info('Multipart upload initiated', [
                'document_hash' => $document->hash,
                'upload_id' => $uploadId,
                'file_path' => $filePath,
            ]);

            return [
                'document_hash' => $document->hash,
                'upload_id' => $uploadId,
                'file_path' => $filePath,
                'expires_in' => config('pdf-viewer.storage.signed_url_expires', 3600),
            ];

        } catch (\Exception $e) {
            // Clean up document record on failure
            $document->delete();
            
            Log::error('Failed to initiate multipart upload', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Generate signed URLs for multipart upload parts
     */
    public function getMultipartUploadUrls(string $documentHash, int $totalParts): array
    {
        $document = $this->findByHashOrFail($documentHash);
        
        if ($document->status !== 'pending_upload') {
            throw new \Exception('Document is not in pending upload state');
        }

        $uploadId = $document->metadata['multipart_upload_id'] ?? null;
        if (!$uploadId) {
            throw new \Exception('No multipart upload ID found for document');
        }

        $disk = $this->getStorageDisk();
        $adapter = $disk->getAdapter();
        $reflection = new \ReflectionClass($adapter);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $s3Client = $clientProperty->getValue($adapter);
        
        $bucketProperty = $reflection->getProperty('bucket');
        $bucketProperty->setAccessible(true);
        $bucketName = $bucketProperty->getValue($adapter);

        $signedUrls = [];
        $expiresIn = config('pdf-viewer.storage.signed_url_expires', 3600);

        try {
            for ($partNumber = 1; $partNumber <= $totalParts; $partNumber++) {
                $command = $s3Client->getCommand('UploadPart', [
                    'Bucket' => $bucketName,
                    'Key' => $document->file_path,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                ]);

                $signedUrl = (string) $s3Client->createPresignedRequest($command, "+{$expiresIn} seconds")->getUri();
                
                $signedUrls[] = [
                    'part_number' => $partNumber,
                    'signed_url' => $signedUrl,
                ];
            }

            Log::info('Generated multipart upload URLs', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
                'total_parts' => $totalParts,
            ]);

            return $signedUrls;

        } catch (\Exception $e) {
            Log::error('Failed to generate multipart upload URLs', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Complete multipart upload
     */
    public function completeMultipartUpload(string $documentHash, array $parts): bool
    {
        $document = $this->findByHashOrFail($documentHash);
        
        if ($document->status !== 'pending_upload') {
            throw new \Exception('Document is not in pending upload state');
        }

        $uploadId = $document->metadata['multipart_upload_id'] ?? null;
        if (!$uploadId) {
            throw new \Exception('No multipart upload ID found for document');
        }

        $disk = $this->getStorageDisk();
        $adapter = $disk->getAdapter();
        $reflection = new \ReflectionClass($adapter);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $s3Client = $clientProperty->getValue($adapter);
        
        $bucketProperty = $reflection->getProperty('bucket');
        $bucketProperty->setAccessible(true);
        $bucketName = $bucketProperty->getValue($adapter);

        try {
            // Complete multipart upload
            $result = $s3Client->completeMultipartUpload([
                'Bucket' => $bucketName,
                'Key' => $document->file_path,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts
                ],
            ]);

            // Update document with final details
            $fileSize = $disk->size($document->file_path);
            
            $document->update([
                'file_size' => $fileSize,
                'status' => 'uploaded',
                'metadata' => array_merge($document->metadata ?? [], [
                    'upload_completed_at' => now()->toISOString(),
                    'etag' => $result['ETag'] ?? null,
                ])
            ]);

            Log::info('Multipart upload completed successfully', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
                'file_size' => $fileSize,
                'etag' => $result['ETag'] ?? null,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to complete multipart upload', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Abort multipart upload
     */
    public function abortMultipartUpload(string $documentHash): bool
    {
        $document = $this->findByHashOrFail($documentHash);
        
        $uploadId = $document->metadata['multipart_upload_id'] ?? null;
        if (!$uploadId) {
            return true; // Nothing to abort
        }

        $disk = $this->getStorageDisk();
        $adapter = $disk->getAdapter();
        $reflection = new \ReflectionClass($adapter);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $s3Client = $clientProperty->getValue($adapter);
        
        $bucketProperty = $reflection->getProperty('bucket');
        $bucketProperty->setAccessible(true);
        $bucketName = $bucketProperty->getValue($adapter);

        try {
            // Abort multipart upload
            $s3Client->abortMultipartUpload([
                'Bucket' => $bucketName,
                'Key' => $document->file_path,
                'UploadId' => $uploadId,
            ]);

            // Delete document record
            $document->delete();

            Log::info('Multipart upload aborted', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to abort multipart upload', [
                'document_hash' => $documentHash,
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
}