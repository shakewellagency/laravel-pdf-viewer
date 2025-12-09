<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfDocumentLink extends Model
{
    use HasUuids;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_UNKNOWN = 'unknown';

    protected $fillable = [
        'pdf_document_id',
        'source_page',
        'type',
        'destination_page',
        'destination_url',
        'coord_x',
        'coord_y',
        'coord_width',
        'coord_height',
        'coord_x_percent',
        'coord_y_percent',
        'coord_width_percent',
        'coord_height_percent',
    ];

    protected $casts = [
        'source_page' => 'integer',
        'destination_page' => 'integer',
        'coord_x' => 'float',
        'coord_y' => 'float',
        'coord_width' => 'float',
        'coord_height' => 'float',
        'coord_x_percent' => 'float',
        'coord_y_percent' => 'float',
        'coord_width_percent' => 'float',
        'coord_height_percent' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the PDF document this link belongs to
     */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    /**
     * Check if this is an internal link
     */
    public function isInternal(): bool
    {
        return $this->type === self::TYPE_INTERNAL;
    }

    /**
     * Check if this is an external link
     */
    public function isExternal(): bool
    {
        return $this->type === self::TYPE_EXTERNAL;
    }

    /**
     * Get the absolute coordinates as an array
     */
    public function getAbsoluteCoordinatesAttribute(): array
    {
        return [
            'x' => $this->coord_x,
            'y' => $this->coord_y,
            'width' => $this->coord_width,
            'height' => $this->coord_height,
        ];
    }

    /**
     * Get the normalized (percentage) coordinates as an array
     */
    public function getNormalizedCoordinatesAttribute(): array
    {
        return [
            'x_percent' => $this->coord_x_percent,
            'y_percent' => $this->coord_y_percent,
            'width_percent' => $this->coord_width_percent,
            'height_percent' => $this->coord_height_percent,
        ];
    }

    /**
     * Scope to get only internal links
     */
    public function scopeInternal($query)
    {
        return $query->where('type', self::TYPE_INTERNAL);
    }

    /**
     * Scope to get only external links
     */
    public function scopeExternal($query)
    {
        return $query->where('type', self::TYPE_EXTERNAL);
    }

    /**
     * Scope to get links for a specific page
     */
    public function scopeForPage($query, int $pageNumber)
    {
        return $query->where('source_page', $pageNumber);
    }

    /**
     * Scope to get links pointing to a specific page
     */
    public function scopePointingToPage($query, int $pageNumber)
    {
        return $query->where('type', self::TYPE_INTERNAL)
            ->where('destination_page', $pageNumber);
    }

    /**
     * Get links grouped by source page for a document
     */
    public static function getGroupedByPageForDocument(string $documentId): array
    {
        return static::where('pdf_document_id', $documentId)
            ->orderBy('source_page')
            ->get()
            ->groupBy('source_page')
            ->toArray();
    }
}
