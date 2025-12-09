<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfDocumentLink extends Model
{
    use HasUuids;

    // Legacy type constants (for backwards compatibility)
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_UNKNOWN = 'unknown';

    // Destination type constants (new spec)
    public const DESTINATION_TYPE_PAGE = 'page';
    public const DESTINATION_TYPE_NAMED = 'named';
    public const DESTINATION_TYPE_EXTERNAL = 'external';

    /**
     * Disable updated_at as per ticket spec (only created_at)
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'pdf_document_id',
        'source_page_id',
        'source_page',
        'source_rect_x',
        'source_rect_y',
        'source_rect_width',
        'source_rect_height',
        'coord_x_percent',
        'coord_y_percent',
        'coord_width_percent',
        'coord_height_percent',
        'destination_page',
        'destination_type',
        'destination_name',
        'destination_url',
        'link_text',
        'type', // Legacy field
    ];

    protected $casts = [
        'source_page' => 'integer',
        'destination_page' => 'integer',
        'source_rect_x' => 'float',
        'source_rect_y' => 'float',
        'source_rect_width' => 'float',
        'source_rect_height' => 'float',
        'coord_x_percent' => 'float',
        'coord_y_percent' => 'float',
        'coord_width_percent' => 'float',
        'coord_height_percent' => 'float',
        'created_at' => 'datetime',
    ];

    /**
     * Get the PDF document this link belongs to
     */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    /**
     * Get the source page this link belongs to
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(PdfDocumentPage::class, 'source_page_id');
    }

    /**
     * Check if this is an internal link (legacy)
     */
    public function isInternal(): bool
    {
        return $this->type === self::TYPE_INTERNAL || $this->destination_type === self::DESTINATION_TYPE_PAGE;
    }

    /**
     * Check if this is an external link (legacy)
     */
    public function isExternal(): bool
    {
        return $this->type === self::TYPE_EXTERNAL || $this->destination_type === self::DESTINATION_TYPE_EXTERNAL;
    }

    /**
     * Check if this is a named destination
     */
    public function isNamedDestination(): bool
    {
        return $this->destination_type === self::DESTINATION_TYPE_NAMED;
    }

    /**
     * Get the source rectangle coordinates as an array
     */
    public function getSourceRectAttribute(): array
    {
        return [
            'x' => $this->source_rect_x,
            'y' => $this->source_rect_y,
            'width' => $this->source_rect_width,
            'height' => $this->source_rect_height,
        ];
    }

    /**
     * Get the absolute coordinates as an array (legacy accessor)
     */
    public function getAbsoluteCoordinatesAttribute(): array
    {
        return $this->source_rect;
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
