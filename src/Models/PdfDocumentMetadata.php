<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfDocumentMetadata extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_document_id',
        'key',
        'value',
        'type',
    ];

    protected $casts = [
        'value' => 'string',
    ];

    /**
     * Get the document that owns this metadata
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    /**
     * Get the typed value based on the type field
     */
    public function getTypedValueAttribute()
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'date' => $this->value ? \Carbon\Carbon::parse($this->value) : null,
            default => $this->value,
        };
    }

    /**
     * Set the typed value based on PHP type
     */
    public function setTypedValue($value): void
    {
        if (is_bool($value)) {
            $this->type = 'boolean';
            $this->value = $value ? '1' : '0';
        } elseif (is_int($value)) {
            $this->type = 'integer';
            $this->value = (string) $value;
        } elseif (is_float($value)) {
            $this->type = 'float';
            $this->value = (string) $value;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->type = 'date';
            $this->value = $value->format('Y-m-d H:i:s');
        } else {
            $this->type = 'string';
            $this->value = (string) $value;
        }
    }

    /**
     * Scope to get metadata by key
     */
    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Scope to get metadata for specific document
     */
    public function scopeForDocument($query, string $documentId)
    {
        return $query->where('pdf_document_id', $documentId);
    }
}