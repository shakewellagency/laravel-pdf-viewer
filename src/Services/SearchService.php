<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfPageContent;

class SearchService implements SearchServiceInterface
{
    public function __construct(
        protected CacheServiceInterface $cacheService
    ) {}

    public function searchDocuments(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->sanitizeQuery($query);
        
        if (strlen($query) < config('pdf-viewer.search.min_query_length', 3)) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        // Check cache first
        $cacheKey = $this->generateSearchCacheKey('documents', $query, $filters, $perPage);
        $cached = $this->cacheService->getCachedSearchResults($cacheKey);
        
        if ($cached) {
            return $this->paginateFromCache($cached, $perPage);
        }

        // Build search query using the separated content table
        $searchQuery = PdfDocument::query()
            ->searchable()
            ->whereHas('pages.content', function (Builder $contentQuery) use ($query) {
                $contentQuery->search($query);
            })
            ->with(['pages' => function ($pageQuery) use ($query) {
                $pageQuery->completed()
                    ->with(['content' => function ($contentQuery) use ($query) {
                        $contentQuery->searchWithRelevance($query);
                    }]);
            }]);

        // Apply filters
        $this->applyFilters($searchQuery, $filters);

        // Execute search with relevance scoring using the separated content table
        $results = $searchQuery
            ->select([
                'pdf_documents.*',
                DB::raw('(
                    SELECT MAX(MATCH(pdf_page_content.content) AGAINST(? IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION))
                    FROM pdf_page_content
                    INNER JOIN pdf_document_pages ON pdf_page_content.page_id = pdf_document_pages.id
                    WHERE pdf_document_pages.pdf_document_id = pdf_documents.id 
                    AND pdf_document_pages.status = "completed"
                ) as relevance_score')
            ])
            ->addBinding($query, 'select')
            ->orderBy('relevance_score', 'desc')
            ->paginate($perPage);

        // Process results for better presentation
        $processedResults = $results->getCollection()->map(function ($document) use ($query) {
            $document->search_snippets = $this->generateDocumentSnippets($document, $query);
            $document->relevance_score = round($document->relevance_score, 4);
            return $document;
        });

        $results->setCollection($processedResults);

        // Cache results
        $this->cacheService->cacheSearchResults($cacheKey, $results->toArray());

        if (config('pdf-viewer.monitoring.log_search')) {
            Log::info('Document search performed', [
                'query' => $query,
                'results_count' => $results->total(),
                'filters' => $filters,
            ]);
        }

        return $results;
    }

    public function searchPages(string $documentHash, string $query, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->sanitizeQuery($query);
        
        if (strlen($query) < config('pdf-viewer.search.min_query_length', 3)) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document || !$document->is_searchable) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        // Check cache first
        $cacheKey = $this->generateSearchCacheKey('pages', $query, ['document' => $documentHash], $perPage);
        $cached = $this->cacheService->getCachedSearchResults($cacheKey);
        
        if ($cached) {
            return $this->paginateFromCache($cached, $perPage);
        }

        // Execute search within document pages using the separated content table
        $results = PdfDocumentPage::query()
            ->where('pdf_document_id', $document->id)
            ->completed()
            ->whereHas('content', function (Builder $contentQuery) use ($query) {
                $contentQuery->search($query);
            })
            ->join('pdf_page_content', 'pdf_document_pages.id', '=', 'pdf_page_content.page_id')
            ->select([
                'pdf_document_pages.*',
                DB::raw('MATCH(pdf_page_content.content) AGAINST(? IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION) as relevance_score')
            ])
            ->addBinding($query, 'select')
            ->orderBy('relevance_score', 'desc')
            ->orderBy('page_number', 'asc')
            ->with(['document', 'content'])
            ->paginate($perPage);

        // Process results with snippets
        $processedResults = $results->getCollection()->map(function ($page) use ($query) {
            $contentText = $page->content ? $page->content->content : '';
            $page->search_snippet = $this->generateSnippet($contentText, $query);
            $page->highlighted_content = $this->highlightContent($contentText, $query);
            $page->relevance_score = round($page->relevance_score, 4);
            return $page;
        });

        $results->setCollection($processedResults);

        // Cache results
        $this->cacheService->cacheSearchResults($cacheKey, $results->toArray());

