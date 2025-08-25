<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfExtractionAudit;

class MonitoringService
{
    /**
     * Monitor system health and performance
     */
    public function getSystemHealthMetrics(): array
    {
        $metrics = [
            'timestamp' => now()->toISOString(),
            'processing' => $this->getProcessingMetrics(),
            'storage' => $this->getStorageMetrics(),
            'performance' => $this->getPerformanceMetrics(),
            'errors' => $this->getErrorMetrics(),
            'compliance' => $this->getComplianceMetrics(),
            'recommendations' => [],
        ];
        
        // Generate health recommendations
        $metrics['recommendations'] = $this->generateHealthRecommendations($metrics);
        
        return $metrics;
    }
    
    /**
     * Get processing-related metrics
     */
    protected function getProcessingMetrics(): array
    {
        return [
            'total_documents' => PdfDocument::count(),
            'pending_documents' => PdfDocument::where('status', 'pending')->count(),
            'processing_documents' => PdfDocument::where('status', 'processing')->count(),
            'completed_documents' => PdfDocument::where('status', 'completed')->count(),
            'failed_documents' => PdfDocument::where('status', 'failed')->count(),
            'total_pages' => PdfDocumentPage::count(),
            'pending_pages' => PdfDocumentPage::where('status', 'pending')->count(),
            'processing_pages' => PdfDocumentPage::where('status', 'processing')->count(),
            'completed_pages' => PdfDocumentPage::where('status', 'completed')->count(),
            'failed_pages' => PdfDocumentPage::where('status', 'failed')->count(),
            'average_pages_per_document' => PdfDocument::avg('page_count') ?? 0,
        ];
    }
    
    /**
     * Get storage-related metrics
     */
    protected function getStorageMetrics(): array
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
            
