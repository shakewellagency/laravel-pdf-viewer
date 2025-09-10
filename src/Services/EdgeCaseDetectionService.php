<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class EdgeCaseDetectionService
{
    /**
     * Analyze PDF for edge cases and special handling requirements
     */
    public function analyzeDocumentEdgeCases(PdfDocument $document): array
    {
        $sourceFile = $this->getSourceFilePath($document);
        
        $analysis = [
            'is_portfolio_pdf' => false,
            'has_form_calculations' => false,
            'has_spread_layouts' => false,
            'has_embedded_files' => false,
            'has_color_separations' => false,
            'has_multimedia_elements' => false,
            'extraction_strategy' => 'standard',
            'warnings' => [],
            'recommendations' => [],
        ];

        try {
            // Read PDF content for analysis
            $content = file_get_contents($sourceFile);
            
            // Detect portfolio PDFs
            if (config('pdf-viewer.page_extraction.detect_portfolio_pdfs', true)) {
                $analysis['is_portfolio_pdf'] = $this->detectPortfolioPdf($content, $document);
            }
            
            // Detect form calculations
            if (config('pdf-viewer.page_extraction.handle_form_calculations') !== 'remove') {
                $analysis['has_form_calculations'] = $this->detectFormCalculations($content);
            }
            
            // Detect spread layouts
            if (config('pdf-viewer.page_extraction.detect_spread_layouts', true)) {
                $analysis['has_spread_layouts'] = $this->detectSpreadLayouts($content, $document);
            }
            
            // Detect embedded files
            if (config('pdf-viewer.page_extraction.preserve_embedded_files') !== 'remove') {
                $analysis['has_embedded_files'] = $this->detectEmbeddedFiles($content);
            }
            
            // Detect color separations
            if (config('pdf-viewer.page_extraction.handle_color_separations') !== 'flatten') {
                $analysis['has_color_separations'] = $this->detectColorSeparations($content);
            }
            
            // Detect multimedia elements
            $analysis['has_multimedia_elements'] = $this->detectMultimediaElements($content);
            
            // Determine extraction strategy based on findings
            $analysis['extraction_strategy'] = $this->determineExtractionStrategy($analysis);
            
            // Generate warnings and recommendations
            $this->generateWarningsAndRecommendations($analysis);
            
        } catch (\Exception $e) {
            Log::warning('Edge case analysis failed', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
            
            $analysis['warnings'][] = 'Edge case analysis failed - using standard extraction';
            $analysis['extraction_strategy'] = 'standard';
        }
        
        return $analysis;
    }
    
    /**
     * Detect portfolio PDFs (PDFs containing other PDFs)
     */
    protected function detectPortfolioPdf(string $content, PdfDocument $document): bool
    {
        // Check for PDF portfolio markers
        $portfolioIndicators = [
            '/Collection',
            '/PDF/Portfolio',
            '/Navigator',
            '/EmbeddedFiles'
        ];
        
        foreach ($portfolioIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                Log::info('Portfolio PDF detected', [
                    'document_hash' => $document->hash,
                    'indicator' => $indicator,
                    'page_count' => $document->page_count,
                ]);
                return true;
            }
        }
        
        // Additional heuristic: check if document has an unusually high embedded file count
        $embeddedFileCount = substr_count($content, '/EmbeddedFile');
        if ($embeddedFileCount > 5) {
            Log::info('Potential portfolio PDF detected via embedded file count', [
                'document_hash' => $document->hash,
                'embedded_file_count' => $embeddedFileCount,
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect form calculations and interactive elements
     */
    protected function detectFormCalculations(string $content): bool
    {
        $calculationIndicators = [
            '/Calculate',
            '/Calculation',
            '/JS',
            '/JavaScript',
            'AFSimple_Calculate',
            'AFPercent_Calculate',
            'AFDate_Keystroke',
        ];
        
        foreach ($calculationIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect spread layouts (facing pages designed together)
     */
    protected function detectSpreadLayouts(string $content, PdfDocument $document): bool
    {
        // Check for spread layout indicators
        $spreadIndicators = [
            '/TrimBox',
            '/BleedBox',
            '/Spread',
            '/Facing',
        ];
        
        foreach ($spreadIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        // Heuristic: even page count with specific dimensions might indicate spreads
        if ($document->page_count % 2 === 0 && $document->page_count > 2) {
            // This is a weak indicator, so we log but don't definitively mark as spread
            Log::debug('Potential spread layout based on even page count', [
                'document_hash' => $document->hash,
                'page_count' => $document->page_count,
            ]);
        }
        
        return false;
    }
    
    /**
     * Detect embedded files
     */
    protected function detectEmbeddedFiles(string $content): bool
    {
        $embeddedIndicators = [
            '/EmbeddedFile',
            '/FileAttachment',
            '/Attachment',
        ];
        
        foreach ($embeddedIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect color separations and print-specific features
     */
    protected function detectColorSeparations(string $content): bool
    {
        $colorSeparationIndicators = [
            '/Separation',
            '/DeviceN',
            '/Spot',
            '/ColorSpace',
            '/OutputIntent',
        ];
        
        foreach ($colorSeparationIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect multimedia elements
     */
    protected function detectMultimediaElements(string $content): bool
    {
        $multimediaIndicators = [
            '/Movie',
            '/Sound',
            '/Screen',
            '/RichMedia',
            '/3D',
            '/Video',
            '/Audio',
        ];
        
        foreach ($multimediaIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Determine extraction strategy based on detected edge cases
     */
    protected function determineExtractionStrategy(array $analysis): string
    {
        // Portfolio PDFs need special handling
        if ($analysis['is_portfolio_pdf']) {
            return 'portfolio_extraction';
        }
        
        // PDFs with form calculations need careful handling
        if ($analysis['has_form_calculations']) {
            return 'form_aware_extraction';
        }
        
        // Spread layouts might need different page grouping
        if ($analysis['has_spread_layouts']) {
            return 'spread_aware_extraction';
        }
        
        // Multiple edge cases detected
        $edgeCaseCount = collect($analysis)->filter(function ($value, $key) {
            return is_bool($value) && $value === true && str_starts_with($key, 'has_') || str_starts_with($key, 'is_');
        })->count();
        
        if ($edgeCaseCount >= 3) {
            return 'conservative_extraction';
        }
        
        if ($edgeCaseCount >= 2) {
            return 'cautious_extraction';
        }
        
        return 'standard';
    }
    
    /**
     * Generate warnings and recommendations based on analysis
     */
    protected function generateWarningsAndRecommendations(array &$analysis): void
    {
        if ($analysis['is_portfolio_pdf']) {
            $analysis['warnings'][] = 'Portfolio PDF detected - individual pages may contain complete documents';
            $analysis['recommendations'][] = 'Consider extracting as complete sub-documents rather than individual pages';
        }
        
        if ($analysis['has_form_calculations']) {
            $analysis['warnings'][] = 'Interactive form calculations detected - extracted pages will lose functionality';
            $analysis['recommendations'][] = 'Preserve original document for interactive features';
        }
        
        if ($analysis['has_spread_layouts']) {
            $analysis['warnings'][] = 'Spread layouts detected - pages designed to be viewed together';
            $analysis['recommendations'][] = 'Consider grouping facing pages or adjusting extraction approach';
        }
        
        if ($analysis['has_embedded_files']) {
            $analysis['warnings'][] = 'Embedded files detected - may be lost in page extraction';
            $analysis['recommendations'][] = 'Extract embedded files separately if needed for compliance';
        }
        
        if ($analysis['has_color_separations']) {
            $analysis['warnings'][] = 'Color separations detected - print-specific features may be affected';
            $analysis['recommendations'][] = 'Verify color accuracy in extracted pages';
        }
        
        if ($analysis['has_multimedia_elements']) {
            $analysis['warnings'][] = 'Multimedia elements detected - will be lost in static page extraction';
            $analysis['recommendations'][] = 'Consider alternative viewing methods for interactive content';
        }
    }
    
    /**
     * Apply edge case handling to extraction context
     */
    public function applyEdgeCaseHandling(array $edgeCaseAnalysis, array &$extractionContext): void
    {
        $strategy = $edgeCaseAnalysis['extraction_strategy'];
        
        switch ($strategy) {
            case 'portfolio_extraction':
                $extractionContext['resource_strategy'] = 'duplicate_all';
                $extractionContext['preserve_embedded'] = true;
                $extractionContext['method'] = 'portfolio_aware';
                break;
                
            case 'form_aware_extraction':
                $extractionContext['preserve_form_fields'] = config('pdf-viewer.page_extraction.handle_form_fields', 'isolate');
                $extractionContext['handle_javascript'] = config('pdf-viewer.page_extraction.handle_javascript', 'remove');
                $extractionContext['method'] = 'form_aware';
                break;
                
            case 'spread_aware_extraction':
                $extractionContext['consider_spreads'] = true;
                $extractionContext['preserve_layout_context'] = true;
                $extractionContext['method'] = 'spread_aware';
                break;
                
            case 'conservative_extraction':
                $extractionContext['resource_strategy'] = 'duplicate_all';
                $extractionContext['compression'] = false;
                $extractionContext['preserve_all_metadata'] = true;
                $extractionContext['method'] = 'conservative';
                break;
                
            case 'cautious_extraction':
                $extractionContext['resource_strategy'] = 'smart_copy';
                $extractionContext['validate_output'] = true;
                $extractionContext['method'] = 'cautious';
                break;
                
            default:
                $extractionContext['method'] = 'standard';
                break;
        }
        
        // Add detected issues to context
        $extractionContext['edge_cases_detected'] = array_keys(array_filter($edgeCaseAnalysis, function ($value, $key) {
            return is_bool($value) && $value === true && (str_starts_with($key, 'has_') || str_starts_with($key, 'is_'));
        }, ARRAY_FILTER_USE_BOTH));
        
        $extractionContext['issues_detected'] = array_merge(
            $extractionContext['issues_detected'] ?? [],
            $edgeCaseAnalysis['warnings']
        );
    }
    
    /**
     * Get source file path for analysis
     */
    protected function getSourceFilePath(PdfDocument $document): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
        
        // For S3, we need to download to temp location
        if ($this->isS3Disk($disk)) {
            $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
            $tempFile = $tempDir . '/edge_analysis_' . $document->hash . '.pdf';
            
            if (!file_exists($tempFile)) {
                $content = $disk->get($document->file_path);
                file_put_contents($tempFile, $content);
            }
            
            return $tempFile;
        }
        
        // For local storage
        return storage_path('app/' . $document->file_path);
    }
    
    /**
     * Check if storage disk is S3
     */
    protected function isS3Disk($disk): bool
    {
        return method_exists($disk->getAdapter(), 'getBucket') || 
               (config('pdf-viewer.storage.disk') === 's3');
    }
    
    /**
     * Clean up temporary files used for analysis
     */
    public function cleanupAnalysisFiles(PdfDocument $document): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
        
        if ($this->isS3Disk($disk)) {
            $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
            $tempFile = $tempDir . '/edge_analysis_' . $document->hash . '.pdf';
            
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}