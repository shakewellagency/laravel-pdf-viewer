<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfAuditWarning extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_extraction_audit_id',
        'warning_type',
        'warning_code',
        'warning_message',
        'severity',
        'page_number',
        'resolved',
        'resolution_notes',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'resolved' => 'boolean',
    ];

    /**
     * Get the audit that owns this warning
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionAudit::class, 'pdf_extraction_audit_id');
    }

    /**
     * Mark warning as resolved
     */
    public function markAsResolved(string $resolutionNotes = null): void
    {
        $this->update([
            'resolved' => true,
            'resolution_notes' => $resolutionNotes,
        ]);
    }

    /**
     * Mark warning as unresolved
     */
    public function markAsUnresolved(): void
    {
        $this->update([
            'resolved' => false,
            'resolution_notes' => null,
        ]);
    }

    /**
     * Create a new warning
     */
    public static function createWarning(
        string $auditId,
        string $type,
        string $code,
        string $message,
        string $severity = 'medium',
        int $pageNumber = null
    ): self {
        return self::create([
            'pdf_extraction_audit_id' => $auditId,
            'warning_type' => $type,
            'warning_code' => $code,
            'warning_message' => $message,
            'severity' => $severity,
            'page_number' => $pageNumber,
        ]);
    }

    /**
     * Scope to get warnings by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('warning_type', $type);
    }

    /**
     * Scope to get warnings by severity
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope to get unresolved warnings
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    /**
     * Scope to get resolved warnings
     */
    public function scopeResolved($query)
    {
        return $query->where('resolved', true);
    }

    /**
     * Scope to get warnings for specific audit
     */
    public function scopeForAudit($query, string $auditId)
    {
        return $query->where('pdf_extraction_audit_id', $auditId);
    }

    /**
     * Scope to get warnings for specific page
     */
    public function scopeForPage($query, int $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }

    /**
     * Scope to get critical warnings
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope to get high severity warnings
     */
    public function scopeHigh($query)
    {
        return $query->where('severity', 'high');
    }
}