            return [
                'storage_driver' => config('pdf-viewer.storage.disk'),
                'total_documents_size' => PdfDocument::sum('file_size') ?? 0,
                'extracted_pages_count' => PdfDocumentPage::whereNotNull('page_file_path')->count(),
                'thumbnails_count' => PdfDocumentPage::whereNotNull('thumbnail_path')->count(),
                'storage_health' => $this->checkStorageHealth($disk),
            ];
        } catch (\Exception $e) {
            Log::warning('Storage metrics collection failed', ['error' => $e->getMessage()]);
            
            return [
                'storage_driver' => config('pdf-viewer.storage.disk'),
                'error' => 'metrics_collection_failed',
            ];
        }
    }
    
    /**
     * Get performance-related metrics
     */
    protected function getPerformanceMetrics(): array
    {
        // Get recent extraction audits for performance analysis
        $recentAudits = PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))
            ->where('status', 'completed')
            ->get();
        
        $performanceData = $recentAudits->map(function ($audit) {
            return $audit->performance_metrics;
        })->filter()->values();
        
        if ($performanceData->isEmpty()) {
            return [
                'no_recent_data' => true,
                'period' => '24_hours',
            ];
        }
        
        $durations = $performanceData->pluck('duration_seconds')->filter();
        $successRates = $recentAudits->pluck('performance_metrics.success_rate')->filter();
        
        return [
            'period' => '24_hours',
            'total_extractions' => $recentAudits->count(),
            'average_duration' => $durations->avg(),
            'min_duration' => $durations->min(),
            'max_duration' => $durations->max(),
            'average_success_rate' => $successRates->avg(),
            'slow_extractions' => $durations->filter(fn($d) => $d > 30)->count(),
            'failed_extractions' => PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))
                ->where('status', 'failed')->count(),
        ];
    }
    
    /**
     * Get error-related metrics
     */
    protected function getErrorMetrics(): array
    {
        $recentErrors = PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))
            ->where('status', 'failed')
            ->get();
        
        $errorTypes = $recentErrors->groupBy('failure_reason')
            ->map(fn($group) => $group->count());
        
        return [
            'period' => '24_hours',
            'total_errors' => $recentErrors->count(),
            'error_types' => $errorTypes->toArray(),
            'error_rate' => $this->calculateErrorRate($recentErrors->count()),
            'critical_errors' => $this->identifyCriticalErrors($recentErrors),
        ];
    }
    
    /**
     * Get compliance-related metrics
     */
    protected function getComplianceMetrics(): array
    {
        $auditCount = PdfExtractionAudit::count();
        $recentAudits = PdfExtractionAudit::where('created_at', '>=', now()->subDays(30))->count();
        
        return [
            'total_audit_records' => $auditCount,
            'recent_audit_records' => $recentAudits,
            'compliance_flags_active' => config('pdf-viewer.compliance.compliance_flags'),
            'audit_trail_enabled' => config('pdf-viewer.compliance.audit_trail', true),
            'retention_policy_days' => config('pdf-viewer.compliance.retention_policy', 2555),
            'user_action_logging' => config('pdf-viewer.compliance.log_user_actions', true),
        ];
    }
    
    /**
     * Check storage health
     */
    protected function checkStorageHealth($disk): array
    {
        try {
            // Test basic operations
            $testPath = 'health-check-' . uniqid() . '.txt';
            $testContent = 'health check';
            
            // Test write
            $disk->put($testPath, $testContent);
            
            // Test read
            $readContent = $disk->get($testPath);
            
            // Test delete
            $disk->delete($testPath);
            
            return [
                'status' => 'healthy',
                'operations_tested' => ['write', 'read', 'delete'],
                'test_successful' => $readContent === $testContent,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Calculate error rate
     */
    protected function calculateErrorRate(int $errorCount): float
    {
        $totalOperations = PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))->count();
        
        if ($totalOperations === 0) {
            return 0.0;
        }
        
        return round(($errorCount / $totalOperations) * 100, 2);
    }
    
    /**
     * Identify critical errors that need immediate attention
     */
    protected function identifyCriticalErrors($recentErrors): array
    {
        $criticalErrors = [];
        
        foreach ($recentErrors as $error) {
            $reason = $error->failure_reason;
            
            // Define critical error patterns
            $criticalPatterns = [
                'storage' => 'Storage system failure',
                'memory' => 'Memory exhaustion',
                'timeout' => 'Processing timeout',
                'encryption' => 'Encryption handling failure',
                'security' => 'Security violation',
            ];
            
            foreach ($criticalPatterns as $pattern => $description) {
                if (str_contains(strtolower($reason), $pattern)) {
                    $criticalErrors[] = [
                        'type' => $pattern,
                        'description' => $description,
                        'count' => $recentErrors->where('failure_reason', $reason)->count(),
                        'last_occurrence' => $error->created_at->toISOString(),
                    ];
                }
            }
        }
        
        return array_unique($criticalErrors, SORT_REGULAR);
    }
    
    /**
     * Generate health recommendations based on metrics
     */
    protected function generateHealthRecommendations(array $metrics): array
    {
        $recommendations = [];
        
        // Processing recommendations
        $processing = $metrics['processing'];
        if ($processing['failed_documents'] > $processing['total_documents'] * 0.1) {
            $recommendations[] = [
                'type' => 'processing',
                'priority' => 'high',
                'message' => 'High document failure rate detected - investigate extraction issues',
            ];
        }
        
        // Performance recommendations
        $performance = $metrics['performance'];
        if (isset($performance['slow_extractions']) && $performance['slow_extractions'] > 0) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'message' => 'Slow extractions detected - consider performance optimization',
            ];
        }
        
        // Error rate recommendations
        $errors = $metrics['errors'];
        if ($errors['error_rate'] > 5) { // More than 5% error rate
            $recommendations[] = [
                'type' => 'reliability',
                'priority' => 'high',
                'message' => 'High error rate - investigate system stability',
            ];
        }
        
        // Storage recommendations
        $storage = $metrics['storage'];
        if (isset($storage['storage_health']['status']) && $storage['storage_health']['status'] !== 'healthy') {
            $recommendations[] = [
                'type' => 'storage',
                'priority' => 'critical',
                'message' => 'Storage system issues detected - immediate attention required',
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Implement intelligent retry logic with exponential backoff
     */
    public function shouldRetryExtraction(PdfDocument $document, int $pageNumber, array $errorContext): array
    {
        $retryDecision = [
            'should_retry' => false,
            'retry_strategy' => 'none',
            'wait_seconds' => 0,
            'max_attempts' => config('pdf-viewer.jobs.page_extraction.tries', 2),
            'reason' => '',
        ];
        
        $currentAttempt = $errorContext['attempt'] ?? 1;
        $errorType = $this->classifyError($errorContext['error'] ?? '');
        
        // Check if we've exceeded max attempts
        if ($currentAttempt >= $retryDecision['max_attempts']) {
            $retryDecision['reason'] = 'max_attempts_exceeded';
            return $retryDecision;
        }
        
        // Determine retry strategy based on error type
        switch ($errorType) {
            case 'temporary':
                $retryDecision['should_retry'] = true;
                $retryDecision['retry_strategy'] = 'exponential_backoff';
                $retryDecision['wait_seconds'] = min(pow(2, $currentAttempt) * 10, 300); // Max 5 minutes
                $retryDecision['reason'] = 'temporary_error_detected';
                break;
                
            case 'resource':
                $retryDecision['should_retry'] = true;
                $retryDecision['retry_strategy'] = 'resource_optimization';
                $retryDecision['wait_seconds'] = 60; // 1 minute for resource recovery
                $retryDecision['reason'] = 'resource_contention';
                break;
                
            case 'network':
                $retryDecision['should_retry'] = true;
                $retryDecision['retry_strategy'] = 'network_retry';
                $retryDecision['wait_seconds'] = 30; // 30 seconds for network recovery
                $retryDecision['reason'] = 'network_issue';
                break;
                
            case 'permanent':
                $retryDecision['should_retry'] = false;
                $retryDecision['reason'] = 'permanent_error_no_retry';
                break;
                
            default:
                // Unknown error - try once more with caution
                if ($currentAttempt === 1) {
                    $retryDecision['should_retry'] = true;
                    $retryDecision['retry_strategy'] = 'cautious_retry';
                    $retryDecision['wait_seconds'] = 120; // 2 minutes
                    $retryDecision['reason'] = 'unknown_error_single_retry';
                }
                break;
        }
        
        return $retryDecision;
    }
    
    /**
     * Classify error type for retry decision
     */
    protected function classifyError(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);
        
        // Temporary errors that might resolve with retry
        $temporaryPatterns = [
            'timeout',
            'connection',
            'network',
            'temporarily unavailable',
            'try again',
        ];
        
        foreach ($temporaryPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return 'temporary';
            }
        }
        
        // Resource-related errors
        $resourcePatterns = [
            'memory',
            'disk space',
            'resource',
            'busy',
            'locked',
        ];
        
        foreach ($resourcePatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return 'resource';
            }
        }
        
        // Network-related errors
        $networkPatterns = [
            'curl',
            'http',
            'ssl',
            'certificate',
            'dns',
        ];
        
        foreach ($networkPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return 'network';
            }
        }
        
        // Permanent errors that won't resolve with retry
        $permanentPatterns = [
            'file not found',
            'permission denied',
            'invalid',
            'corrupt',
            'unsupported',
            'malformed',
        ];
        
        foreach ($permanentPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return 'permanent';
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Monitor document processing progress
     */
    public function monitorDocumentProgress(PdfDocument $document): array
    {
        $progressData = [
            'document_hash' => $document->hash,
            'status' => $document->status,
            'progress_percentage' => $this->calculateProgressPercentage($document),
            'pages_status' => $this->getPageStatusBreakdown($document),
            'estimated_completion' => $this->estimateCompletionTime($document),
            'issues' => $this->detectProcessingIssues($document),
            'performance_indicators' => $this->getPerformanceIndicators($document),
        ];
        
        // Cache progress data for dashboard
        $this->cacheProgressData($document->hash, $progressData);
        
        return $progressData;
    }
    
    /**
     * Calculate processing progress percentage
     */
    protected function calculateProgressPercentage(PdfDocument $document): float
    {
        if ($document->page_count === 0) {
            return 0.0;
        }
        
        $completedPages = $document->pages()
            ->whereIn('status', ['completed', 'failed'])
            ->count();
        
        return round(($completedPages / $document->page_count) * 100, 2);
    }
    
    /**
     * Get page status breakdown
     */
    protected function getPageStatusBreakdown(PdfDocument $document): array
    {
        return [
            'pending' => $document->pages()->where('status', 'pending')->count(),
            'processing' => $document->pages()->where('status', 'processing')->count(),
            'completed' => $document->pages()->where('status', 'completed')->count(),
            'failed' => $document->pages()->where('status', 'failed')->count(),
        ];
    }
    
    /**
     * Estimate completion time for document processing
     */
    protected function estimateCompletionTime(PdfDocument $document): ?string
    {
        $statusBreakdown = $this->getPageStatusBreakdown($document);
        $remainingPages = $statusBreakdown['pending'] + $statusBreakdown['processing'];
        
        if ($remainingPages === 0) {
            return null; // Already completed
        }
        
        // Get average processing time from recent successful extractions
        $avgProcessingTime = $this->getAverageProcessingTime();
        
        $estimatedSeconds = $remainingPages * $avgProcessingTime;
        $estimatedCompletion = now()->addSeconds($estimatedSeconds);
        
        return $estimatedCompletion->toISOString();
    }
    
    /**
     * Get average processing time per page
     */
    protected function getAverageProcessingTime(): float
    {
        $recentAudits = PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))
            ->where('status', 'completed')
            ->get();
        
        if ($recentAudits->isEmpty()) {
            return 5.0; // Default 5 seconds per page
        }
        
        $durations = $recentAudits->map(function ($audit) {
            $pages = count($audit->pages_completed ?? []);
            $duration = $audit->getDurationInSeconds();
            
            return $pages > 0 && $duration > 0 ? $duration / $pages : null;
        })->filter();
        
        return $durations->avg() ?? 5.0;
    }
    
    /**
     * Detect processing issues
     */
    protected function detectProcessingIssues(PdfDocument $document): array
    {
        $issues = [];
        
        // Check for stuck processing
        $stuckPages = $document->pages()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->count();
        
        if ($stuckPages > 0) {
            $issues[] = [
                'type' => 'stuck_processing',
                'severity' => 'medium',
                'message' => "{$stuckPages} pages appear stuck in processing state",
            ];
        }
        
        // Check for high failure rate
        $totalPages = $document->page_count;
        $failedPages = $document->pages()->where('status', 'failed')->count();
        
        if ($totalPages > 0 && ($failedPages / $totalPages) > 0.2) {
            $issues[] = [
                'type' => 'high_failure_rate',
                'severity' => 'high',
                'message' => "High failure rate: {$failedPages}/{$totalPages} pages failed",
            ];
        }
        
        return $issues;
    }
    
    /**
     * Get performance indicators for document
     */
    protected function getPerformanceIndicators(PdfDocument $document): array
    {
        $indicators = [];
        
        // Processing speed indicator
        $processingStarted = $document->processing_started_at;
        if ($processingStarted) {
            $duration = now()->diffInSeconds($processingStarted);
            $completedPages = $document->pages()->where('status', 'completed')->count();
            
            if ($completedPages > 0) {
                $pagesPerSecond = $completedPages / $duration;
                $indicators['pages_per_second'] = round($pagesPerSecond, 3);
                
                if ($pagesPerSecond < 0.1) { // Less than 1 page per 10 seconds
                    $indicators['performance_warning'] = 'slow_processing_detected';
                }
            }
        }
        
        return $indicators;
    }
    
    /**
     * Cache progress data for quick access
     */
    protected function cacheProgressData(string $documentHash, array $progressData): void
    {
        $cacheKey = "pdf_viewer:progress:{$documentHash}";
        $ttl = 60; // Cache for 1 minute
        
        Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->put($cacheKey, $progressData, $ttl);
    }
    
    /**
     * Set up automated monitoring alerts
     */
    public function setupMonitoringAlerts(): void
    {
        // This would integrate with your monitoring system
        // For now, we'll log the setup
        Log::info('PDF Viewer monitoring alerts configured', [
            'error_rate_threshold' => 5, // 5%
            'slow_processing_threshold' => 30, // 30 seconds per page
            'storage_health_check_interval' => 300, // 5 minutes
            'performance_monitoring_enabled' => config('pdf-viewer.page_extraction.performance_monitoring', true),
        ]);
    }
    
    /**
     * Generate monitoring dashboard data
     */
    public function getDashboardData(): array
    {
        return [
            'overview' => $this->getSystemHealthMetrics(),
            'recent_activity' => $this->getRecentActivity(),
            'performance_trends' => $this->getPerformanceTrends(),
            'alerts' => $this->getActiveAlerts(),
        ];
    }
    
    /**
     * Get recent activity summary
     */
    protected function getRecentActivity(): array
    {
        $recentDocuments = PdfDocument::where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        $recentAudits = PdfExtractionAudit::where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        
        return [
            'recent_documents' => $recentDocuments->map(fn($doc) => [
                'hash' => $doc->hash,
                'title' => $doc->title,
                'status' => $doc->status,
                'page_count' => $doc->page_count,
                'created_at' => $doc->created_at->toISOString(),
            ])->toArray(),
            'recent_extractions' => $recentAudits->map(fn($audit) => [
                'id' => $audit->id,
                'document_hash' => $audit->document->hash,
                'operation_type' => $audit->operation_type,
                'status' => $audit->status,
                'success_rate' => $audit->getSuccessRate(),
                'created_at' => $audit->created_at->toISOString(),
            ])->toArray(),
        ];
    }
    
    /**
     * Get performance trends
     */
    protected function getPerformanceTrends(): array
    {
        // Get performance data for the last 7 days
        $trends = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $nextDate = $date->copy()->addDay();
            
            $dailyAudits = PdfExtractionAudit::whereBetween('created_at', [$date, $nextDate])
                ->where('status', 'completed')
                ->get();
            
            $avgDuration = $dailyAudits->avg(fn($audit) => $audit->getDurationInSeconds()) ?? 0;
            $avgSuccessRate = $dailyAudits->avg(fn($audit) => $audit->getSuccessRate()) ?? 0;
            
            $trends[] = [
                'date' => $date->format('Y-m-d'),
                'extractions_count' => $dailyAudits->count(),
                'average_duration' => round($avgDuration, 2),
                'average_success_rate' => round($avgSuccessRate, 2),
            ];
        }
        
        return $trends;
    }
    
    /**
     * Get active monitoring alerts
     */
    protected function getActiveAlerts(): array
    {
        $alerts = [];
        
        // Check for recent critical errors
        $criticalErrors = PdfExtractionAudit::where('created_at', '>=', now()->subHours(1))
            ->where('status', 'failed')
            ->count();
        
        if ($criticalErrors > 5) {
            $alerts[] = [
                'type' => 'critical_errors',
                'severity' => 'high',
                'message' => "{$criticalErrors} critical errors in the last hour",
                'timestamp' => now()->toISOString(),
            ];
        }
        
        // Check for storage issues
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
            $healthCheck = $this->checkStorageHealth($disk);
            
            if ($healthCheck['status'] !== 'healthy') {
                $alerts[] = [
                    'type' => 'storage_health',
                    'severity' => 'critical',
                    'message' => 'Storage system health check failed',
                    'details' => $healthCheck,
                    'timestamp' => now()->toISOString(),
                ];
            }
        } catch (\Exception $e) {
            $alerts[] = [
                'type' => 'storage_connection',
                'severity' => 'critical',
                'message' => 'Cannot connect to storage system',
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
        
        return $alerts;
    }
}