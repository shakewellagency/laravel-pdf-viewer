<?php

namespace Shakewellagency\LaravelPdfViewer\Contracts;

/**
 * Cache Service Interface - Single Responsibility: Caching operations
 */
interface CacheServiceInterface
{
    /**
     * Cache document metadata
     */
    public function cacheDocumentMetadata(string $documentHash, array $metadata, ?int $ttl = null): bool;

    /**
     * Get cached document metadata
     */
    public function getCachedDocumentMetadata(string $documentHash): ?array;

    /**
     * Cache page content
     */
    public function cachePageContent(string $documentHash, int $pageNumber, array $content, ?int $ttl = null): bool;

    /**
     * Get cached page content
     */
    public function getCachedPageContent(string $documentHash, int $pageNumber): ?array;

    /**
     * Cache search results
     */
    public function cacheSearchResults(string $queryHash, array $results, ?int $ttl = null): bool;

    /**
     * Get cached search results
     */
    public function getCachedSearchResults(string $queryHash): ?array;

    /**
     * Cache document outline (TOC)
     */
    public function cacheOutline(string $documentHash, array $outline, ?int $ttl = null): bool;

    /**
     * Get cached document outline
     */
    public function getCachedOutline(string $documentHash): ?array;

    /**
     * Cache document links
     */
    public function cacheLinks(string $documentHash, array $links, ?int $ttl = null): bool;

    /**
     * Get cached document links
     */
    public function getCachedLinks(string $documentHash): ?array;

    /**
     * Cache page links
     */
    public function cachePageLinks(string $documentHash, int $pageNumber, array $links, ?int $ttl = null): bool;

    /**
     * Get cached page links
     */
    public function getCachedPageLinks(string $documentHash, int $pageNumber): ?array;

    /**
     * Invalidate document cache
     */
    public function invalidateDocumentCache(string $documentHash): bool;

    /**
     * Invalidate search cache
     */
    public function invalidateSearchCache(): bool;

    /**
     * Warm document cache after processing
     */
    public function warmDocumentCache(string $documentHash): bool;

    /**
     * Clear all PDF viewer cache
     */
    public function clearAllCache(): bool;

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array;

    /**
     * Generate cache key
     */
    public function generateCacheKey(string $prefix, array $params): string;
}