        return $results;
    }

    public function getSuggestions(string $query, int $limit = 10): array
    {
        $query = $this->sanitizeQuery($query);
        
        if (strlen($query) < 2) {
            return [];
        }

        // Get common terms from indexed content using the separated content table
        $suggestions = DB::table('pdf_page_content')
            ->join('pdf_document_pages', 'pdf_page_content.page_id', '=', 'pdf_document_pages.id')
            ->select(DB::raw('pdf_page_content.content'))
            ->where('pdf_document_pages.status', 'completed')
            ->where('pdf_document_pages.is_parsed', true)
            ->whereRaw('pdf_page_content.content LIKE ?', ['%' . $query . '%'])
            ->limit(50)
            ->get()
            ->flatMap(function ($pageContent) use ($query) {
                // Extract words around the query term
                preg_match_all('/\b\w*' . preg_quote($query, '/') . '\w*\b/i', $pageContent->content, $matches);
                return $matches[0];
            })
            ->unique()
            ->filter(function ($suggestion) use ($query) {
                return strlen($suggestion) > strlen($query) && strlen($suggestion) <= 50;
            })
            ->take($limit)
            ->values()
            ->toArray();

        return array_slice($suggestions, 0, $limit);
    }

    public function highlightContent(string $content, string $query): string
    {
        if (empty($content) || empty($query)) {
            return $content;
        }

        $tag = config('pdf-viewer.search.highlight_tag', 'mark');
        $words = explode(' ', $query);
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 2) {
                $content = preg_replace(
                    '/(' . preg_quote($word, '/') . ')/i',
                    "<{$tag}>$1</{$tag}>",
                    $content
                );
            }
        }

        return $content;
    }

    public function generateSnippet(string $content, string $query, int $length = 200): string
    {
        if (empty($content) || empty($query)) {
            return '';
        }

        $length = config('pdf-viewer.search.snippet_length', $length);
        $content = strip_tags($content);
        
        // Find the position of the query in the content
        $queryPos = stripos($content, $query);
        
        if ($queryPos === false) {
            // If exact query not found, try first word
            $words = explode(' ', $query);
            $firstWord = trim($words[0]);
            $queryPos = stripos($content, $firstWord);
        }

        if ($queryPos === false) {
            // Return beginning of content if no match found
            return substr($content, 0, $length) . '...';
        }

        // Calculate snippet start position
        $start = max(0, $queryPos - ($length / 2));
        $snippet = substr($content, $start, $length);

        // Add ellipsis if content is truncated
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        
        if (strlen($content) > $start + $length) {
            $snippet .= '...';
        }

        return trim($snippet);
    }

    public function calculateRelevance(string $content, string $query): float
    {
        if (empty($content) || empty($query)) {
            return 0.0;
        }

        $content = strtolower(strip_tags($content));
        $query = strtolower($query);
        
        // Simple relevance calculation based on term frequency
        $queryWords = explode(' ', $query);
        $contentWords = explode(' ', $content);
        $totalWords = count($contentWords);
        
        if ($totalWords === 0) {
            return 0.0;
        }

        $matchCount = 0;
        foreach ($queryWords as $queryWord) {
            $queryWord = trim($queryWord);
            if (strlen($queryWord) >= 2) {
                $matchCount += substr_count($content, $queryWord);
            }
        }

        return round(($matchCount / $totalWords) * 100, 2);
    }

    public function indexPage(string $documentHash, int $pageNumber, string $content): bool
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document) {
            return false;
        }

        $page = PdfDocumentPage::where('pdf_document_id', $document->id)
            ->where('page_number', $pageNumber)
            ->first();

        if (!$page) {
            return false;
        }

        // Update page status
        $page->update([
            'is_parsed' => true,
            'status' => 'completed',
        ]);

        // Store content in separated table
        PdfPageContent::createOrUpdateForPage($page, $content);

        return true;
    }

    public function removeFromIndex(string $documentHash): bool
    {
        $document = PdfDocument::findByHash($documentHash);
        
        if (!$document) {
            return false;
        }

        // Remove content from separated table
        $pageIds = $document->pages()->pluck('id');
        PdfPageContent::whereIn('page_id', $pageIds)->delete();

        // Update page status
        $document->pages()->update([
            'is_parsed' => false,
        ]);

        $document->update(['is_searchable' => false]);

        return true;
    }

    public function rebuildIndex(): bool
    {
        try {
            // This would typically involve reprocessing all documents
            // For now, we'll just mark all as needing reindexing
            PdfDocument::completed()->update(['is_searchable' => false]);
            
            // In a real implementation, you'd dispatch jobs to reprocess content
            Log::info('Search index rebuild initiated');
            
            return true;
        } catch (\Exception $e) {
            Log::error('Search index rebuild failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getSearchStats(): array
    {
        return [
            'total_documents' => PdfDocument::count(),
            'searchable_documents' => PdfDocument::searchable()->count(),
            'total_pages' => PdfDocumentPage::count(),
            'indexed_pages' => PdfDocumentPage::parsed()->count(),
            'total_content_size' => DB::table('pdf_page_content')
                ->selectRaw('SUM(content_length) as total_size')
                ->value('total_size') ?? 0,
            'content_stats' => PdfPageContent::getContentStats(),
        ];
    }

    protected function sanitizeQuery(string $query): string
    {
        // Remove potentially harmful characters
        $query = strip_tags($query);
        $query = preg_replace('/[^\w\s\-\"\']/u', '', $query);
        $query = trim($query);
        
        // Limit query length
        $maxLength = config('pdf-viewer.search.max_query_length', 255);
        if (strlen($query) > $maxLength) {
            $query = substr($query, 0, $maxLength);
        }

        return $query;
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
    }

    protected function generateDocumentSnippets(PdfDocument $document, string $query): array
    {
        return $document->pages->take(3)->map(function ($page) use ($query) {
            $contentText = $page->content ? $page->content->content : '';
            return [
                'page_number' => $page->page_number,
                'snippet' => $this->generateSnippet($contentText, $query),
            ];
        })->toArray();
    }

    protected function generateSearchCacheKey(string $type, string $query, array $filters, int $perPage): string
    {
        return md5($type . $query . serialize($filters) . $perPage);
    }

    protected function paginateFromCache(array $cached, int $perPage): LengthAwarePaginator
    {
        // This is a simplified implementation
        // In a real scenario, you'd need to properly reconstruct the paginator
        return new LengthAwarePaginator(
            collect($cached['data'] ?? []),
            $cached['total'] ?? 0,
            $perPage,
            $cached['current_page'] ?? 1
        );
    }
}