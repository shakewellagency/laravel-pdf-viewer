<?php

namespace Shakewellagency\LaravelPdfViewer\Console\Commands;

use Illuminate\Console\Command;
use Shakewellagency\LaravelPdfViewer\Services\MonitoringService;

class MonitorSystemHealthCommand extends Command
{
    protected $signature = 'pdf-viewer:monitor-health 
                           {--json : Output results in JSON format}
                           {--alerts-only : Show only active alerts}';

    protected $description = 'Monitor PDF viewer system health and performance';

    public function handle(MonitoringService $monitoringService): int
    {
        try {
            $healthMetrics = $monitoringService->getSystemHealthMetrics();
            
            if ($this->option('alerts-only')) {
                $this->displayAlerts($healthMetrics['recommendations'] ?? []);
                return 0;
            }
            
            if ($this->option('json')) {
                $this->line(json_encode($healthMetrics, JSON_PRETTY_PRINT));
                return 0;
            }
            
            $this->displayHealthSummary($healthMetrics);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Health monitoring failed: ' . $e->getMessage());
            return 1;
        }
    }
    
    protected function displayHealthSummary(array $metrics): void
    {
        $this->info('=== PDF Viewer System Health Report ===');
        $this->newLine();
        
        // Processing metrics
        $processing = $metrics['processing'];
        $this->info('📄 Processing Status:');
        $this->line("  Total Documents: {$processing['total_documents']}");
        $this->line("  Completed: {$processing['completed_documents']}");
        $this->line("  Processing: {$processing['processing_documents']}");
        $this->line("  Failed: {$processing['failed_documents']}");
        $this->newLine();
        
        // Performance metrics
        if (isset($metrics['performance']['no_recent_data'])) {
            $this->warn('⚠️  No recent performance data available');
        } else {
            $performance = $metrics['performance'];
            $this->info('⚡ Performance (24h):');
            $this->line("  Total Extractions: {$performance['total_extractions']}");
            $this->line("  Average Duration: " . round($performance['average_duration'] ?? 0, 2) . 's');
            $this->line("  Average Success Rate: " . round($performance['average_success_rate'] ?? 0, 2) . '%');
            
            if ($performance['slow_extractions'] > 0) {
                $this->warn("  Slow Extractions: {$performance['slow_extractions']}");
            }
        }
        $this->newLine();
        
        // Error metrics
        $errors = $metrics['errors'];
        $this->info('🚨 Error Status (24h):');
        $this->line("  Total Errors: {$errors['total_errors']}");
        $this->line("  Error Rate: {$errors['error_rate']}%");
        
        if (!empty($errors['error_types'])) {
            $this->line('  Error Types:');
            foreach ($errors['error_types'] as $type => $count) {
                $this->line("    - {$type}: {$count}");
            }
        }
        $this->newLine();
        
        // Storage health
        $storage = $metrics['storage'];
        $storageStatus = $storage['storage_health']['status'] ?? 'unknown';
        $statusIcon = $storageStatus === 'healthy' ? '✅' : '❌';
        $this->info("💾 Storage Health: {$statusIcon} {$storageStatus}");
        $this->line("  Driver: {$storage['storage_driver']}");
        $this->line("  Extracted Pages: {$storage['extracted_pages_count']}");
        $this->line("  Thumbnails: {$storage['thumbnails_count']}");
        $this->newLine();
        
        // Recommendations
        $recommendations = $metrics['recommendations'];
        if (!empty($recommendations)) {
            $this->warn('💡 Recommendations:');
            foreach ($recommendations as $rec) {
                $priority = strtoupper($rec['priority']);
                $this->line("  [{$priority}] {$rec['message']}");
            }
        } else {
            $this->info('✅ No issues detected - system operating normally');
        }
    }
    
    protected function displayAlerts(array $recommendations): void
    {
        if (empty($recommendations)) {
            $this->info('✅ No active alerts');
            return;
        }
        
        $this->warn('🚨 Active Alerts:');
        
        foreach ($recommendations as $rec) {
            $priority = strtoupper($rec['priority']);
            $icon = $rec['priority'] === 'critical' ? '🔴' : ($rec['priority'] === 'high' ? '🟠' : '🟡');
            
            $this->line("{$icon} [{$priority}] {$rec['message']}");
        }
    }
}