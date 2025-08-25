<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfAuditComplianceFlag extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_extraction_audit_id',
        'compliance_type',
        'is_compliant',
        'flag_reason',
        'remediation_action',
    ];

    protected $casts = [
        'is_compliant' => 'boolean',
    ];

    /**
     * Get the audit that owns this compliance flag
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PdfExtractionAudit::class, 'pdf_extraction_audit_id');
    }

    /**
     * Mark as non-compliant with reason
     */
    public function markAsNonCompliant(string $reason, string $remediationAction = null): void
    {
        $this->update([
            'is_compliant' => false,
            'flag_reason' => $reason,
            'remediation_action' => $remediationAction,
        ]);
    }

    /**
     * Mark as compliant
     */
    public function markAsCompliant(string $remediationAction = null): void
    {
        $this->update([
            'is_compliant' => true,
            'flag_reason' => null,
            'remediation_action' => $remediationAction,
        ]);
    }

    /**
     * Scope to get flags by compliance type
     */
    public function scopeByComplianceType($query, string $type)
    {
        return $query->where('compliance_type', $type);
    }

    /**
     * Scope to get non-compliant flags
     */
    public function scopeNonCompliant($query)
    {
        return $query->where('is_compliant', false);
    }

    /**
     * Scope to get compliant flags
     */
    public function scopeCompliant($query)
    {
        return $query->where('is_compliant', true);
    }

    /**
     * Scope to get flags for specific audit
     */
    public function scopeForAudit($query, string $auditId)
    {
        return $query->where('pdf_extraction_audit_id', $auditId);
    }
}