<?php

namespace Shakewellagency\LaravelPdfViewer\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Requests\PageIndexRequest;
use Shakewellagency\LaravelPdfViewer\Resources\PageResource;
use Shakewellagency\LaravelPdfViewer\Resources\LinkResource;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;

class PageController extends Controller
{
    public function __construct(
        protected DocumentServiceInterface $documentService,
        protected CacheServiceInterface $cacheService
    ) {}

    public function index(PageIndexRequest $request, string $documentHash): JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $perPage = $request->input('per_page', 20);
        $pages = $document->pages()
            ->when($request->filled('status'), fn($query) => $query->where('status', $request->status))
            ->when($request->filled('parsed_only'), fn($query) => $query->parsed())
            ->orderBy('page_number')
            ->paginate($perPage);

        return response()->json([
            'data' => PageResource::collection($pages->items()),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'from' => $pages->firstItem(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'to' => $pages->lastItem(),
                'total' => $pages->total(),
                'document' => [
                    'hash' => $document->hash,
                    'title' => $document->title,
                    'total_pages' => $document->page_count,
                ],
            ],
        ]);
    }

    public function show(string $documentHash, int $pageNumber): JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Check cache first
        $cachedContent = $this->cacheService->getCachedPageContent($documentHash, $pageNumber);
        
        if ($cachedContent) {
            return response()->json([
                'data' => array_merge($cachedContent, [
                    'document_hash' => $documentHash,
                    'cached' => true,
                ]),
            ]);
        }

        $page = $document->pages()->where('page_number', $pageNumber)->first();

        if (!$page) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        $pageData = new PageResource($page);

        // Cache the page content
        if ($page->status === 'completed' && $page->is_parsed) {
            $this->cacheService->cachePageContent($documentHash, $pageNumber, $pageData->toArray(request()));
        }

        return response()->json([
            'data' => $pageData,
        ]);
    }

    public function thumbnail(string $documentHash, int $pageNumber): Response|JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            abort(404, 'Document not found');
        }

        $page = $document->pages()->where('page_number', $pageNumber)->first();

        if (!$page || !$page->thumbnail_path) {
            abort(404, 'Thumbnail not found');
        }

        try {
            $disk = Storage::disk(config('pdf-viewer.storage.disk'));
            
            if (!$disk->exists($page->thumbnail_path)) {
                abort(404, 'Thumbnail file not found');
            }

            // Always return signed URL for consistent frontend handling
            $signedUrl = $this->documentService->getSignedUrl($page->thumbnail_path, 3600); // 1 hour
            
            return response()->json([
                'url' => $signedUrl,
                'expires_in' => 3600,
                'content_type' => 'image/jpeg'
            ]);

        } catch (\Exception $e) {
            abort(500, 'Failed to retrieve thumbnail');
        }
    }

    public function download(string $documentHash, int $pageNumber): Response|JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            abort(404, 'Document not found');
        }

        $page = $document->pages()->where('page_number', $pageNumber)->first();

        if (!$page || !$page->page_file_path) {
            abort(404, 'Page file not found');
        }

        try {
            $disk = Storage::disk(config('pdf-viewer.storage.disk'));
            
            if (!$disk->exists($page->page_file_path)) {
                abort(404, 'Page file not found');
            }

            // Always return signed URL for consistent frontend handling
            $signedUrl = $this->documentService->getSignedUrl($page->page_file_path, 1800); // 30 minutes
            $filename = $document->title . "_page_{$pageNumber}.pdf";
            
            return response()->json([
                'url' => $signedUrl,
                'expires_in' => 1800,
                'content_type' => 'application/pdf',
                'filename' => $filename
            ]);

        } catch (\Exception $e) {
            abort(500, 'Failed to download page');
        }
    }

    /**
     * Get links for a specific page
     */
    public function links(string $documentHash, int $pageNumber): JsonResponse
    {
        $document = $this->documentService->findByHash($documentHash);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $page = $document->pages()->where('page_number', $pageNumber)->first();

        if (!$page) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        try {
            // Try to get cached page links first
            $cachedLinks = $this->cacheService->getCachedPageLinks($documentHash, $pageNumber);

            if ($cachedLinks !== null) {
                return response()->json($cachedLinks);
            }

            // Get links for this specific page with optimized query
            $links = PdfDocumentLink::where('pdf_document_id', $document->id)
                ->where('source_page', $pageNumber)
                ->orderBy('id')
                ->get();

            $totalLinks = $links->count();
            $internalLinks = $links->where('type', 'internal')->count();
            $externalLinks = $links->where('type', 'external')->count();

            $response = [
                'data' => LinkResource::collection($links),
                'meta' => [
                    'document_hash' => $document->hash,
                    'page_number' => $pageNumber,
                    'total_links' => $totalLinks,
                    'internal_links' => $internalLinks,
                    'external_links' => $externalLinks,
                ],
            ];

            // Cache the response (24 hour TTL - link data is static)
            $this->cacheService->cachePageLinks($documentHash, $pageNumber, $response);

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve page links',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if the storage disk is S3
     */
    protected function isS3Disk($disk): bool
    {
        return method_exists($disk->getAdapter(), 'getBucket') ||
               (config('pdf-viewer.storage.disk') === 's3');
    }
}