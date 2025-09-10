<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class CacheService implements CacheServiceInterface
{
    protected string $cacheStore;
    protected string $prefix;
    protected array $tags;

    public function __construct()
    {
        $this->cacheStore = config('pdf-viewer.cache.store', 'redis');
        $this->prefix = config('pdf-viewer.cache.prefix', 'pdf_viewer');
        $this->tags = config('pdf-viewer.cache.tags', [
            'documents' => 'pdf_viewer_documents',
            'pages' => 'pdf_viewer_pages',
            'search' => 'pdf_viewer_search',
        ]);
    }

    public function cacheDocumentMetadata(string $documentHash, array $metadata, ?int $ttl = null): bool
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return false;
        }

        try {
            $key = $this->generateCacheKey('document_metadata', ['hash' => $documentHash]);
            $ttl = $ttl ?? config('pdf-viewer.cache.ttl.document_metadata', 3600);

            if ($this->supportsTags()) {
                Cache::store($this->cacheStore)
                    ->tags([$this->tags['documents']])
                    ->put($key, $metadata, $ttl);
            } else {
                Cache::store($this->cacheStore)->put($key, $metadata, $ttl);
            }

            if (config('pdf-viewer.monitoring.log_cache')) {
                Log::debug('Document metadata cached', [
                    'document_hash' => $documentHash,
                    'cache_key' => $key,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cache document metadata', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCachedDocumentMetadata(string $documentHash): ?array
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return null;
        }

        try {
            $key = $this->generateCacheKey('document_metadata', ['hash' => $documentHash]);
            
            if ($this->supportsTags()) {
                $cached = Cache::store($this->cacheStore)
                    ->tags([$this->tags['documents']])
                    ->get($key);
            } else {
                $cached = Cache::store($this->cacheStore)->get($key);
            }

            return $cached;
        } catch (\Exception $e) {
            Log::error('Failed to get cached document metadata', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function cachePageContent(string $documentHash, int $pageNumber, array $content, ?int $ttl = null): bool
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return false;
        }

        try {
            $key = $this->generateCacheKey('page_content', [
                'hash' => $documentHash,
                'page' => $pageNumber,
            ]);
            $ttl = $ttl ?? config('pdf-viewer.cache.ttl.page_content', 7200);

            if ($this->supportsTags()) {
                Cache::store($this->cacheStore)
                    ->tags([$this->tags['pages'], $this->tags['documents']])
                    ->put($key, $content, $ttl);
            } else {
                Cache::store($this->cacheStore)->put($key, $content, $ttl);
            }

            if (config('pdf-viewer.monitoring.log_cache')) {
                Log::debug('Page content cached', [
                    'document_hash' => $documentHash,
                    'page_number' => $pageNumber,
                    'cache_key' => $key,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cache page content', [
                'document_hash' => $documentHash,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCachedPageContent(string $documentHash, int $pageNumber): ?array
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return null;
        }

        try {
            $key = $this->generateCacheKey('page_content', [
                'hash' => $documentHash,
                'page' => $pageNumber,
            ]);

            if ($this->supportsTags()) {
                $cached = Cache::store($this->cacheStore)
                    ->tags([$this->tags['pages']])
                    ->get($key);
            } else {
                $cached = Cache::store($this->cacheStore)->get($key);
            }

            return $cached;
        } catch (\Exception $e) {
            Log::error('Failed to get cached page content', [
                'document_hash' => $documentHash,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function cacheSearchResults(string $queryHash, array $results, ?int $ttl = null): bool
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return false;
        }

        try {
            $key = $this->generateCacheKey('search_results', ['query_hash' => $queryHash]);
            $ttl = $ttl ?? config('pdf-viewer.cache.ttl.search_results', 1800);

            if ($this->supportsTags()) {
                Cache::store($this->cacheStore)
                    ->tags([$this->tags['search']])
                    ->put($key, $results, $ttl);
            } else {
                Cache::store($this->cacheStore)->put($key, $results, $ttl);
            }

            if (config('pdf-viewer.monitoring.log_cache')) {
                Log::debug('Search results cached', [
                    'query_hash' => $queryHash,
                    'cache_key' => $key,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cache search results', [
                'query_hash' => $queryHash,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCachedSearchResults(string $queryHash): ?array
    {
        if (!config('pdf-viewer.cache.enabled', true)) {
            return null;
        }

        try {
            $key = $this->generateCacheKey('search_results', ['query_hash' => $queryHash]);

            if ($this->supportsTags()) {
                $cached = Cache::store($this->cacheStore)
                    ->tags([$this->tags['search']])
                    ->get($key);
            } else {
                $cached = Cache::store($this->cacheStore)->get($key);
            }

            return $cached;
        } catch (\Exception $e) {
            Log::error('Failed to get cached search results', [
                'query_hash' => $queryHash,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function invalidateDocumentCache(string $documentHash): bool
    {
        try {
            if ($this->supportsTags()) {
                // Flush all document-related cache
                Cache::store($this->cacheStore)->tags([$this->tags['documents']])->flush();
            } else {
                // Manually remove specific keys (less efficient)
                $patterns = [
                    $this->generateCacheKey('document_metadata', ['hash' => $documentHash]),
                ];

                // Also remove all page content for this document
                $document = PdfDocument::findByHash($documentHash);
                if ($document) {
                    for ($i = 1; $i <= $document->page_count; $i++) {
                        $patterns[] = $this->generateCacheKey('page_content', [
                            'hash' => $documentHash,
                            'page' => $i,
                        ]);
                    }
                }

                foreach ($patterns as $key) {
                    Cache::store($this->cacheStore)->forget($key);
                }
            }

            Log::info('Document cache invalidated', ['document_hash' => $documentHash]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to invalidate document cache', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function invalidateSearchCache(): bool
    {
        try {
            if ($this->supportsTags()) {
                Cache::store($this->cacheStore)->tags([$this->tags['search']])->flush();
            } else {
                // For stores without tag support, we'd need to track keys manually
                // This is a limitation when not using Redis/Memcached
                Log::warning('Search cache invalidation limited without tag support');
            }

            Log::info('Search cache invalidated');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to invalidate search cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function warmDocumentCache(string $documentHash): bool
    {
        try {
            $document = PdfDocument::findByHash($documentHash);
            
            if (!$document) {
                return false;
            }

            // Cache document metadata
            $metadata = [
                'id' => $document->id,
                'hash' => $document->hash,
                'title' => $document->title,
                'filename' => $document->original_filename,
                'file_size' => $document->file_size,
                'page_count' => $document->page_count,
                'status' => $document->status,
                'is_searchable' => $document->is_searchable,
            ];
            $this->cacheDocumentMetadata($documentHash, $metadata);

            // Cache first few pages content
            $pagesToWarm = min(5, $document->page_count);
            $document->pages()
                ->completed()
                ->limit($pagesToWarm)
                ->get()
                ->each(function ($page) use ($documentHash) {
                    $pageData = [
                        'content' => $page->content,
                        'page_number' => $page->page_number,
                        'word_count' => $page->word_count,
                    ];
                    $this->cachePageContent($documentHash, $page->page_number, $pageData);
                });

            Log::info('Document cache warmed', [
                'document_hash' => $documentHash,
                'pages_warmed' => $pagesToWarm,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to warm document cache', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function clearAllCache(): bool
    {
        try {
            if ($this->supportsTags()) {
                Cache::store($this->cacheStore)->tags(array_values($this->tags))->flush();
            } else {
                // Clear entire cache store (more aggressive)
                Cache::store($this->cacheStore)->flush();
            }

            Log::info('All PDF viewer cache cleared');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear all cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getCacheStats(): array
    {
        // Basic cache statistics
        // Note: Real implementation would depend on cache driver capabilities
        return [
            'cache_enabled' => config('pdf-viewer.cache.enabled', true),
            'cache_store' => $this->cacheStore,
            'tags_supported' => $this->supportsTags(),
            'prefix' => $this->prefix,
            'total_keys' => 0, // Placeholder - would need cache driver specific implementation
            'memory_usage' => 0, // Placeholder - would need cache driver specific implementation
        ];
    }

    public function generateCacheKey(string $prefix, array $params): string
    {
        ksort($params); // Ensure consistent key generation
        $paramString = http_build_query($params);
        return $this->prefix . ':' . $prefix . ':' . md5($paramString);
    }

    protected function supportsTags(): bool
    {
        // Only Redis and Memcached support tags in Laravel
        return in_array($this->cacheStore, ['redis', 'memcached']);
    }
}