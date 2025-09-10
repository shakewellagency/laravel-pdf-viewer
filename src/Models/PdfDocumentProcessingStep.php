<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PdfDocumentProcessingStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdf_document_id',
        'step_name',
        'status',
        'total_items',
        'completed_items',
        'failed_items',
        'progress_percentage',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'completed_items' => 'integer',
        'failed_items' => 'integer',
        'progress_percentage' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the document that owns this processing step
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    /**
     * Calculate and update progress percentage
     */
    public function updateProgress(): void
    {
        if ($this->total_items > 0) {
            $this->progress_percentage = ($this->completed_items / $this->total_items) * 100;
            $this->save();
        }
    }

    /**
     * Mark step as started
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark step as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress_percentage' => 100.00,
        ]);
    }

    /**
     * Mark step as failed
     */
    public function markAsFailed(string $errorMessage = null): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Increment completed items and update progress
     */
    public function incrementCompleted(): void
    {
        $this->increment('completed_items');
        $this->updateProgress();
    }

    /**
     * Increment failed items and update progress
     */
    public function incrementFailed(): void
    {
        $this->increment('failed_items');
        $this->updateProgress();
    }

    /**
     * Scope to get steps by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get steps for specific document
     */
    public function scopeForDocument($query, string $documentId)
    {
        return $query->where('pdf_document_id', $documentId);
    }

    /**
     * Scope to get steps by name
     */
    public function scopeByStepName($query, string $stepName)
    {
        return $query->where('step_name', $stepName);
    }
}