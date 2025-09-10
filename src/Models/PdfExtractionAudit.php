<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfExtractionAudit extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'pdf_document_id',
        'user_id',
        'operation_type',
        'pages_requested',
        'pages_completed',
        'pages_failed',
        'extraction_reason',
        'requester_ip',
        'user_agent',
        'compliance_flags',
        'pdf_metadata',
        'extraction_settings',
        'performance_metrics',
        'extraction_method',
        'original_checksum',
        'page_checksums',
        'original_file_size',
        'total_extracted_size',
        'status',
        'failure_reason',
        'warnings',
        'initiated_at',
        'completed_at',
    ];

    protected $casts = [
        'pages_requested' => 'array',
        'pages_completed' => 'array',
        'pages_failed' => 'array',
        'compliance_flags' => 'array',
        'pdf_metadata' => 'array',
        'extraction_settings' => 'array',
        'performance_metrics' => 'array',
        'page_checksums' => 'array',
        'warnings' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'original_file_size' => 'integer',
        'total_extracted_size' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Get the document this audit belongs to
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    /**
     * Get the user who initiated the extraction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }

    /**
     * Check if extraction was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'completed' && empty($this->pages_failed);
    }

    /**
     * Check if extraction had partial failures
     */
    public function hasPartialFailures(): bool
    {
        return $this->status === 'partial' || !empty($this->pages_failed);
    }

    /**
     * Get extraction duration
     */
    public function getDurationInSeconds(): ?int
    {
        if (!$this->initiated_at || !$this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->initiated_at);
    }

    /**
     * Get pages that failed extraction
     */
    public function getFailedPages(): array
    {
        return $this->pages_failed ?? [];
    }

    /**
     * Calculate extraction success rate
     */
    public function getSuccessRate(): float
    {
        $requested = count($this->pages_requested ?? []);
        $failed = count($this->pages_failed ?? []);
        
        if ($requested === 0) {
            return 0.0;
        }
        
        return round((($requested - $failed) / $requested) * 100, 2);
    }

    /**
     * Scope for successful extractions
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed extractions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for partial extractions
     */
    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    /**
     * Scope for specific operation types
     */
    public function scopeOperationType($query, string $type)
    {
        return $query->where('operation_type', $type);
    }

    /**
     * Scope for compliance auditing
     */
    public function scopeForCompliance($query, array $flags = [])
    {
        return $query->when(!empty($flags), function ($q) use ($flags) {
            foreach ($flags as $flag) {
                $q->whereJsonContains('compliance_flags', $flag);
            }
        });
    }
}