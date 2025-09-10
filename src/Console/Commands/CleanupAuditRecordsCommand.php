<?php

namespace Shakewellagency\LaravelPdfViewer\Console\Commands;

use Illuminate\Console\Command;
use Shakewellagency\LaravelPdfViewer\Services\ExtractionAuditService;

class CleanupAuditRecordsCommand extends Command
{
    protected $signature = 'pdf-viewer:cleanup-audit-records 
                           {--days= : Override default retention days}
                           {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up old PDF extraction audit records based on retention policy';

    public function handle(ExtractionAuditService $auditService): int
    {
        $this->info('Starting PDF extraction audit cleanup...');
        
        $daysOverride = $this->option('days');
        $isDryRun = $this->option('dry-run');
        
        try {
            if ($daysOverride) {
                // Temporarily override config for this command
                config(['pdf-viewer.compliance.retention_policy' => (int) $daysOverride]);
                $this->info("Using custom retention period: {$daysOverride} days");
            }
            
            if ($isDryRun) {
                $this->info('DRY RUN MODE - No records will be deleted');
                
                $retentionDays = config('pdf-viewer.compliance.retention_policy', 2555);
                $cutoffDate = now()->subDays($retentionDays);
                
                $countToDelete = \Shakewellagency\LaravelPdfViewer\Models\PdfExtractionAudit::where('created_at', '<', $cutoffDate)->count();
                
                $this->info("Would delete {$countToDelete} audit records older than {$cutoffDate->format('Y-m-d')}");
                
                return 0;
            }
            
            $deletedCount = $auditService->cleanupAuditRecords();
            
            $this->info("Successfully deleted {$deletedCount} old audit records");
            
            if ($deletedCount > 0) {
                $this->info('Audit cleanup completed successfully');
            } else {
                $this->info('No old audit records found for cleanup');
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Audit cleanup failed: ' . $e->getMessage());
            return 1;
        }
    }
}