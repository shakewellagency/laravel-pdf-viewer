<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
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

        $disk = Storage::disk(config('pdf-viewer.storage.disk'));
        $path = config('pdf-viewer.storage.path');
        
        $filename = uniqid() . '_' . time() . '.pdf';
        $filePath = $disk->putFileAs($path, $file, $filename);

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

        return $document;
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
        $disk = Storage::disk(config('pdf-viewer.storage.disk'));

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
    }
}