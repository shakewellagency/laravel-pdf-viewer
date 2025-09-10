<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfAuditPage extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_extraction_audit_id',
        'page_number',
        'status',
        'checksum',
        'file_size',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the audit that owns this page record
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionAudit::class, 'pdf_extraction_audit_id');
    }

    /**
     * Mark page as started
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark page as completed
     */
    public function markAsCompleted(string $checksum = null, int $fileSize = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'checksum' => $checksum,
            'file_size' => $fileSize,
        ]);
    }

    /**
     * Mark page as failed
     */
    public function markAsFailed(string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Scope to get pages by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get pages for specific audit
     */
    public function scopeForAudit($query, string $auditId)
    {
        return $query->where('pdf_extraction_audit_id', $auditId);
    }
}