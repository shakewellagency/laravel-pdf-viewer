<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfAuditSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_extraction_audit_id',
        'setting_key',
        'setting_value',
        'setting_type',
    ];

    protected $casts = [
        'setting_value' => 'string',
    ];

    /**
     * Get the audit that owns this setting
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionAudit::class, 'pdf_extraction_audit_id');
    }

    /**
     * Get the typed value based on the type field
     */
    public function getTypedValueAttribute()
    {
        return match ($this->setting_type) {
            'integer' => (int) $this->setting_value,
            'float' => (float) $this->setting_value,
            'boolean' => filter_var($this->setting_value, FILTER_VALIDATE_BOOLEAN),
            default => $this->setting_value,
        };
    }

    /**
     * Set the typed value based on PHP type
     */
    public function setTypedValue($value): void
    {
        if (is_bool($value)) {
            $this->setting_type = 'boolean';
            $this->setting_value = $value ? '1' : '0';
        } elseif (is_int($value)) {
            $this->setting_type = 'integer';
            $this->setting_value = (string) $value;
        } elseif (is_float($value)) {
            $this->setting_type = 'float';
            $this->setting_value = (string) $value;
        } else {
            $this->setting_type = 'string';
            $this->setting_value = (string) $value;
        }
    }

    /**
     * Scope to get settings by key
     */
    public function scopeByKey($query, string $key)
    {
        return $query->where('setting_key', $key);
    }

    /**
     * Scope to get settings for specific audit
     */
    public function scopeForAudit($query, string $auditId)
    {
        return $query->where('pdf_extraction_audit_id', $auditId);
    }
}