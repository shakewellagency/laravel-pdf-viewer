<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Filesystem\FilesystemAdapter;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfPageContent;
use Smalot\PdfParser\Parser;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class PageProcessingService implements PageProcessingServiceInterface
{
    public function extractPage(PdfDocument $document, int $pageNumber): string
    {
        try {
            $disk = $this->getStorageDisk();
            $pageFileName = "page_{$pageNumber}.pdf";
            $pagePath = config('pdf-viewer.storage.pages_path') . '/' . $document->hash . '/' . $pageFileName;

            // Handle S3/Vapor vs local storage differently
            if ($this->isS3Disk($disk)) {
                return $this->extractPageForS3($document, $pageNumber, $pagePath, $disk);
            } else {
                return $this->extractPageLocally($document, $pageNumber, $pagePath);
            }
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
     * Extract page for S3/Vapor environment
     */
    protected function extractPageForS3(PdfDocument $document, int $pageNumber, string $pagePath, $disk): string
    {
        // Download the PDF from S3 to local temp directory
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $sourceFile = $tempDir . '/' . basename($document->file_path);
        
        // Download PDF from S3 to temp location
        $pdfContent = $disk->get($document->file_path);
        file_put_contents($sourceFile, $pdfContent);

        try {
            // Extract specific page using pdftk or similar command
            $pageFile = $tempDir . '/' . uniqid() . '_page_' . $pageNumber . '.pdf';
            
            // Use pdftk if available, otherwise fall back to copying entire file
            if ($this->isPdftkAvailable()) {
                $command = "pdftk \"{$sourceFile}\" cat {$pageNumber} output \"{$pageFile}\"";
                exec($command, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    throw new \Exception("Failed to extract page {$pageNumber}");
                }
            } else {
                // Fall back to copying the entire file for now
                copy($sourceFile, $pageFile);
            }

            // Upload extracted page to S3
            $pageContent = file_get_contents($pageFile);
            $disk->put($pagePath, $pageContent);

            // Clean up temp files
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
            if (file_exists($pageFile)) {
                unlink($pageFile);
            }

            return $pagePath;
        } catch (\Exception $e) {
            // Clean up on error
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
            if (isset($pageFile) && file_exists($pageFile)) {
                unlink($pageFile);
            }
            throw $e;
        }
    }

    /**
     * Extract page locally - improved version with fallback for missing pdftk
     */
    protected function extractPageLocally(PdfDocument $document, int $pageNumber, string $pagePath): string
    {
        // For Vapor compatibility, use /tmp for all file operations
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $sourceFile = $tempDir . '/' . basename($document->file_path);
        $pageFile = $tempDir . '/' . uniqid() . '_page_' . $pageNumber . '.pdf';
        
        // Download/copy source file to temp location
        $disk = $this->getStorageDisk();
        if ($this->isS3Disk($disk)) {
            $pdfContent = $disk->get($document->file_path);
            file_put_contents($sourceFile, $pdfContent);
        } else {
            $originalPath = storage_path('app/' . $document->file_path);
            if (file_exists($originalPath)) {
                copy($originalPath, $sourceFile);
            } else {
                throw new \Exception("Source PDF file not found: {$originalPath}");
            }
        }

        try {
            if ($this->isPdftkAvailable()) {
                // Use pdftk to extract specific page
                $command = "pdftk \"{$sourceFile}\" cat {$pageNumber} output \"{$pageFile}\"";
                exec($command, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    throw new \Exception("Failed to extract page {$pageNumber}");
                }
            } else {
                // Pure PHP approach for environments without pdftk
                Log::info("Creating page reference without system dependencies", [
                    'document_hash' => $document->hash,
                    'page_number' => $pageNumber,
                    'temp_source_file' => $sourceFile,
                    'page_path' => $pagePath
                ]);
                
                // Create a minimal reference file
                $referenceData = json_encode([
                    'type' => 'page_reference',
                    'original_pdf' => $document->file_path,
                    'page_number' => $pageNumber,
                    'document_hash' => $document->hash,
                    'created_at' => now()->toISOString()
                ]);
                file_put_contents($pageFile . '.ref', $referenceData);
                
                // For compatibility, create a copy in temp location
                if (file_exists($sourceFile)) {
                    copy($sourceFile, $pageFile);
                    
                    Log::info("Created page reference and temp copy", [
                        'page_file' => $pageFile,
                        'reference_file' => $pageFile . '.ref',
                        'size' => filesize($pageFile)
                    ]);
                } else {
                    throw new \Exception("Source PDF file not found: {$sourceFile}");
                }
            }

            // Upload processed page file to storage if using S3
            if ($this->isS3Disk($disk) && file_exists($pageFile)) {
                $pageContent = file_get_contents($pageFile);
                $disk->put($pagePath, $pageContent);
            }

            return $pagePath;
        } finally {
            // Always clean up temp files
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
            if (file_exists($pageFile)) {
                unlink($pageFile);
            }
            if (file_exists($pageFile . '.ref')) {
                unlink($pageFile . '.ref');
            }
        }
    }

    /**
     * Extract text from a page file path - improved version with direct PDF reading
     */
    public function extractText(string $pageFilePath): string
    {
        Log::info('Starting extractText with improved page-aware parsing', [
            'page_file_path' => $pageFilePath,
        ]);
        
        try {
            // Extract text from specific page using smalot/pdfparser
            
            // Get the document hash from the page file path
            $pathParts = explode('/', $pageFilePath);
            $documentHash = $pathParts[1] ?? null;
            $pageFileName = $pathParts[2] ?? null;
            
            Log::info('Path parsing', [
                'path_parts' => $pathParts,
                'document_hash' => $documentHash,
                'page_file_name' => $pageFileName,
            ]);
            
            if (!$documentHash || !$pageFileName) {
                Log::error('Invalid page file path format', ['page_file_path' => $pageFilePath]);
                return '';
            }
            
            // Extract page number from filename (e.g., "page_1.pdf" -> 1)
            if (!preg_match('/page_(\d+)\.pdf/', $pageFileName, $matches)) {
                Log::error('Could not extract page number from filename', ['page_file_path' => $pageFilePath]);
                return '';
            }
            $pageNumber = (int) $matches[1];
            
            Log::info('Page number extracted', ['page_number' => $pageNumber]);
            
            // Find the original PDF document
            $document = PdfDocument::where('hash', $documentHash)->first();
            if (!$document) {
                Log::error('Document not found for hash', ['hash' => $documentHash]);
                return '';
            }
            
            Log::info('Document found', [
                'document_id' => $document->id,
                'document_title' => $document->title,
                'document_file_path' => $document->file_path,
            ]);
            
            // Extract text from specific page of the original PDF
            $disk = Storage::disk(config('pdf-viewer.storage.disk'));
            $isS3 = $this->isS3Disk($disk);
            
            Log::info('Storage check', ['is_s3' => $isS3]);
            
            if ($isS3) {
                $text = $this->extractPageTextFromS3($document, $pageNumber, $disk);
            } else {
                $text = $this->extractPageTextLocally($document, $pageNumber);
            }
            
            Log::info('Page text extraction result', [
                'page_number' => $pageNumber,
                'text_length' => strlen($text),
                'first_100_chars' => substr($text, 0, 100),
            ]);
            
            return $text;
            
        } catch (\Exception $e) {
            Log::error('Text extraction failed', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return empty string instead of throwing to allow processing to continue
            return '';
        }
    }

    /**
     * Extract text from specific page of PDF stored locally
     */
    protected function extractPageTextLocally($document, int $pageNumber): string
    {
        $fullPath = storage_path('app/' . $document->file_path);
        
        Log::info('Local page extraction', [
            'document_file_path' => $document->file_path,
            'full_path' => $fullPath,
            'page_number' => $pageNumber,
            'file_exists' => file_exists($fullPath),
            'file_size' => file_exists($fullPath) ? filesize($fullPath) : 'N/A',
        ]);
        
        if (!file_exists($fullPath)) {
            throw new \Exception("Document file not found: {$document->file_path}");
        }

        // Use smalot/pdfparser (pure PHP) to extract content from specific page
        $parser = new Parser();
        $pdf = $parser->parseFile($fullPath);
        $pages = $pdf->getPages();
        
        Log::info('PDF pages loaded', [
            'total_pages' => count($pages),
            'requested_page' => $pageNumber,
        ]);
        
        // Check if requested page exists (pages are 0-indexed in array, but we use 1-based numbering)
        if (!isset($pages[$pageNumber - 1])) {
            Log::warning('Requested page does not exist', [
                'page_number' => $pageNumber,
                'total_pages' => count($pages),
            ]);
            return '';
        }
        
        // Get text from specific page
        $page = $pages[$pageNumber - 1];
        $rawText = $page->getText();
        
        Log::info('Raw page text extracted', [
            'page_number' => $pageNumber,
            'raw_text_length' => strlen($rawText),
            'raw_first_200_chars' => substr($rawText, 0, 200),
        ]);

        // Clean up the extracted text
        $text = $this->cleanExtractedText($rawText);
        
        Log::info('After cleaning page text', [
            'page_number' => $pageNumber,
            'cleaned_text_length' => strlen($text),
            'cleaned_first_200_chars' => substr($text, 0, 200),
        ]);

        return $text;
    }

    /**
     * Extract text from specific page of PDF stored on S3
     */
    protected function extractPageTextFromS3($document, int $pageNumber, $disk): string
    {
        // Download the PDF from S3 to temp location
        $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
        $tempFile = $tempDir . '/page_extract_' . uniqid() . '.pdf';

        Log::info('S3 page extraction starting', [
            'document_file_path' => $document->file_path,
            'page_number' => $pageNumber,
            'temp_file' => $tempFile,
            's3_exists' => $disk->exists($document->file_path),
        ]);

        try {
            // Download PDF from S3
            $pdfContent = $disk->get($document->file_path);
            
            Log::info('Downloaded from S3 for page extraction', [
                'content_length' => strlen($pdfContent),
                'page_number' => $pageNumber,
            ]);
            
            file_put_contents($tempFile, $pdfContent);
            
            Log::info('Temp file written for page extraction', [
                'temp_file_size' => file_exists($tempFile) ? filesize($tempFile) : 0,
                'temp_file_exists' => file_exists($tempFile),
            ]);

            // Extract text from specific page using smalot/pdfparser (pure PHP)
            $parser = new Parser();
            $pdf = $parser->parseFile($tempFile);
            $pages = $pdf->getPages();
            
            Log::info('S3 PDF pages loaded', [
                'total_pages' => count($pages),
                'requested_page' => $pageNumber,
            ]);
            
            // Check if requested page exists
            if (!isset($pages[$pageNumber - 1])) {
                Log::warning('S3: Requested page does not exist', [
                    'page_number' => $pageNumber,
                    'total_pages' => count($pages),
                ]);
                
                // Cleanup temp file
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                
                return '';
            }
            
            // Get text from specific page
            $page = $pages[$pageNumber - 1];
            $rawText = $page->getText();
            
            Log::info('S3 page text extracted', [
                'page_number' => $pageNumber,
                'raw_text_length' => strlen($rawText),
            ]);

            // Clean up the extracted text
            $text = $this->cleanExtractedText($rawText);
            
            Log::info('S3 page text cleaned', [
                'page_number' => $pageNumber,
                'cleaned_text_length' => strlen($text),
            ]);

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
     * Generate thumbnail for page - improved with better error handling
     */
    public function generateThumbnail(string $pageFilePath, int $width = 300, int $height = 400): string
    {
        try {
            $disk = $this->getStorageDisk();
            
            if ($this->isS3Disk($disk)) {
                return $this->generateThumbnailForS3($pageFilePath, $width, $height, $disk);
            } else {
                return $this->generateThumbnailLocally($pageFilePath, $width, $height);
            }
        } catch (\Exception $e) {
            Log::warning('Thumbnail generation failed', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Generate thumbnail locally
     */
    protected function generateThumbnailLocally(string $pageFilePath, int $width, int $height): string
    {
        try {
            $fullPath = storage_path('app/' . $pageFilePath);
            
            if (!file_exists($fullPath)) {
                return '';
            }

            // For now, return empty string as thumbnail generation requires additional setup
            // This can be implemented later with ImageMagick or similar tools
            return '';
        } catch (\Exception $e) {
            Log::warning('Local thumbnail generation failed', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Generate thumbnail for S3 stored file
     */
    protected function generateThumbnailForS3(string $pageFilePath, int $width, int $height, $disk): string
    {
        try {
            // For now, return empty string as thumbnail generation requires additional setup
            return '';
        } catch (\Exception $e) {
            Log::warning('S3 thumbnail generation failed', [
                'page_file_path' => $pageFilePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Update page content - updated to use separated content table
     */
    public function updatePageContent(PdfDocumentPage $page, string $content): bool
    {
        // Update the page status
        $page->update([
            'status' => 'completed',
            'is_parsed' => true,
            'metadata' => array_merge($page->metadata ?? [], [
                'text_extracted_at' => now()->toISOString(),
                'content_length' => strlen($content),
                'word_count' => str_word_count($content),
            ]),
        ]);

        // Create or update content in separate table for better performance
        PdfPageContent::createOrUpdateForPage($page, $content);

        return true;
    }

    /**
     * Mark page as processed
     */
    public function markPageProcessed(PdfDocumentPage $page): void
    {
        $page->update([
            'status' => 'completed',
            'is_parsed' => true,
        ]);
    }

    /**
     * Handle page processing failure
     */
    public function handlePageFailure(PdfDocumentPage $page, string $error): void
    {
        $page->update([
            'status' => 'failed',
            'processing_error' => $error,
        ]);
    }

    /**
     * Clean extracted text - improved version with better UTF-8 handling
     */
    protected function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim whitespace
        $text = trim($text);
        
        // Remove control characters but keep newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Use mb_convert_encoding to ensure valid UTF-8 and remove problematic sequences
        // This is safer than trying to match invalid surrogate pairs with regex
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove common problematic characters that cause MySQL encoding issues
        // Using individual character replacements instead of complex regex ranges
        $problematicChars = [
            "\xEF\xBF\xBD", // Unicode replacement character
            "\x00",          // Null byte
            "\xFF\xFE",      // BOM
            "\xFE\xFF",      // BOM
        ];
        
        foreach ($problematicChars as $char) {
            $text = str_replace($char, '', $text);
        }
        
        // Final validation - ensure the string is valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            Log::warning('Text contains invalid UTF-8, attempting to clean', ['original_length' => strlen($text)]);
            $text = mb_scrub($text, 'UTF-8');
            Log::info('Text cleaned with mb_scrub', ['new_length' => strlen($text)]);
        }
        
        return $text;
    }

    /**
     * Get storage disk - now uses dedicated PDF S3 configuration if available
     */
    protected function getStorageDisk()
    {
        // Check if PDF viewer has its own S3 configuration
        $pdfAwsConfig = config('pdf-viewer.storage.aws');
        
        if ($this->hasPdfViewerS3Config($pdfAwsConfig)) {
            return $this->createPdfViewerS3Disk($pdfAwsConfig);
        }
        
        // Fall back to default configured disk
        return Storage::disk(config('pdf-viewer.storage.disk'));
    }

    /**
     * Check if disk is S3
     */
    protected function isS3Disk($disk): bool
    {
        return $disk->getConfig()['driver'] === 's3';
    }

    /**
     * Check if pdftk is available (optional, for page splitting)
     */
    protected function isPdftkAvailable(): bool
    {
        exec('which pdftk', $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * Create page record - updated for separated content table
     */
    public function createPage(PdfDocument $document, int $pageNumber, string $content = ''): PdfDocumentPage
    {
        $page = $document->pages()->create([
            'page_number' => $pageNumber,
            'status' => 'pending',
            'is_parsed' => !empty($content),
        ]);

        // If content is provided, store it in the separate content table
        if (!empty($content)) {
            PdfPageContent::createOrUpdateForPage($page, $content);
        }

        return $page;
    }

    /**
     * Get page file path
     */
    public function getPageFilePath(string $documentHash, int $pageNumber): string
    {
        $pageFileName = "page_{$pageNumber}.pdf";
        return config('pdf-viewer.storage.pages_path') . '/' . $documentHash . '/' . $pageFileName;
    }

    /**
     * Validate page file
     */
    public function validatePageFile(string $pageFilePath): bool
    {
        $disk = $this->getStorageDisk();
        if ($this->isS3Disk($disk)) {
            return $disk->exists($pageFilePath);
        } else {
            $fullPath = storage_path('app/' . $pageFilePath);
            return file_exists($fullPath) && filesize($fullPath) > 0;
        }
    }

    /**
     * Clean up page files
     */
    public function cleanupPageFiles(string $documentHash): bool
    {
        try {
            $disk = $this->getStorageDisk();
            $pagesPath = config('pdf-viewer.storage.pages_path') . '/' . $documentHash;
            
            if ($this->isS3Disk($disk)) {
                $files = $disk->files($pagesPath);
                foreach ($files as $file) {
                    $disk->delete($file);
                }
            } else {
                $fullPath = storage_path('app/' . $pagesPath);
                if (is_dir($fullPath)) {
                    $files = glob($fullPath . '/*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                    rmdir($fullPath);
                }
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

    /**
     * Check if PDF viewer has its own S3 configuration
     */
    protected function hasPdfViewerS3Config(array $config): bool
    {
        return !empty($config['key']) && 
               !empty($config['secret']) && 
               !empty($config['region']) && 
               !empty($config['bucket']);
    }

    /**
     * Create dedicated PDF viewer S3 disk
     */
    protected function createPdfViewerS3Disk(array $awsConfig): FilesystemAdapter
    {
        $s3Client = new S3Client([
            'credentials' => [
                'key' => $awsConfig['key'],
                'secret' => $awsConfig['secret'],
            ],
            'region' => $awsConfig['region'],
            'version' => 'latest',
            'use_path_style_endpoint' => $awsConfig['use_path_style_endpoint'] ?? false,
        ]);

        $adapter = new AwsS3V3Adapter(
            $s3Client,
            $awsConfig['bucket']
        );

        $filesystem = new Filesystem($adapter);

        return new FilesystemAdapter($filesystem, $adapter, [
            'driver' => 's3',
            'key' => $awsConfig['key'],
            'secret' => $awsConfig['secret'],
            'region' => $awsConfig['region'],
            'bucket' => $awsConfig['bucket'],
        ]);
    }

    /**
     * Generate signed URL for PDF file
     */
    public function generateSignedUrl(string $filePath, int $expires = null): string
    {
        $disk = $this->getStorageDisk();
        
        if (!$this->isS3Disk($disk)) {
            throw new \Exception('Signed URLs are only available for S3 storage');
        }

        $expires = $expires ?: config('pdf-viewer.storage.signed_url_expires', 3600);
        
        return $disk->temporaryUrl($filePath, now()->addSeconds($expires));
    }

    /**
     * Generate signed URL for document
     */
    public function generateDocumentSignedUrl(PdfDocument $document, int $expires = null): string
    {
        return $this->generateSignedUrl($document->file_path, $expires);
    }

    /**
     * Generate signed URL for page
     */
    public function generatePageSignedUrl(string $documentHash, int $pageNumber, int $expires = null): string
    {
        $pageFilePath = $this->getPageFilePath($documentHash, $pageNumber);
        return $this->generateSignedUrl($pageFilePath, $expires);
    }

    /**
     * Generate multiple signed URLs for document pages
     */
    public function generatePagesSignedUrls(string $documentHash, array $pageNumbers, int $expires = null): array
    {
        $urls = [];
        
        foreach ($pageNumbers as $pageNumber) {
            try {
                $urls[$pageNumber] = $this->generatePageSignedUrl($documentHash, $pageNumber, $expires);
            } catch (\Exception $e) {
                Log::warning('Failed to generate signed URL for page', [
                    'document_hash' => $documentHash,
                    'page_number' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
                $urls[$pageNumber] = null;
            }
        }
        
        return $urls;
    }

    /**
     * Get the PDF viewer S3 client for advanced operations
     */
    protected function getPdfViewerS3Client(): ?S3Client
    {
        $pdfAwsConfig = config('pdf-viewer.storage.aws');
        
        if (!$this->hasPdfViewerS3Config($pdfAwsConfig)) {
            return null;
        }

        return new S3Client([
            'credentials' => [
                'key' => $pdfAwsConfig['key'],
                'secret' => $pdfAwsConfig['secret'],
            ],
            'region' => $pdfAwsConfig['region'],
            'version' => 'latest',
            'use_path_style_endpoint' => $pdfAwsConfig['use_path_style_endpoint'] ?? false,
        ]);
    }

    /**
     * Generate pre-signed POST data for direct uploads to PDF S3 bucket
     */
    public function generatePresignedPost(string $filePath, array $conditions = [], int $expires = null): array
    {
        $s3Client = $this->getPdfViewerS3Client();
        
        if (!$s3Client) {
            throw new \Exception('PDF viewer S3 configuration not available');
        }

        $bucket = config('pdf-viewer.storage.aws.bucket');
        $expires = $expires ?: config('pdf-viewer.storage.signed_url_expires', 3600);

        $defaultConditions = [
            ['bucket' => $bucket],
            ['starts-with', '$key', dirname($filePath) . '/'],
            ['content-length-range', 1, config('pdf-viewer.processing.max_file_size', 104857600)],
        ];

        $allConditions = array_merge($defaultConditions, $conditions);

        $postObject = $s3Client->createPresignedPost([
            'Bucket' => $bucket,
            'Key' => $filePath,
            'Conditions' => $allConditions,
            'Expires' => $expires,
        ]);

        return $postObject;
    }
}