<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfDocumentOutline extends Model
{
    use HasUuids;

    public const DESTINATION_TYPE_PAGE = 'page';
    public const DESTINATION_TYPE_NAMED = 'named';

    protected $fillable = [
        'pdf_document_id',
        'parent_id',
        'title',
        'level',
        'destination_page',
        'destination_type',
        'destination_name',
        'order_index',
    ];

    protected $casts = [
        'level' => 'integer',
        'destination_page' => 'integer',
        'order_index' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the PDF document this outline belongs to
     */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    /**
     * Get the parent outline entry
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PdfDocumentOutline::class, 'parent_id');
    }

    /**
     * Get child outline entries
     */
    public function children(): HasMany
    {
        return $this->hasMany(PdfDocumentOutline::class, 'parent_id')->orderBy('order_index');
    }

    /**
     * Get all descendants recursively
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get the full path of titles from root to this entry
     */
    public function getTitlePathAttribute(): array
    {
        $path = [$this->title];
        $current = $this->parent;

        while ($current) {
            array_unshift($path, $current->title);
            $current = $current->parent;
        }

        return $path;
    }

    /**
     * Scope to get only root level entries (no parent)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to get entries for a specific level
     */
    public function scopeAtLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope to order by order_index
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order_index');
    }

    /**
     * Check if destination is a named destination
     */
    public function isNamedDestination(): bool
    {
        return $this->destination_type === self::DESTINATION_TYPE_NAMED;
    }

    /**
     * Check if destination is a page destination
     */
    public function isPageDestination(): bool
    {
        return $this->destination_type === self::DESTINATION_TYPE_PAGE;
    }

    /**
     * Get the hierarchical tree structure for a document
     */
    public static function getTreeForDocument(string $documentId): array
    {
        $entries = static::where('pdf_document_id', $documentId)
            ->whereNull('parent_id')
            ->with('descendants')
            ->orderBy('order_index')
            ->get();

        return $entries->map(fn($entry) => static::formatTreeEntry($entry))->toArray();
    }

    /**
     * Format a single entry for tree output
     */
    protected static function formatTreeEntry(PdfDocumentOutline $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'level' => $entry->level,
            'destination_page' => $entry->destination_page,
            'children' => $entry->children->map(fn($child) => static::formatTreeEntry($child))->toArray(),
        ];
    }
}
