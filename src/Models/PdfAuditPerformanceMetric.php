<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfAuditPerformanceMetric extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_extraction_audit_id',
        'metric_name',
        'metric_value',
        'metric_unit',
        'recorded_at',
    ];

    protected $casts = [
        'metric_value' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    /**
     * Get the audit that owns this performance metric
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionAudit::class, 'pdf_extraction_audit_id');
    }

    /**
     * Format the metric value with unit
     */
    public function getFormattedValueAttribute(): string
    {
        return $this->metric_value . ' ' . $this->metric_unit;
    }

    /**
     * Record a new metric value
     */
    public static function recordMetric(string $auditId, string $name, float $value, string $unit): self
    {
        return self::create([
            'pdf_extraction_audit_id' => $auditId,
            'metric_name' => $name,
            'metric_value' => $value,
            'metric_unit' => $unit,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Scope to get metrics by name
     */
    public function scopeByMetricName($query, string $name)
    {
        return $query->where('metric_name', $name);
    }

    /**
     * Scope to get metrics for specific audit
     */
    public function scopeForAudit($query, string $auditId)
    {
        return $query->where('pdf_extraction_audit_id', $auditId);
    }

    /**
     * Scope to get metrics within time range
     */
    public function scopeWithinTimeRange($query, \DateTimeInterface $start, \DateTimeInterface $end)
    {
        return $query->whereBetween('recorded_at', [$start, $end]);
    }
}