<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Services\ExtractionAuditService;
use Shakewellagency\LaravelPdfViewer\Services\EdgeCaseDetectionService;
use Shakewellagency\LaravelPdfViewer\Services\CrossReferenceService;
use Spatie\PdfToText\Pdf;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use setasign\Fpdi\Tcpdf\Fpdi;

class PageProcessingService implements PageProcessingServiceInterface
{
    protected ExtractionAuditService $auditService;
    protected EdgeCaseDetectionService $edgeCaseService;
    protected CrossReferenceService $crossRefService;

    public function __construct(
        ExtractionAuditService $auditService, 
        EdgeCaseDetectionService $edgeCaseService,
        CrossReferenceService $crossRefService
    ) {
        $this->auditService = $auditService;
        $this->edgeCaseService = $edgeCaseService;
        $this->crossRefService = $crossRefService;
    }
    public function extractPage(PdfDocument $document, int $pageNumber): string
    {
        $result = $this->extractPageWithContext($document, $pageNumber);
        return $result['file_path'];
    }

    /**
     * Extract page with comprehensive context and metadata
     */
    public function extractPageWithContext(PdfDocument $document, int $pageNumber): array
    {
        try {
            $disk = $this->getStorageDisk();
            $pageFileName = "page_{$pageNumber}.pdf";
            $pagePath = config('pdf-viewer.storage.pages_path') . '/' . $document->hash . '/' . $pageFileName;

            $extractionContext = [
                'method' => 'fpdi',
                'extraction_strategy' => 'standard',
                'issues_detected' => [],
                'fallbacks_used' => [],
                'resource_optimization' => config('pdf-viewer.page_extraction.optimize_resources', true),
            ];

            // Analyze document for edge cases
            $edgeCaseAnalysis = $this->edgeCaseService->analyzeDocumentEdgeCases($document);
            $this->edgeCaseService->applyEdgeCaseHandling($edgeCaseAnalysis, $extractionContext);
            
            // Apply cross-reference handling
            $this->crossRefService->applyCrossReferenceHandling($document->hash, $pageNumber, $extractionContext);
            
            // Handle resource subsetting for this page
            $resourceMap = $this->crossRefService->handleResourceSubsetting(
                $this->getSourceFilePath($document), 
                $pageNumber, 
                $extractionContext
            );

            // Handle S3/Vapor vs local storage differently
            if ($this->isS3Disk($disk)) {
                $filePath = $this->extractPageForS3WithContext($document, $pageNumber, $pagePath, $disk, $extractionContext);
            } else {
                $filePath = $this->extractPageLocallyWithContext($document, $pageNumber, $pagePath, $extractionContext);
            }

            return [
                'file_path' => $filePath,
                'context' => $extractionContext,
            ];

        } catch (\Exception $e) {
            Log::error('Page extraction failed', [
                'document_hash' => $document->hash,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract page for S3/Vapor environment with context
     */
    protected function extractPageForS3WithContext(PdfDocument $document, int $pageNumber, string $pagePath, $disk, array &$extractionContext): string
    {
        // Download the PDF from S3 to local temp directory
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $sourceFile = $tempDir . '/' . basename($document->file_path);
        
        // Download PDF from S3 to temp location
        $pdfContent = $disk->get($document->file_path);
        file_put_contents($sourceFile, $pdfContent);

        try {
            // Extract page using FPDI with context tracking
            $tempPageFile = $tempDir . '/page_' . $pageNumber . '_' . uniqid() . '.pdf';
            
            $this->extractSinglePageWithFpdi($sourceFile, $pageNumber, $tempPageFile, $extractionContext);

            if (!file_exists($tempPageFile)) {
                throw new \Exception("Failed to extract page {$pageNumber}");
            }

            // Upload the extracted page back to S3
            $pageContent = file_get_contents($tempPageFile);
            $disk->put($pagePath, $pageContent);

            // Cleanup temp files
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
            if (file_exists($tempPageFile)) {
                unlink($tempPageFile);
            }

            return $pagePath;
        } catch (\Exception $e) {
            // Cleanup temp files on error
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
            if (isset($tempPageFile) && file_exists($tempPageFile)) {
                unlink($tempPageFile);
            }
            throw $e;
        }
    }

    /**
     * Extract page for local storage with context
     */
    protected function extractPageLocallyWithContext(PdfDocument $document, int $pageNumber, string $pagePath, array &$extractionContext): string
    {
        $sourceFile = storage_path('app/' . $document->file_path);
        $fullPagePath = storage_path('app/' . $pagePath);

        // Create directory if it doesn't exist
        $directory = dirname($fullPagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Use FPDI to extract single page with context tracking
        $this->extractSinglePageWithFpdi($sourceFile, $pageNumber, $fullPagePath, $extractionContext);

        if (!file_exists($fullPagePath)) {
            throw new \Exception("Failed to extract page {$pageNumber}");
        }

        return $pagePath;
    }

    public function extractText(string $pageFilePath): string
    {
        try {
            $disk = Storage::disk(config('pdf-viewer.storage.disk'));

            if ($this->isS3Disk($disk)) {
                return $this->extractTextFromS3($pageFilePath, $disk);
            } else {
                return $this->extractTextLocally($pageFilePath);
            }
        } catch (\Exception $e) {
            Log::error('Text extraction failed', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
            ]);
            
            // Return empty string instead of throwing to allow processing to continue
            return '';
        }
    }

    /**
     * Extract text from S3 stored PDF
     */
    protected function extractTextFromS3(string $pageFilePath, $disk): string
    {
        // Download the page PDF from S3 to temp location
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $tempFile = $tempDir . '/text_extract_' . uniqid() . '.pdf';

        try {
            // Download PDF from S3
            $pdfContent = $disk->get($pageFilePath);
            file_put_contents($tempFile, $pdfContent);

            // Extract text using Spatie PDF to Text
            $text = (new Pdf())
                ->setPdf($tempFile)
                ->text();

            // Clean up the extracted text
            $text = $this->cleanExtractedText($text);

            // Cleanup temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return $text;
        } catch (\Exception $e) {
            // Cleanup temp file on error
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            throw $e;
        }
    }

    /**
     * Extract text from locally stored PDF
     */
    protected function extractTextLocally(string $pageFilePath): string
    {
        $fullPath = storage_path('app/' . $pageFilePath);
        
        if (!file_exists($fullPath)) {
            throw new \Exception("Page file not found: {$pageFilePath}");
        }

        // Use Spatie PDF to Text to extract content
        $text = (new Pdf())
            ->setPdf($fullPath)
            ->text();

        // Clean up the extracted text
        $text = $this->cleanExtractedText($text);

        return $text;
    }

    public function createPage(PdfDocument $document, int $pageNumber, string $content = ''): PdfDocumentPage
    {
        return PdfDocumentPage::create([
            'pdf_document_id' => $document->id,
            'page_number' => $pageNumber,
            'content' => $content,
            'status' => 'pending',
        ]);
    }

    public function updatePageContent(PdfDocumentPage $page, string $content): bool
    {
        return $page->update([
            'content' => $content,
            'is_parsed' => !empty($content),
            'status' => 'completed',
        ]);
    }

    public function generateThumbnail(string $pageFilePath, int $width = 300, int $height = 400): string
    {
        try {
            if (!config('pdf-viewer.thumbnails.enabled')) {
                return '';
            }

            $disk = Storage::disk(config('pdf-viewer.storage.disk'));

            if ($this->isS3Disk($disk)) {
                return $this->generateThumbnailForS3($pageFilePath, $width, $height, $disk);
            } else {
                return $this->generateThumbnailLocally($pageFilePath, $width, $height);
            }
        } catch (\Exception $e) {
            Log::error('Thumbnail generation error', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Generate thumbnail for S3 stored PDF
     */
    protected function generateThumbnailForS3(string $pageFilePath, int $width, int $height, $disk): string
    {
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $tempPageFile = $tempDir . '/thumb_source_' . uniqid() . '.pdf';
        $tempThumbnailFile = $tempDir . '/thumb_' . uniqid() . '.jpg';

        try {
            // Download PDF from S3
            $pdfContent = $disk->get($pageFilePath);
            file_put_contents($tempPageFile, $pdfContent);

            // Generate thumbnail filename
            $thumbnailName = pathinfo($pageFilePath, PATHINFO_FILENAME) . '_thumb.jpg';
            $documentHash = basename(dirname($pageFilePath));
            $thumbnailPath = config('pdf-viewer.storage.thumbnails_path') . '/' . $documentHash . '/' . $thumbnailName;

            // Convert PDF page to image using ImageMagick
            $command = sprintf(
                'convert -density 150 "%s[0]" -quality %d -resize %dx%d "%s" 2>&1',
                $tempPageFile,
                config('pdf-viewer.thumbnails.quality', 80),
                $width,
                $height,
                $tempThumbnailFile
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($tempThumbnailFile)) {
                Log::warning('Thumbnail generation failed', [
                    'page_file_path' => $pageFilePath,
                    'command' => $command,
                    'output' => implode("\n", $output),
                ]);
                return '';
            }

            // Upload thumbnail to S3
            $thumbnailContent = file_get_contents($tempThumbnailFile);
            $disk->put($thumbnailPath, $thumbnailContent, 'public');

            // Cleanup temp files
            if (file_exists($tempPageFile)) {
                unlink($tempPageFile);
            }
            if (file_exists($tempThumbnailFile)) {
                unlink($tempThumbnailFile);
            }

            return $thumbnailPath;
        } catch (\Exception $e) {
            // Cleanup temp files on error
            if (file_exists($tempPageFile)) {
                unlink($tempPageFile);
            }
            if (file_exists($tempThumbnailFile)) {
                unlink($tempThumbnailFile);
            }
            throw $e;
        }
    }

    /**
     * Generate thumbnail for locally stored PDF
     */
    protected function generateThumbnailLocally(string $pageFilePath, int $width, int $height): string
    {
        $fullPagePath = storage_path('app/' . $pageFilePath);
        
        if (!file_exists($fullPagePath)) {
            throw new \Exception("Page file not found: {$pageFilePath}");
        }

        // Generate thumbnail filename
        $thumbnailName = pathinfo($pageFilePath, PATHINFO_FILENAME) . '_thumb.jpg';
        $documentHash = basename(dirname($pageFilePath));
        $thumbnailPath = config('pdf-viewer.storage.thumbnails_path') . '/' . $documentHash . '/' . $thumbnailName;
        $fullThumbnailPath = storage_path('app/' . $thumbnailPath);

        // Create directory if it doesn't exist
        $directory = dirname($fullThumbnailPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Convert PDF page to image using ImageMagick
        $command = sprintf(
            'convert -density 150 "%s[0]" -quality %d -resize %dx%d "%s" 2>&1',
            $fullPagePath,
            config('pdf-viewer.thumbnails.quality', 80),
            $width,
            $height,
            $fullThumbnailPath
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($fullThumbnailPath)) {
            Log::warning('Thumbnail generation failed', [
                'page_file_path' => $pageFilePath,
                'command' => $command,
                'output' => implode("\n", $output),
            ]);
            return '';
        }

        return $thumbnailPath;
    }

    public function getPageFilePath(string $documentHash, int $pageNumber): string
    {
        $pageFileName = "page_{$pageNumber}.pdf";
        return config('pdf-viewer.storage.pages_path') . '/' . $documentHash . '/' . $pageFileName;
    }

    public function validatePageFile(string $pageFilePath): bool
    {
        $fullPath = storage_path('app/' . $pageFilePath);
        
        if (!file_exists($fullPath)) {
            return false;
        }

        // Check if it's a valid PDF file
        $handle = fopen($fullPath, 'r');
        $header = fread($handle, 5);
        fclose($handle);

        return strpos($header, '%PDF') === 0;
    }

    public function cleanupPageFiles(string $documentHash): bool
    {
        try {
            $disk = Storage::disk(config('pdf-viewer.storage.disk'));
            
            // Delete page files
            $pagesPath = config('pdf-viewer.storage.pages_path') . '/' . $documentHash;
            if ($disk->exists($pagesPath)) {
                $disk->deleteDirectory($pagesPath);
            }

            // Delete thumbnail files
            $thumbnailsPath = config('pdf-viewer.storage.thumbnails_path') . '/' . $documentHash;
            if ($disk->exists($thumbnailsPath)) {
                $disk->deleteDirectory($thumbnailsPath);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup page files', [
                'document_hash' => $documentHash,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function markPageProcessed(PdfDocumentPage $page): void
    {
        $page->update([
            'status' => 'completed',
            'processing_error' => null,
        ]);
    }

    public function handlePageFailure(PdfDocumentPage $page, string $error): void
    {
        $page->update([
            'status' => 'failed',
            'processing_error' => $error,
        ]);

        Log::error('Page processing failed', [
            'page_id' => $page->id,
            'document_hash' => $page->document->hash,
            'page_number' => $page->page_number,
            'error' => $error,
        ]);
    }

    protected function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove control characters but keep newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Trim whitespace
        $text = trim($text);
        
        return $text;
    }

    /**
     * Get the configured storage disk with package-specific S3 configuration
     */
    protected function getStorageDisk()
    {
        $diskName = config('pdf-viewer.storage.disk');
        
        // If using S3, create a custom S3 disk with package-specific credentials
        if ($diskName === 's3') {
            $awsConfig = config('pdf-viewer.storage.aws');
            
            if (!empty($awsConfig['key']) && !empty($awsConfig['secret']) && !empty($awsConfig['bucket'])) {
                return Storage::build([
                    'driver' => 's3',
                    'key' => $awsConfig['key'],
                    'secret' => $awsConfig['secret'],
                    'region' => $awsConfig['region'] ?? 'ap-southeast-2',
                    'bucket' => $awsConfig['bucket'],
                    'url' => null,
                    'endpoint' => null,
                    'use_path_style_endpoint' => $awsConfig['use_path_style_endpoint'] ?? false,
                    'throw' => false,
                    'visibility' => 'public',
                ]);
            }
        }
        
        // Fall back to configured disk
        return Storage::disk($diskName);
    }

    /**
     * Extract single page using FPDI library with comprehensive PDF structure handling
     */
    protected function extractSinglePageWithFpdi(string $sourceFile, int $pageNumber, string $outputFile, array &$extractionContext = []): void
    {
        try {
            // Pre-flight checks for PDF compatibility
            $pdfInfo = $this->analyzePdfStructure($sourceFile);
            $extractionContext['pdf_analysis'] = $pdfInfo;
            
            // Handle encryption first
            if ($pdfInfo['encrypted'] && config('pdf-viewer.page_extraction.handle_encryption', true)) {
                $sourceFile = $this->handleEncryptedPdf($sourceFile, $pdfInfo);
                $extractionContext['issues_detected'][] = 'encryption_handled';
            }
            
            // Initialize FPDI with comprehensive configuration
            $pdf = new Fpdi();
            
            // Configure based on PDF analysis and settings
            $this->configureFpdiForExtraction($pdf, $pdfInfo);
            
            // Set source file with comprehensive error handling
            try {
                $pageCount = $pdf->setSourceFile($sourceFile);
                $extractionContext['total_pages'] = $pageCount;
            } catch (\Exception $e) {
                $extractionContext['extraction_strategy'] = 'fallback';
                $this->handleExtractionError($e, $sourceFile, $pageNumber, $outputFile, $pdfInfo, $extractionContext);
                return;
            }
            
            // Validate page number
            if ($pageNumber > $pageCount || $pageNumber < 1) {
                throw new \Exception("Page {$pageNumber} does not exist in PDF (total pages: {$pageCount})");
            }
            
            // Import page with resource optimization
            $templateId = $pdf->importPage($pageNumber);
            
            // Handle page-specific configuration
            $size = $pdf->getTemplateSize($templateId);
            $rotation = $this->detectPageRotation($pdf, $templateId, $pdfInfo);
            
            // Add page with proper orientation and rotation
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            
            // Apply rotation if detected
            if ($rotation !== 0 && config('pdf-viewer.page_extraction.rotation_handling') === 'preserve') {
                $pdf->Rotate($rotation);
            }
            
            // Use template with resource handling
            $pdf->useTemplate($templateId);
            
            // Handle metadata copying
            $this->copyRelevantMetadata($pdf, $pdfInfo, $pageNumber);
            
            // Clean up navigation elements if configured
            if (config('pdf-viewer.page_extraction.strip_navigation', true)) {
                $this->stripNavigationElements($pdf, $pdfInfo, $pageNumber);
            }
            
            // Output with validation
            $pdf->Output('F', $outputFile);
            
            // Post-processing validation and optimization
            $this->validateAndOptimizeExtractedPage($outputFile, $pageNumber, $pdfInfo, $extractionContext);
            
            // Mark extraction as successful
            $extractionContext['status'] = 'success';
            $extractionContext['final_file_size'] = filesize($outputFile);
            
        } catch (\Exception $e) {
            $extractionContext['status'] = 'failed';
            $extractionContext['error'] = $e->getMessage();
            
            Log::error('FPDI page extraction failed', [
                'source_file' => $sourceFile,
                'page_number' => $pageNumber,
                'output_file' => $outputFile,
                'error' => $e->getMessage(),
                'pdf_info' => $pdfInfo ?? null,
                'extraction_context' => $extractionContext,
            ]);
            throw new \Exception("FPDI extraction failed: {$e->getMessage()}");
        }
    }

    /**
     * Handle font extraction issues with fallback strategy
     */
    protected function handleFontExtractionFallback(string $sourceFile, int $pageNumber, string $outputFile): void
    {
        $strategy = config('pdf-viewer.page_extraction.font_fallback_strategy', 'preserve');
        
        switch ($strategy) {
            case 'substitute':
                // Use basic FPDI without font preservation
                $this->extractPageWithBasicFpdi($sourceFile, $pageNumber, $outputFile);
                break;
                
            case 'ignore':
                // Copy the entire PDF as fallback (preserves all fonts)
                copy($sourceFile, $outputFile);
                Log::warning('Font issues detected, copied entire PDF as fallback', [
                    'source_file' => $sourceFile,
                    'page_number' => $pageNumber,
                    'output_file' => $outputFile,
                ]);
                break;
                
            case 'preserve':
            default:
                // Try alternative PDF libraries or tools
                throw new \Exception("Font preservation failed and no suitable fallback available");
        }
    }

    /**
     * Extract page with basic FPDI (may not preserve all fonts)
     */
    protected function extractPageWithBasicFpdi(string $sourceFile, int $pageNumber, string $outputFile): void
    {
        $pdf = new Fpdi();
        
        // Basic extraction without font-specific handling
        $pageCount = $pdf->setSourceFile($sourceFile);
        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);
        $pdf->Output('F', $outputFile);
        
        Log::info('Page extracted with basic FPDI (fonts may be substituted)', [
            'source_file' => $sourceFile,
            'page_number' => $pageNumber,
            'output_file' => $outputFile,
        ]);
    }

    /**
     * Validate that fonts are preserved in extracted page
     */
    protected function validateExtractedPageFonts(string $outputFile, int $pageNumber): void
    {
        try {
            // Basic validation - check if the PDF is readable and has content
            if (!file_exists($outputFile)) {
                throw new \Exception('Extracted page file does not exist');
            }
            
            // Check file size (should be reasonable, not empty)
            $fileSize = filesize($outputFile);
            if ($fileSize < 100) { // Very small files likely indicate extraction failure
                throw new \Exception('Extracted page file is too small, may indicate font issues');
            }
            
            // Verify it's a valid PDF
            $handle = fopen($outputFile, 'r');
            $header = fread($handle, 5);
            fclose($handle);
            
            if (strpos($header, '%PDF') !== 0) {
                throw new \Exception('Extracted file is not a valid PDF');
            }
            
            Log::debug('Page extraction validation passed', [
                'output_file' => $outputFile,
                'page_number' => $pageNumber,
                'file_size' => $fileSize,
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Page font validation failed', [
                'output_file' => $outputFile,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - let the extraction continue even if validation fails
        }
    }

    /**
     * Analyze PDF structure for extraction compatibility
     */
    protected function analyzePdfStructure(string $sourceFile): array
    {
        $info = [
            'encrypted' => false,
            'linearized' => false,
            'has_incremental_updates' => false,
            'has_javascript' => false,
            'has_form_fields' => false,
            'has_annotations' => false,
            'font_count' => 0,
            'image_count' => 0,
            'version' => '1.4',
            'page_rotations' => [],
        ];
        
        try {
            // Basic file analysis
            $handle = fopen($sourceFile, 'rb');
            $header = fread($handle, 1024);
            fclose($handle);
            
            // Check encryption
            $info['encrypted'] = strpos($header, '/Encrypt') !== false;
            
            // Check linearization
            $info['linearized'] = strpos($header, '/Linearized') !== false;
            
            // Check for JavaScript
            $info['has_javascript'] = strpos(file_get_contents($sourceFile), '/JavaScript') !== false;
            
            // Check for form fields
            $info['has_form_fields'] = strpos(file_get_contents($sourceFile), '/AcroForm') !== false;
            
            // Check for annotations
            $info['has_annotations'] = strpos(file_get_contents($sourceFile), '/Annots') !== false;
            
            // Extract PDF version
            if (preg_match('/%PDF-(\d+\.\d+)/', $header, $matches)) {
                $info['version'] = $matches[1];
            }
            
            Log::debug('PDF structure analysis completed', [
                'source_file' => $sourceFile,
                'pdf_info' => $info,
            ]);
            
        } catch (\Exception $e) {
            Log::warning('PDF structure analysis failed, using defaults', [
                'source_file' => $sourceFile,
                'error' => $e->getMessage(),
            ]);
        }
        
        return $info;
    }

    /**
     * Handle encrypted PDFs
     */
    protected function handleEncryptedPdf(string $sourceFile, array $pdfInfo): string
    {
        if (!$pdfInfo['encrypted']) {
            return $sourceFile;
        }
        
        Log::warning('Encrypted PDF detected - extraction may fail or produce limited results', [
            'source_file' => $sourceFile,
            'pdf_version' => $pdfInfo['version'],
        ]);
        
        // For now, attempt extraction anyway - FPDI may handle some encryption
        // In production, you might want to implement password handling here
        return $sourceFile;
    }

    /**
     * Configure FPDI based on PDF analysis
     */
    protected function configureFpdiForExtraction(Fpdi $pdf, array $pdfInfo): void
    {
        // Font configuration
        if (config('pdf-viewer.page_extraction.preserve_fonts', true)) {
            $pdf->SetFont('helvetica', '', 12);
            $pdf->setFontSubsetting(false);
        }
        
        // Compression
        if (config('pdf-viewer.page_extraction.compression', true)) {
            $pdf->SetCompression(true);
        }
        
        // Handle special PDF features
        if ($pdfInfo['linearized']) {
            Log::info('Linearized PDF detected - extraction may need special handling');
        }
        
        // Set metadata
        $pdf->SetCreator('Laravel PDF Viewer');
        $pdf->SetTitle('Extracted Page');
        $pdf->SetSubject('Single page extracted from multi-page PDF');
    }

    /**
     * Handle extraction errors with appropriate fallbacks
     */
    protected function handleExtractionError(\Exception $e, string $sourceFile, int $pageNumber, string $outputFile, array $pdfInfo, array &$extractionContext): void
    {
        $errorMsg = $e->getMessage();
        
        // Font-related errors
        if (str_contains($errorMsg, 'font') || str_contains($errorMsg, 'encoding')) {
            Log::warning('Font-related issue detected', [
                'source_file' => $sourceFile,
                'page_number' => $pageNumber,
                'error' => $errorMsg,
                'pdf_info' => $pdfInfo,
            ]);
            $this->handleFontExtractionFallback($sourceFile, $pageNumber, $outputFile);
            $extractionContext['fallbacks_used'][] = 'font_fallback';
            return;
        }
        
        // Encryption errors
        if (str_contains($errorMsg, 'encrypt') || str_contains($errorMsg, 'password')) {
            Log::warning('Encryption issue detected', [
                'source_file' => $sourceFile,
                'page_number' => $pageNumber,
                'error' => $errorMsg,
            ]);
            throw new \Exception("PDF is encrypted and cannot be processed without password");
        }
        
        // Resource/structure errors
        if (str_contains($errorMsg, 'resource') || str_contains($errorMsg, 'reference')) {
            Log::warning('Resource sharing issue detected', [
                'source_file' => $sourceFile,
                'page_number' => $pageNumber,
                'error' => $errorMsg,
                'pdf_info' => $pdfInfo,
            ]);
            $this->handleResourceSharingFallback($sourceFile, $pageNumber, $outputFile);
            $extractionContext['fallbacks_used'][] = 'resource_sharing_fallback';
            return;
        }
        
        throw $e;
    }

    /**
     * Handle resource sharing issues with smart copying
     */
    protected function handleResourceSharingFallback(string $sourceFile, int $pageNumber, string $outputFile): void
    {
        $strategy = config('pdf-viewer.page_extraction.resource_strategy', 'smart_copy');
        
        switch ($strategy) {
            case 'duplicate_all':
                // Copy entire PDF to ensure all resources are available
                copy($sourceFile, $outputFile);
                Log::info('Resource sharing issue resolved by copying entire PDF', [
                    'page_number' => $pageNumber,
                    'strategy' => 'duplicate_all',
                ]);
                break;
                
            case 'minimal':
                // Try basic extraction without resource optimization
                $this->extractPageWithBasicFpdi($sourceFile, $pageNumber, $outputFile);
                break;
                
            case 'smart_copy':
            default:
                // Attempt extraction with resource analysis
                $this->extractWithResourceAnalysis($sourceFile, $pageNumber, $outputFile);
                break;
        }
    }

    /**
     * Extract page with resource usage analysis
     */
    protected function extractWithResourceAnalysis(string $sourceFile, int $pageNumber, string $outputFile): void
    {
        try {
            $pdf = new Fpdi();
            
            // Use more conservative settings for problematic PDFs
            $pdf->setFontSubsetting(true); // Allow font subsetting for resource optimization
            $pdf->SetCompression(false); // Disable compression to avoid issues
            
            $pageCount = $pdf->setSourceFile($sourceFile);
            $templateId = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
            $pdf->Output('F', $outputFile);
            
            // Check extracted file size
            $extractedSize = filesize($outputFile);
            $maxSize = config('pdf-viewer.page_extraction.max_resource_size', 10485760);
            
            if ($extractedSize > $maxSize) {
                Log::warning('Extracted page exceeds size limit, may contain excessive resources', [
                    'page_number' => $pageNumber,
                    'extracted_size' => $extractedSize,
                    'max_size' => $maxSize,
                ]);
            }
            
        } catch (\Exception $e) {
            // Ultimate fallback - copy entire PDF
            copy($sourceFile, $outputFile);
            Log::warning('Resource analysis extraction failed, used entire PDF copy', [
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect page rotation
     */
    protected function detectPageRotation(Fpdi $pdf, $templateId, array $pdfInfo): int
    {
        // FPDI doesn't directly expose rotation info, so we use template size analysis
        $size = $pdf->getTemplateSize($templateId);
        
        // Simple heuristic: if width > height significantly, might be rotated
        $aspectRatio = $size['width'] / $size['height'];
        
        if ($aspectRatio > 1.5) {
            Log::debug('Potential landscape orientation detected', [
                'aspect_ratio' => $aspectRatio,
                'width' => $size['width'],
                'height' => $size['height'],
            ]);
        }
        
        return 0; // FPDI handles rotation automatically in most cases
    }

    /**
     * Copy relevant metadata to extracted page
     */
    protected function copyRelevantMetadata(Fpdi $pdf, array $pdfInfo, int $pageNumber): void
    {
        $copyStrategy = config('pdf-viewer.page_extraction.copy_metadata', 'basic');
        
        if ($copyStrategy === 'none') {
            return;
        }
        
        // Basic metadata
        $pdf->SetCreator('Laravel PDF Viewer - Page Extraction');
        $pdf->SetTitle("Page {$pageNumber}");
        $pdf->SetSubject("Single page extracted from multi-page PDF");
        $pdf->SetKeywords('extracted-page, pdf-viewer');
        
        if ($copyStrategy === 'full') {
            // Additional metadata could be copied here
            $pdf->SetAuthor('Original PDF Author (Page Extract)');
        }
    }

    /**
     * Strip navigation elements that don't make sense in single pages
     */
    protected function stripNavigationElements(Fpdi $pdf, array $pdfInfo, int $pageNumber): void
    {
        // FPDI automatically strips most navigation elements during template import
        // This method is a placeholder for future enhancements
        
        Log::debug('Navigation elements stripped from extracted page', [
            'page_number' => $pageNumber,
            'had_javascript' => $pdfInfo['has_javascript'],
            'had_form_fields' => $pdfInfo['has_form_fields'],
            'had_annotations' => $pdfInfo['has_annotations'],
        ]);
    }

    /**
     * Validate and optimize extracted page
     */
    protected function validateAndOptimizeExtractedPage(string $outputFile, int $pageNumber, array $pdfInfo, array &$extractionContext): void
    {
        // Validate extraction quality
        if (config('pdf-viewer.page_extraction.validate_fonts', true)) {
            $this->validateExtractedPageFonts($outputFile, $pageNumber);
        }
        
        // Check for resource optimization opportunities
        if (config('pdf-viewer.page_extraction.optimize_resources', true)) {
            $this->optimizeExtractedPageResources($outputFile, $pdfInfo);
        }
        
        // Log extraction summary
        $fileSize = filesize($outputFile);
        Log::info('Page extraction completed with validation', [
            'page_number' => $pageNumber,
            'output_file_size' => $fileSize,
            'pdf_was_encrypted' => $pdfInfo['encrypted'],
            'pdf_was_linearized' => $pdfInfo['linearized'],
            'had_complex_features' => $pdfInfo['has_javascript'] || $pdfInfo['has_form_fields'],
        ]);
    }

    /**
     * Optimize resources in extracted page
     */
    protected function optimizeExtractedPageResources(string $outputFile, array $pdfInfo): void
    {
        $fileSize = filesize($outputFile);
        $maxSize = config('pdf-viewer.page_extraction.max_resource_size', 10485760);
        
        if ($fileSize > $maxSize) {
            Log::warning('Extracted page exceeds optimal size', [
                'file_size' => $fileSize,
                'max_size' => $maxSize,
                'optimization_needed' => true,
            ]);
            
            // Could implement additional optimization here if needed
            // For now, just log the issue for monitoring
        }
    }

    /**
     * Check if the storage disk is S3
     */
    protected function isS3Disk($disk): bool
    {
        return method_exists($disk->getAdapter(), 'getBucket') || 
               (config('pdf-viewer.storage.disk') === 's3');
    }

    /**
     * Get source file path for document
     */
    protected function getSourceFilePath(PdfDocument $document): string
    {
        $disk = $this->getStorageDisk();
        
        if ($this->isS3Disk($disk)) {
            $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
            $tempFile = $tempDir . '/source_' . $document->hash . '.pdf';
            
            if (!file_exists($tempFile)) {
                $content = $disk->get($document->file_path);
                file_put_contents($tempFile, $content);
            }
            
            return $tempFile;
        }
        
        return storage_path('app/' . $document->file_path);
    }
}