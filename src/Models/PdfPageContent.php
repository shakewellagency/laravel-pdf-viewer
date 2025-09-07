<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PdfPageContent Model
 * 
 * Stores the actual text content extracted from PDF pages.
 * Separated from PdfDocumentPage for optimal performance:
 * - Page metadata operations don't load large text content
 * - Search operations work on optimized content-only table
 * - Better cache utilization and reduced memory usage
 */
class PdfPageContent extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'pdf_page_content';

    protected $fillable = [
        'page_id',
        'content',
        'content_hash',
        'content_length',
        'word_count',
        'extracted_at',
    ];

    protected $casts = [
        'content_length' => 'integer',
        'word_count' => 'integer',
        'extracted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the page that owns this content
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(PdfDocumentPage::class, 'page_id');
    }

    /**
     * Get the document through the page relationship
     */
    public function document(): BelongsTo
    {
        return $this->page()->getRelated()->document();
    }

    /**
     * Scope for searching content using MySQL FULLTEXT
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->whereRaw(
            'MATCH(content) AGAINST(? IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION)',
            [$searchTerm]
        );
    }

    /**
     * Scope for searching with relevance score
     */
    public function scopeSearchWithRelevance($query, string $searchTerm)
    {
        return $query->selectRaw(
            '*, MATCH(content) AGAINST(? IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION) as relevance_score',
            [$searchTerm]
        )->whereRaw(
            'MATCH(content) AGAINST(? IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION)',
            [$searchTerm]
        )->orderBy('relevance_score', 'desc');
    }

    /**
     * Scope for finding similar content by hash
     */
    public function scopeBySimilarHash($query, string $hash, float $similarity = 0.8)
    {
        // This could be enhanced with more sophisticated similarity matching
        return $query->where('content_hash', $hash);
    }

    /**
     * Scope for content with minimum word count
     */
    public function scopeMinWords($query, int $minWords)
    {
        return $query->where('word_count', '>=', $minWords);
    }

    /**
     * Scope for content extracted within date range
     */
    public function scopeExtractedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('extracted_at', [$startDate, $endDate]);
    }

    /**
     * Generate content hash for deduplication
     */
    public static function generateContentHash(string $content): string
    {
        return hash('sha256', trim($content));
    }

    /**
     * Create or update content for a page
     */
    public static function createOrUpdateForPage(PdfDocumentPage $page, string $content): self
    {
        $contentLength = mb_strlen($content);
        $wordCount = str_word_count(strip_tags($content));
        $contentHash = self::generateContentHash($content);

        return self::updateOrCreate(
            ['page_id' => $page->id],
            [
                'content' => $content,
                'content_hash' => $contentHash,
                'content_length' => $contentLength,
                'word_count' => $wordCount,
                'extracted_at' => now(),
            ]
        );
    }

    /**
     * Get content statistics
     */
    public static function getContentStats(): array
    {
        $stats = self::selectRaw('
            COUNT(*) as total_pages,
            SUM(content_length) as total_characters,
            SUM(word_count) as total_words,
            AVG(content_length) as avg_characters_per_page,
            AVG(word_count) as avg_words_per_page,
            MAX(content_length) as max_characters,
            MIN(content_length) as min_characters
        ')->first();

        return [
            'total_pages' => $stats->total_pages ?? 0,
            'total_characters' => $stats->total_characters ?? 0,
            'total_words' => $stats->total_words ?? 0,
            'avg_characters_per_page' => round($stats->avg_characters_per_page ?? 0, 2),
            'avg_words_per_page' => round($stats->avg_words_per_page ?? 0, 2),
            'max_characters' => $stats->max_characters ?? 0,
            'min_characters' => $stats->min_characters ?? 0,
        ];
    }
}