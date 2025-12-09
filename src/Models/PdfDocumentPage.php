<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Shakewellagency\LaravelPdfViewer\Database\Factories\PdfDocumentPageFactory;

class PdfDocumentPage extends Model
{
    use HasFactory, HasUuids, SoftDeletes; // Searchable trait removed for now

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
        'page_number' => 'integer',
        'metadata' => 'array',
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
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Shakewellagency\LaravelPdfViewer\Database\Factories\PdfDocumentPageFactory::new();
    }

    /**
     * Get the indexable data array for the model (Scout/Search)
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'document_hash' => $this->document->hash ?? null,
            'document_title' => $this->document->title ?? null,
            'page_number' => $this->page_number,
            'content' => $this->getContentText(),
        ];
    }

    /**
     * Determine if the model should be searchable
     */
    public function shouldBeSearchable(): bool
    {
        $hasContent = !empty(trim($this->getContentText()));
        return $this->is_parsed &&
               $hasContent &&
               $this->status === 'completed' &&
               optional($this->document)->is_searchable;
    }

    /**
     * Get the content text, preferring the direct column over the relation
     */
    public function getContentText(): string
    {
        // First check the direct content column
        if (!empty($this->attributes['content'] ?? null)) {
            return $this->attributes['content'];
        }

        // Fall back to the content relation if exists
        if ($this->relationLoaded('contentRecord') && $this->contentRecord) {
            return $this->contentRecord->content ?? '';
        }

        return '';
    }

    /**
     * Get the document this page belongs to
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    /**
     * Get the page content record (stored in separate table for performance)
     * Note: Named 'contentRecord' to avoid conflict with 'content' column
     */
    public function contentRecord(): HasOne
    {
        return $this->hasOne(PdfPageContent::class, 'page_id');
    }

    /**
     * Get content snippet around search term
     */
    public function getSearchSnippet(string $query, int $length = 200): string
    {
        $contentText = $this->getContentText();

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
        $contentText = $this->getContentText();

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
        $contentText = $this->getContentText();
        return strlen(strip_tags($contentText));
    }

    /**
     * Get word count
     */
    public function getWordCountAttribute(): int
    {
        $contentText = $this->getContentText();

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
        $contentText = $this->getContentText();
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
        return $query->whereNotNull('content')
                    ->where('content', '!=', '');
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
     * Get page metadata
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PdfPageMetadata::class);
    }

    /**
     * Get a specific metadata value by key
     */
    public function getMetadata(string $key, $default = null)
    {
        $metadata = $this->metadata()->where('key', $key)->first();
        return $metadata ? $metadata->typed_value : $default;
    }

    /**
     * Set a metadata value by key
     */
    public function setMetadata(string $key, $value): PdfPageMetadata
    {
        $metadata = $this->metadata()->updateOrCreate(
            ['key' => $key],
            []
        );
        
        $metadata->setTypedValue($value);
        $metadata->save();
        
        return $metadata;
    }

    /**
     * Get all metadata as associative array
     */
    public function getAllMetadata(): array
    {
        return $this->metadata->pluck('typed_value', 'key')->toArray();
    }
}