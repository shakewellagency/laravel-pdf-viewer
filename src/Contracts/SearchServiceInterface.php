<?php

namespace Shakewellagency\LaravelPdfViewer\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Search Service Interface - Single Responsibility: Full-text search operations
 */
interface SearchServiceInterface
{
    /**
     * Search documents by text content
     */
    public function searchDocuments(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Search pages within a document
     */
    public function searchPages(string $documentHash, string $query, int $perPage = 15): LengthAwarePaginator;

    /**
     * Get search suggestions/autocomplete
     */
    public function getSuggestions(string $query, int $limit = 10): array;

    /**
     * Highlight search terms in content
     */
    public function highlightContent(string $content, string $query): string;

    /**
     * Generate search snippet
     */
    public function generateSnippet(string $content, string $query, int $length = 200): string;

    /**
     * Calculate relevance score
     */
    public function calculateRelevance(string $content, string $query): float;

    /**
     * Index page content for search
     */
    public function indexPage(string $documentHash, int $pageNumber, string $content): bool;

    /**
     * Remove document from search index
     */
    public function removeFromIndex(string $documentHash): bool;

    /**
     * Rebuild search index
     */
    public function rebuildIndex(): bool;

    /**
     * Get search statistics
     */
    public function getSearchStats(): array;
}