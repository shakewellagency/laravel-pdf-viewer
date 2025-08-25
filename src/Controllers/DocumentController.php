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
use Shakewellagency\LaravelPdfViewer\Services\ExtractionAuditService;
use Shakewellagency\LaravelPdfViewer\Services\MonitoringService;
use Shakewellagency\LaravelPdfViewer\Services\CrossReferenceService;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentServiceInterface $documentService,
        protected DocumentProcessingServiceInterface $processingService,
        protected CacheServiceInterface $cacheService,
        protected ExtractionAuditService $auditService,
        protected MonitoringService $monitoringService,
        protected CrossReferenceService $crossRefService
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

    /**
     * Handle secure file access for local storage signed URLs
     */
    public function secureFileAccess(string $token): \Illuminate\Http\Response
    {
        try {
            // Decrypt and validate the token
            $payload = decrypt($token);
            
            // Validate token structure
            if (!is_array($payload) || !isset($payload['file_path'], $payload['expires_at'], $payload['type'])) {
                abort(400, 'Invalid token format');
            }
            
            // Check if token has expired
            if (now()->timestamp > $payload['expires_at']) {
                abort(403, 'Token has expired');
            }
            
            // Validate token type
            if ($payload['type'] !== 'page_access') {
                abort(400, 'Invalid token type');
            }
            
            $filePath = $payload['file_path'];
            $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
            
            // Verify file exists
            if (!$disk->exists($filePath)) {
                abort(404, 'File not found');
            }
            
            // Get file content and mime type
            $content = $disk->get($filePath);
            $mimeType = $disk->mimeType($filePath) ?: 'application/pdf';
            
            // Determine appropriate headers based on file type
            $headers = [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, max-age=3600',
            ];
            
            // For PDF files, use inline disposition for browser viewing
            if (str_contains($mimeType, 'pdf')) {
                $headers['Content-Disposition'] = 'inline';
            } else {
                $headers['Content-Disposition'] = 'attachment';
            }
            
            return response($content, 200, $headers);
            
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            abort(400, 'Invalid token');
        } catch (\Exception $e) {
            abort(500, 'Failed to access file');
        }
    }

    /**
     * Download original document for compliance purposes
     */
    public function downloadOriginal(string $documentHash): \Illuminate\Http\Response
    {
        try {
            $document = $this->documentService->findByHash($documentHash);
            
            if (!$document) {
                abort(404, 'Document not found');
            }

            // Create audit trail for original document access
            $audit = $this->auditService->initiateExtraction(
                $document,
                null, // No specific pages for full document
                'full_document_download',
                'Compliance access to original unmodified document'
            );

            $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
            
            if (!$disk->exists($document->file_path)) {
                $this->auditService->recordExtractionFailure($audit, 'Original file not found', [
                    'file_path' => $document->file_path,
                    'disk' => config('pdf-viewer.storage.disk'),
                ]);
                abort(404, 'Original file not found');
            }

            // Get file content
            $content = $disk->get($document->file_path);
            
            // Complete audit trail
            $this->auditService->completeExtraction($audit);
            
            // Return file with proper headers for download
            $headers = [
                'Content-Type' => $document->mime_type ?: 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $document->original_filename . '"',
                'Cache-Control' => 'private, no-cache',
                'Content-Length' => strlen($content),
            ];
            
            return response($content, 200, $headers);
            
        } catch (\Exception $e) {
            Log::error('Original document download failed', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            abort(500, 'Failed to download original document');
        }
    }

    /**
     * Get compliance report for document
     */
    public function complianceReport(string $documentHash): JsonResponse
    {
        try {
            $report = $this->auditService->getComplianceReport($documentHash);
            
            return response()->json([
                'data' => $report,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate compliance report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get monitoring dashboard data
     */
    public function monitoringDashboard(): JsonResponse
    {
        try {
            $dashboardData = $this->monitoringService->getDashboardData();
            
            return response()->json([
                'data' => $dashboardData,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve monitoring data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed document processing progress
     */
    public function detailedProgress(string $documentHash): JsonResponse
    {
        try {
            $document = $this->documentService->findByHash($documentHash);
            
            if (!$document) {
                return response()->json(['message' => 'Document not found'], 404);
            }
            
            $progressData = $this->monitoringService->monitorDocumentProgress($document);
            
            return response()->json([
                'data' => $progressData,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve detailed progress',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cross-reference navigation script for document
     */
    public function crossReferenceScript(string $documentHash): \Illuminate\Http\Response
    {
        try {
            $document = $this->documentService->findByHash($documentHash);
            
            if (!$document) {
                abort(404, 'Document not found');
            }

            // Get cross-reference map from cache
            $crossRefMap = $this->crossRefService->getCachedCrossReferenceMap($documentHash);
            
            if (!$crossRefMap) {
                // Generate cross-reference map if not cached
                $crossRefMap = $this->crossRefService->analyzeCrossReferences($document);
            }
            
            // Generate JavaScript for cross-reference navigation
            $jsCode = $this->crossRefService->generateCrossReferenceNavigation($documentHash, $crossRefMap);
            
            return response($jsCode, 200, [
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'public, max-age=3600',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Cross-reference script generation failed', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            
            // Return empty script on error
            return response('// Cross-reference navigation unavailable', 200, [
                'Content-Type' => 'application/javascript',
            ]);
        }
    }
}