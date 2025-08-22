<?php

namespace Shakewellagency\LaravelPdfViewer\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Requests\DocumentStoreRequest;
use Shakewellagency\LaravelPdfViewer\Requests\DocumentIndexRequest;
use Shakewellagency\LaravelPdfViewer\Requests\DocumentUpdateRequest;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentResource;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentProgressResource;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentServiceInterface $documentService,
        protected DocumentProcessingServiceInterface $processingService,
        protected CacheServiceInterface $cacheService
    ) {}

    public function index(DocumentIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $request->input('per_page', 15);

        $documents = $this->documentService->list($filters, $perPage);

        return response()->json([
            'data' => DocumentResource::collection($documents->items()),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'from' => $documents->firstItem(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'to' => $documents->lastItem(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function store(DocumentStoreRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $metadata = $request->validated();
            unset($metadata['file']);

            $document = $this->documentService->upload($file, $metadata);

            return response()->json([
                'message' => 'Document uploaded successfully',
                'data' => new DocumentResource($document),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload document',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $metadata = $this->documentService->getMetadata($documentHash);

            return response()->json([
                'data' => $metadata,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(DocumentUpdateRequest $request, string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $metadata = $request->validated();
            $updated = $this->documentService->updateMetadata($documentHash, $metadata);

            if ($updated) {
                return response()->json([
                    'message' => 'Document updated successfully',
                    'data' => $this->documentService->getMetadata($documentHash),
                ]);
            }

            return response()->json([
                'message' => 'Failed to update document',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $deleted = $this->documentService->delete($documentHash);

            if ($deleted) {
                return response()->json([
                    'message' => 'Document deleted successfully',
                ]);
            }

            return response()->json([
                'message' => 'Failed to delete document',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function process(string $documentHash): JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if ($document->status === 'processing') {
            return response()->json([
                'message' => 'Document is already being processed',
                'data' => new DocumentProgressResource($document),
            ], 409);
        }

        try {
            $this->processingService->process($document);

            return response()->json([
                'message' => 'Document processing started',
                'data' => new DocumentProgressResource($document),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to start document processing',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function progress(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $progress = $this->documentService->getProgress($documentHash);

            return response()->json([
                'data' => $progress,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve processing progress',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function retry(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $retried = $this->processingService->retryProcessing($documentHash);

            if ($retried) {
                return response()->json([
                    'message' => 'Document processing restarted',
                    'data' => $this->processingService->getProcessingStatus($documentHash),
                ]);
            }

            return response()->json([
                'message' => 'Cannot retry processing for this document',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retry document processing',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $cancelled = $this->processingService->cancelProcessing($documentHash);

            if ($cancelled) {
                return response()->json([
                    'message' => 'Document processing cancelled',
                ]);
            }

            return response()->json([
                'message' => 'Cannot cancel processing for this document',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel document processing',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function clearCache(): JsonResponse
    {
        try {
            $cleared = $this->cacheService->clearAllCache();

            return response()->json([
                'message' => $cleared ? 'Cache cleared successfully' : 'Failed to clear cache',
            ], $cleared ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to clear cache',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function warmCache(string $documentHash): JsonResponse
    {
        if (!$this->documentService->exists($documentHash)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        try {
            $warmed = $this->cacheService->warmDocumentCache($documentHash);

            return response()->json([
                'message' => $warmed ? 'Cache warmed successfully' : 'Failed to warm cache',
            ], $warmed ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to warm cache',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function stats(): JsonResponse
    {
        try {
            $searchService = app(\Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface::class);

            $stats = [
                'documents' => $searchService->getSearchStats(),
                'cache' => $this->cacheService->getCacheStats(),
                'processing' => [
                    'total_documents' => \Shakewellagency\LaravelPdfViewer\Models\PdfDocument::count(),
                    'processing_documents' => \Shakewellagency\LaravelPdfViewer\Models\PdfDocument::processing()->count(),
                    'completed_documents' => \Shakewellagency\LaravelPdfViewer\Models\PdfDocument::completed()->count(),
                    'failed_documents' => \Shakewellagency\LaravelPdfViewer\Models\PdfDocument::failed()->count(),
                ],
            ];

            return response()->json(['data' => $stats]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function health(): JsonResponse
    {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => now()->toISOString(),
                'checks' => [
                    'database' => $this->checkDatabase(),
                    'cache' => $this->checkCache(),
                    'storage' => $this->checkStorage(),
                ],
            ];

            $allHealthy = collect($health['checks'])->every(fn($check) => $check['status'] === 'healthy');
            
            if (!$allHealthy) {
                $health['status'] = 'unhealthy';
            }

            return response()->json($health, $allHealthy ? 200 : 503);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ], 503);
        }
    }

    protected function checkDatabase(): array
    {
        try {
            \Shakewellagency\LaravelPdfViewer\Models\PdfDocument::query()->limit(1)->get();
            return ['status' => 'healthy'];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    protected function checkCache(): array
    {
        try {
            $stats = $this->cacheService->getCacheStats();
            return ['status' => 'healthy', 'details' => $stats];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    protected function checkStorage(): array
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
            $path = config('pdf-viewer.storage.path');
            
            // Check if storage path is accessible
            if (!$disk->exists($path)) {
                $disk->makeDirectory($path);
            }
            
            return ['status' => 'healthy'];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    /**
     * Initiate multipart upload
     */
    public function initiateMultipartUpload(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'original_filename' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1',
            'total_parts' => 'required|integer|min:1|max:10000',
        ]);

        try {
            $metadata = $request->only(['title', 'original_filename', 'file_size']);
            $totalParts = $request->input('total_parts');

            // Initiate multipart upload
            $upload = $this->documentService->initiateMultipartUpload($metadata);

            // Generate signed URLs for all parts
            $signedUrls = $this->documentService->getMultipartUploadUrls(
                $upload['document_hash'], 
                $totalParts
            );

            return response()->json([
                'message' => 'Multipart upload initiated successfully',
                'data' => [
                    'document_hash' => $upload['document_hash'],
                    'upload_id' => $upload['upload_id'],
                    'signed_urls' => $signedUrls,
                    'expires_in' => $upload['expires_in'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to initiate multipart upload',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Complete multipart upload
     */
    public function completeMultipartUpload(\Illuminate\Http\Request $request, string $documentHash): JsonResponse
    {
        $request->validate([
            'parts' => 'required|array',
            'parts.*.PartNumber' => 'required|integer|min:1',
            'parts.*.ETag' => 'required|string',
        ]);

        try {
            $parts = $request->input('parts');
            
            $success = $this->documentService->completeMultipartUpload($documentHash, $parts);

            if ($success) {
                $document = $this->documentService->findByHash($documentHash);
                
                return response()->json([
                    'message' => 'Multipart upload completed successfully',
                    'data' => new DocumentResource($document),
                ]);
            }

            return response()->json([
                'message' => 'Failed to complete multipart upload',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to complete multipart upload',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Abort multipart upload
     */
    public function abortMultipartUpload(string $documentHash): JsonResponse
    {
        try {
            $success = $this->documentService->abortMultipartUpload($documentHash);

            if ($success) {
                return response()->json([
                    'message' => 'Multipart upload aborted successfully',
                ]);
            }

            return response()->json([
                'message' => 'Failed to abort multipart upload',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to abort multipart upload',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get additional signed URLs for multipart upload (if needed)
     */
    public function getMultipartUrls(\Illuminate\Http\Request $request, string $documentHash): JsonResponse
    {
        $request->validate([
            'total_parts' => 'required|integer|min:1|max:10000',
        ]);

        try {
            $totalParts = $request->input('total_parts');
            
            $signedUrls = $this->documentService->getMultipartUploadUrls($documentHash, $totalParts);

            return response()->json([
                'data' => [
                    'document_hash' => $documentHash,
                    'signed_urls' => $signedUrls,
                    'expires_in' => config('pdf-viewer.storage.signed_url_expires', 3600),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate signed URLs',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}