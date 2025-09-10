<?php

namespace Shakewellagency\LaravelPdfViewer\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Shakewellagency\LaravelPdfViewer\Database\Factories\PdfDocumentFactory;

class PdfDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'title',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'file_path',
        'page_count',
        'status',
        'processing_error',
        'processing_started_at',
        'processing_completed_at',
        'is_searchable',
        'created_by',
    ];

    protected $casts = [
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'is_searchable' => 'boolean',
        'file_size' => 'integer',
        'page_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'file_path', // Hide internal file path from API responses
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Shakewellagency\LaravelPdfViewer\Database\Factories\PdfDocumentFactory::new();
    }

    /**
     * Boot the model and set up event handlers
     */
    protected static function boot()
    {
        parent::boot();

        // Generate hash when creating document
        static::creating(function ($document) {
            if (!$document->hash) {
                $document->hash = static::generateHash($document->original_filename, $document->file_size);
            }
        });
    }

    /**
     * Generate secure hash for document identification
     */
    public static function generateHash(string $filename, int $fileSize): string
    {
        $salt = config('pdf-viewer.security.salt', config('app.key'));
        $data = $filename . $fileSize . time() . uniqid();
        
        return hash(config('pdf-viewer.security.hash_algorithm', 'sha256'), $data . $salt);
    }

    /**
     * Find document by hash
     */
    public static function findByHash(string $hash): ?static
    {
        return static::where('hash', $hash)->first();
    }

    /**
     * Get the route key name for model binding
     */
    public function getRouteKeyName(): string
    {
        return 'hash';
    }

    /**
     * Get document pages
     */
    public function pages(): HasMany
    {
        return $this->hasMany(PdfDocumentPage::class)->orderBy('page_number');
    }

    /**
     * Get completed pages
     */
    public function completedPages(): HasMany
    {
        return $this->pages()->where('status', 'completed');
    }

    /**
     * Get failed pages
     */
    public function failedPages(): HasMany
    {
        return $this->pages()->where('status', 'failed');
    }

    /**
     * Check if document processing is complete
     */
    public function isProcessingComplete(): bool
    {
        return $this->status === 'completed' && $this->pages()->where('status', '!=', 'completed')->count() === 0;
    }

    /**
     * Calculate processing progress percentage
     */
    public function getProcessingProgress(): float
    {
        if ($this->page_count === 0) {
            return 0;
        }

        $completedPages = $this->completedPages()->count();
        return round(($completedPages / $this->page_count) * 100, 2);
    }

    /**
     * Get human readable file size
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get file size in megabytes
     */
    public function getFileSizeInMbAttribute(): float
    {
        return round($this->file_size / 1024 / 1024, 2);
    }

    /**
     * Check if document is currently processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if document processing is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if document processing has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        return $this->getProcessingProgress();
    }

    /**
     * Get processing time duration
     */
    public function getProcessingTime(): ?CarbonInterval
    {
        if (!$this->processing_started_at) {
            return null;
        }

        $endTime = $this->processing_completed_at ?? now();
        return $this->processing_started_at->diffAsCarbonInterval($endTime);
    }

    /**
     * Scope for searchable documents
     */
    public function scopeSearchable($query)
    {
        return $query->where('is_searchable', true);
    }

    /**
     * Scope for completed documents
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for processing documents
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope for failed documents
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Get document metadata
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PdfDocumentMetadata::class);
    }

    /**
     * Get processing steps for this document
     */
    public function processingSteps(): HasMany
    {
        return $this->hasMany(PdfDocumentProcessingStep::class);
    }

    /**
     * Get cross references for this document
     */
    public function crossReferences(): HasMany
    {
        return $this->hasMany(PdfCrossReference::class, 'document_hash', 'hash');
    }

    /**
     * Get a specific metadata value by key
     */
    public function getMetadata(string $key, $default = null)
    {
        $metadata = $this->metadata()->where('key', $key)->first();
        return $metadata ? $metadata->typed_value : $default;
    }

    /**
     * Set a metadata value by key
     */
    public function setMetadata(string $key, $value): PdfDocumentMetadata
    {
        $metadata = $this->metadata()->updateOrCreate(
            ['key' => $key],
            []
        );
        
        $metadata->setTypedValue($value);
        $metadata->save();
        
        return $metadata;
    }

    /**
     * Get all metadata as associative array
     */
    public function getAllMetadata(): array
    {
        return $this->metadata->pluck('typed_value', 'key')->toArray();
    }

    /**
     * Get processing step by name
     */
    public function getProcessingStep(string $stepName): ?PdfDocumentProcessingStep
    {
        return $this->processingSteps()->where('step_name', $stepName)->first();
    }

    /**
     * Create or update a processing step
     */
    public function updateProcessingStep(string $stepName, array $data): PdfDocumentProcessingStep
    {
        return $this->processingSteps()->updateOrCreate(
            ['step_name' => $stepName],
            $data
        );
    }
}