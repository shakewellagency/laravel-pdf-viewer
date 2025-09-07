<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Shakewellagency\LaravelPdfViewer\Database\Factories\PdfDocumentPageFactory;

class PdfDocumentPage extends Model
{
    use HasFactory, HasUuids, SoftDeletes, Searchable;

    protected $fillable = [
        'pdf_document_id',
        'page_number',
        'page_file_path',
        'thumbnail_path',
        'metadata',
        'status',
        'processing_error',
        'is_parsed',
    ];

    protected $casts = [
        'metadata' => 'array',
        'page_number' => 'integer',
        'is_parsed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'page_file_path', // Hide internal file path from API responses
        'thumbnail_path', // Hide internal thumbnail path from API responses
    ];

    /**
     * Get the indexable data array for the model (Scout/Search)
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'document_hash' => $this->document->hash,
            'document_title' => $this->document->title,
            'page_number' => $this->page_number,
            'content' => $this->content ? $this->content->content : '',
        ];
    }

    /**
     * Determine if the model should be searchable
     */
    public function shouldBeSearchable(): bool
    {
        $hasContent = $this->content && !empty(trim($this->content->content ?? ''));
        return $this->is_parsed && 
               $hasContent && 
               $this->status === 'completed' &&
               $this->document->is_searchable;
    }

    /**
     * Get the document this page belongs to
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    /**
     * Get the page content (stored in separate table for performance)
     */
    public function content(): HasOne
    {
        return $this->hasOne(PdfPageContent::class, 'page_id');
    }

    /**
     * Get content snippet around search term
     */
    public function getSearchSnippet(string $query, int $length = 200): string
    {
        $contentText = $this->content ? $this->content->content : '';
        
        if (empty($contentText) || empty($query)) {
            return '';
        }

        $content = strip_tags($contentText);
        $queryPos = stripos($content, $query);

        if ($queryPos === false) {
            return substr($content, 0, $length) . '...';
        }

        $start = max(0, $queryPos - ($length / 2));
        $snippet = substr($content, $start, $length);

        // Add ellipsis if content is truncated
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        
        if (strlen($content) > $start + $length) {
            $snippet .= '...';
        }

        return $snippet;
    }

    /**
     * Highlight search terms in content
     */
    public function highlightContent(string $query, string $tag = 'mark'): string
    {
        $contentText = $this->content ? $this->content->content : '';
        
        if (empty($contentText) || empty($query)) {
            return $contentText;
        }

        return preg_replace(
            '/(' . preg_quote($query, '/') . ')/i',
            "<{$tag}>$1</{$tag}>",
            $contentText
        );
    }

    /**
     * Calculate text content length
     */
    public function getContentLengthAttribute(): int
    {
        $contentText = $this->content ? $this->content->content : '';
        return strlen(strip_tags($contentText));
    }

    /**
     * Get word count
     */
    public function getWordCountAttribute(): int
    {
        $contentText = $this->content ? $this->content->content : '';
        
        if (empty($contentText)) {
            return 0;
        }

        return str_word_count(strip_tags($contentText));
    }

    /**
     * Check if page has content
     */
    public function hasContent(): bool
    {
        $contentText = $this->content ? $this->content->content : '';
        return !empty($contentText) && trim($contentText) !== '';
    }

    /**
     * Check if page has thumbnail
     */
    public function hasThumbnail(): bool
    {
        return !empty($this->thumbnail_path) && file_exists(storage_path($this->thumbnail_path));
    }

    /**
     * Scope for parsed pages
     */
    public function scopeParsed($query)
    {
        return $query->where('is_parsed', true);
    }

    /**
     * Scope for pages with content
     */
    public function scopeWithContent($query)
    {
        return $query->whereHas('content', function ($contentQuery) {
            $contentQuery->whereNotNull('content')
                        ->where('content', '!=', '')
                        ->where('content_length', '>', 0);
        });
    }

    /**
     * Scope for completed pages
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed pages
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for pages in a specific document
     */
    public function scopeForDocument($query, string $documentHash)
    {
        return $query->whereHas('document', function ($q) use ($documentHash) {
            $q->where('hash', $documentHash);
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return PdfDocumentPageFactory::new();
    }
}