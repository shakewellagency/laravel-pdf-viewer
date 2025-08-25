<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;

class PerformanceOptimizationService
{
    /**
     * Optimize extraction performance with chunked processing
     */
    public function optimizeExtractionPerformance(PdfDocument $document): array
    {
        $optimization = [
            'use_chunked_processing' => false,
            'chunk_size' => config('pdf-viewer.page_extraction.chunk_size', 100),
            'parallel_processing' => false,
            'memory_optimization' => false,
            'recovery_checkpoints' => [],
            'performance_strategy' => 'standard',
        ];
        
        try {
            // Determine if chunked processing is beneficial
            if (config('pdf-viewer.page_extraction.chunk_processing', true) && $document->page_count > 50) {
                $optimization['use_chunked_processing'] = true;
                $optimization['performance_strategy'] = 'chunked';
            }
            
            // Check memory requirements
            $estimatedMemoryUsage = $this->estimateMemoryUsage($document);
            $memoryLimit = $this->parseMemoryLimit(config('pdf-viewer.processing.memory_limit', '512M'));
            
            if ($estimatedMemoryUsage > $memoryLimit * 0.8) {
                $optimization['memory_optimization'] = true;
                $optimization['performance_strategy'] = 'memory_conscious';
            }
            
            // Set up recovery checkpoints for large documents
            if (config('pdf-viewer.page_extraction.enable_resume', true) && $document->page_count > 20) {
                $optimization['recovery_checkpoints'] = $this->generateRecoveryCheckpoints($document);
            }
            
            // Performance monitoring setup
            if (config('pdf-viewer.page_extraction.performance_monitoring', true)) {
                $optimization['monitor_performance'] = true;
            }
            
        } catch (\Exception $e) {
            Log::warning('Performance optimization analysis failed', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
        }
        
        return $optimization;
    }
    
    /**
     * Estimate memory usage for document processing
     */
    protected function estimateMemoryUsage(PdfDocument $document): int
    {
        // Base memory usage estimation
        $baseMemory = 50 * 1024 * 1024; // 50MB base
        
        // Add memory per page (rough estimate)
        $memoryPerPage = 2 * 1024 * 1024; // 2MB per page
        $pageMemory = $document->page_count * $memoryPerPage;
        
        // Add memory for file size (PDF content in memory)
        $fileMemory = ($document->file_size ?? 10 * 1024 * 1024) * 2; // 2x file size
        
        return $baseMemory + $pageMemory + $fileMemory;
    }
    
    /**
     * Parse memory limit string to bytes
     */
    protected function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int) $memoryLimit;
        }
    }
    
    /**
     * Generate recovery checkpoints for resumable processing
     */
    protected function generateRecoveryCheckpoints(PdfDocument $document): array
    {
        $chunkSize = config('pdf-viewer.page_extraction.chunk_size', 100);
        $checkpoints = [];
        
        for ($page = 1; $page <= $document->page_count; $page += $chunkSize) {
            $endPage = min($page + $chunkSize - 1, $document->page_count);
            
            $checkpoints[] = [
                'start_page' => $page,
                'end_page' => $endPage,
                'checkpoint_id' => "checkpoint_{$page}_{$endPage}",
                'estimated_duration' => $this->estimateChunkDuration($endPage - $page + 1),
            ];
        }
        
        return $checkpoints;
    }
    
    /**
     * Estimate processing duration for page chunk
     */
    protected function estimateChunkDuration(int $pageCount): int
    {
        // Base processing time per page (in seconds)
        $baseTimePerPage = 2; // 2 seconds per page estimate
        
        // Add overhead for chunk setup
        $chunkOverhead = 5; // 5 seconds setup time
        
        return ($pageCount * $baseTimePerPage) + $chunkOverhead;
    }
    
    /**
     * Create recovery checkpoint data
     */
    public function createRecoveryCheckpoint(PdfDocument $document, int $currentPage, array $processingState): void
    {
        if (!config('pdf-viewer.page_extraction.enable_resume', true)) {
            return;
        }
        
        $checkpointData = [
            'document_hash' => $document->hash,
            'current_page' => $currentPage,
            'processing_state' => $processingState,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
        
        $cacheKey = "pdf_viewer:recovery:{$document->hash}";
        $ttl = config('pdf-viewer.page_extraction.max_processing_time', 1800);
        
        Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->put($cacheKey, $checkpointData, $ttl);
    }
    
    /**
     * Restore from recovery checkpoint
     */
    public function restoreFromCheckpoint(string $documentHash): ?array
    {
        $cacheKey = "pdf_viewer:recovery:{$documentHash}";
        
        $checkpointData = Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->get($cacheKey);
        
        if ($checkpointData) {
            Log::info('Restored processing from checkpoint', [
                'document_hash' => $documentHash,
                'checkpoint_page' => $checkpointData['current_page'],
                'checkpoint_time' => $checkpointData['timestamp'],
            ]);
        }
        
        return $checkpointData;
    }
    
    /**
     * Clear recovery checkpoint after successful completion
     */
    public function clearRecoveryCheckpoint(string $documentHash): void
    {
        $cacheKey = "pdf_viewer:recovery:{$documentHash}";
        
        Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->forget($cacheKey);
    }
    
    /**
     * Monitor processing performance and detect bottlenecks
     */
    public function monitorProcessingPerformance(
        PdfDocument $document, 
        int $pageNumber, 
        float $startTime, 
        array $extractionContext
    ): array {
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $metrics = [
            'page_number' => $pageNumber,
            'duration_seconds' => round($duration, 3),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'extraction_method' => $extractionContext['method'] ?? 'unknown',
            'had_fallbacks' => !empty($extractionContext['fallbacks_used']),
            'performance_grade' => $this->calculatePerformanceGrade($duration, $extractionContext),
        ];
        
        // Log performance metrics
        if (config('pdf-viewer.page_extraction.performance_monitoring', true)) {
            Log::debug('Page extraction performance', [
                'document_hash' => $document->hash,
                'metrics' => $metrics,
            ]);
        }
        
        // Detect performance issues
        if ($duration > 30) { // More than 30 seconds per page
            Log::warning('Slow page extraction detected', [
                'document_hash' => $document->hash,
                'page_number' => $pageNumber,
                'duration' => $duration,
                'extraction_context' => $extractionContext,
            ]);
            
            $metrics['performance_alert'] = 'slow_extraction';
        }
        
        return $metrics;
    }
    
    /**
     * Calculate performance grade based on metrics
     */
    protected function calculatePerformanceGrade(float $duration, array $extractionContext): string
    {
        // Base grading on duration
        if ($duration < 2) {
            $grade = 'excellent';
        } elseif ($duration < 5) {
            $grade = 'good';
        } elseif ($duration < 15) {
            $grade = 'acceptable';
        } elseif ($duration < 30) {
            $grade = 'slow';
        } else {
            $grade = 'very_slow';
        }
        
        // Adjust grade based on complexity
        $complexityFactors = [
            'fallbacks_used' => !empty($extractionContext['fallbacks_used']),
            'edge_cases' => !empty($extractionContext['edge_cases_detected']),
            'cross_references' => isset($extractionContext['cross_reference_strategy']),
        ];
        
        $complexityScore = count(array_filter($complexityFactors));
        
        // Adjust grade for complexity
        if ($complexityScore > 0 && $grade === 'slow') {
            $grade = 'acceptable'; // More lenient for complex documents
        }
        
        return $grade;
    }
    
    /**
     * Optimize memory usage during processing
     */
    public function optimizeMemoryUsage(): void
    {
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Clear any temporary caches
        $this->clearTemporaryCaches();
    }
    
    /**
     * Clear temporary caches to free memory
     */
    protected function clearTemporaryCaches(): void
    {
        // Clear specific temporary cache keys
        $tempKeys = [
            'pdf_viewer:temp:*',
            'pdf_viewer:analysis:*',
        ];
        
        foreach ($tempKeys as $pattern) {
            // This would need a more sophisticated cache clearing mechanism
            // For now, just log the intent
            Log::debug('Clearing temporary cache pattern', ['pattern' => $pattern]);
        }
    }
}