<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class PdfDocumentPage extends Model
{
    use HasFactory, HasUuids, SoftDeletes, Searchable;

    protected $fillable = [
        'pdf_document_id',
        'page_number',
        'content',
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
            'content' => $this->content,
        ];
    }

    /**
     * Determine if the model should be searchable
     */
    public function shouldBeSearchable(): bool
    {
        return $this->is_parsed && 
               !empty($this->content) && 
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
     * Get content snippet around search term
     */
    public function getSearchSnippet(string $query, int $length = 200): string
    {
        if (empty($this->content) || empty($query)) {
            return '';
        }

        $content = strip_tags($this->content);
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
        if (empty($this->content) || empty($query)) {
            return $this->content;
        }

        return preg_replace(
            '/(' . preg_quote($query, '/') . ')/i',
            "<{$tag}>$1</{$tag}>",
            $this->content
        );
    }

    /**
     * Calculate text content length
     */
    public function getContentLengthAttribute(): int
    {
        return strlen(strip_tags($this->content ?? ''));
    }

    /**
     * Get word count
     */
    public function getWordCountAttribute(): int
    {
        if (empty($this->content)) {
            return 0;
        }

        return str_word_count(strip_tags($this->content));
    }

    /**
     * Check if page has content
     */
    public function hasContent(): bool
    {
        return !empty($this->content) && trim($this->content) !== '';
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
        return $query->whereNotNull('content')->where('content', '!=', '');
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
}