<?php

namespace Shakewellagency\LaravelPdfViewer\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Requests\SearchDocumentsRequest;
use Shakewellagency\LaravelPdfViewer\Requests\SearchPagesRequest;
use Shakewellagency\LaravelPdfViewer\Requests\SearchSuggestionsRequest;
use Shakewellagency\LaravelPdfViewer\Resources\DocumentSearchResource;
use Shakewellagency\LaravelPdfViewer\Resources\PageSearchResource;

class SearchController extends Controller
{
    public function __construct(
        protected SearchServiceInterface $searchService
    ) {}

    public function searchDocuments(SearchDocumentsRequest $request): JsonResponse
    {
        $query = $request->input('q', '');
        $perPage = $request->input('per_page', 15);
        
        if (strlen(trim($query)) < config('pdf-viewer.search.min_query_length', 3)) {
            return response()->json([
                'message' => 'Query must be at least ' . config('pdf-viewer.search.min_query_length', 3) . ' characters long',
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                ],
            ], 422);
        }

        try {
            $filters = $request->only(['status', 'created_by', 'date_from', 'date_to']);
            $results = $this->searchService->searchDocuments($query, $filters, $perPage);

            return response()->json([
                'data' => DocumentSearchResource::collection($results->items()),
                'meta' => [
                    'query' => $query,
                    'current_page' => $results->currentPage(),
                    'from' => $results->firstItem(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'to' => $results->lastItem(),
                    'total' => $results->total(),
                    'filters' => $filters,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Search failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function searchPages(SearchPagesRequest $request, string $documentHash): JsonResponse
    {
        $query = $request->input('q', '');
        $perPage = $request->input('per_page', 15);
        
        if (strlen(trim($query)) < config('pdf-viewer.search.min_query_length', 3)) {
            return response()->json([
                'message' => 'Query must be at least ' . config('pdf-viewer.search.min_query_length', 3) . ' characters long',
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                ],
            ], 422);
        }

        try {
            $results = $this->searchService->searchPages($documentHash, $query, $perPage);

            if ($results->total() === 0) {
                // Check if document exists
                $documentService = app(\Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface::class);
                if (!$documentService->exists($documentHash)) {
                    return response()->json(['message' => 'Document not found'], 404);
                }
            }

            return response()->json([
                'data' => PageSearchResource::collection($results->items()),
                'meta' => [
                    'query' => $query,
                    'document_hash' => $documentHash,
                    'current_page' => $results->currentPage(),
                    'from' => $results->firstItem(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'to' => $results->lastItem(),
                    'total' => $results->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Page search failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function suggestions(SearchSuggestionsRequest $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = min($request->input('limit', 10), 50); // Cap at 50 suggestions

        if (strlen(trim($query)) < 2) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'query' => $query,
                    'limit' => $limit,
                    'count' => 0,
                ],
            ]);
        }

        try {
            $suggestions = $this->searchService->getSuggestions($query, $limit);

            return response()->json([
                'data' => $suggestions,
                'meta' => [
                    'query' => $query,
                    'limit' => $limit,
                    'count' => count($suggestions),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to get suggestions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}