<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfDocumentOutline extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_document_id',
        'parent_id',
        'title',
        'level',
        'destination_page',
        'sort_order',
    ];

    protected $casts = [
        'level' => 'integer',
        'destination_page' => 'integer',
        'sort_order' => 'integer',
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
        return $this->hasMany(PdfDocumentOutline::class, 'parent_id')->orderBy('sort_order');
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
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the hierarchical tree structure for a document
     */
    public static function getTreeForDocument(string $documentId): array
    {
        $entries = static::where('pdf_document_id', $documentId)
            ->whereNull('parent_id')
            ->with('descendants')
            ->orderBy('sort_order')
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
