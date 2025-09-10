<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class FileNamingService
{
    /**
     * Generate robust file name for extracted page
     */
    public function generatePageFileName(
        PdfDocument $document, 
        int $pageNumber, 
        array $extractionContext = []
    ): string {
        $strategy = config('pdf-viewer.file_naming.strategy', 'hierarchical');
        $includeTimestamp = config('pdf-viewer.file_naming.include_timestamp', true);
        $includeChecksum = config('pdf-viewer.file_naming.include_checksum', true);
        
        switch ($strategy) {
            case 'hierarchical':
                return $this->generateHierarchicalName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
                
            case 'flat':
                return $this->generateFlatName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
                
            case 'hybrid':
                return $this->generateHybridName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
                
            default:
                return $this->generateHierarchicalName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
        }
    }
    
    /**
     * Generate hierarchical file name (folder structure)
     */
    protected function generateHierarchicalName(
        PdfDocument $document, 
        int $pageNumber, 
        array $extractionContext, 
        bool $includeTimestamp, 
        bool $includeChecksum
    ): string {
        $parts = [];
        
        // Base name from original filename
        $baseName = $this->sanitizeFilename(pathinfo($document->original_filename, PATHINFO_FILENAME));
        $parts[] = $baseName;
        
        // Page number with zero padding
        $paddedPageNumber = str_pad($pageNumber, 4, '0', STR_PAD_LEFT);
        $parts[] = "page_{$paddedPageNumber}";
        
        // Add extraction method if not standard
        if (isset($extractionContext['method']) && $extractionContext['method'] !== 'standard') {
            $parts[] = $extractionContext['method'];
        }
        
        // Add timestamp if enabled
        if ($includeTimestamp) {
            $parts[] = now()->format('Ymd_His');
        }
        
        // Add checksum if enabled
        if ($includeChecksum) {
            $parts[] = substr($document->hash, 0, 8);
        }
        
        $filename = implode('_', $parts) . '.pdf';
        
        return $this->validateAndTruncateFilename($filename);
    }
    
    /**
     * Generate flat file name (single directory)
     */
    protected function generateFlatName(
        PdfDocument $document, 
        int $pageNumber, 
        array $extractionContext, 
        bool $includeTimestamp, 
        bool $includeChecksum
    ): string {
        $parts = [];
        
        // Document identifier
        $parts[] = substr($document->hash, 0, 12);
        
        // Page number
        $parts[] = "p{$pageNumber}";
        
        // Extraction context
        if (isset($extractionContext['extraction_strategy']) && $extractionContext['extraction_strategy'] !== 'standard') {
            $parts[] = substr($extractionContext['extraction_strategy'], 0, 4);
        }
        
        // Timestamp
        if ($includeTimestamp) {
            $parts[] = now()->format('YmdHis');
        }
        
        $filename = implode('-', $parts) . '.pdf';
        
        return $this->validateAndTruncateFilename($filename);
    }
    
    /**
     * Generate hybrid file name (combines hierarchical and flat approaches)
     */
    protected function generateHybridName(
        PdfDocument $document, 
        int $pageNumber, 
        array $extractionContext, 
        bool $includeTimestamp, 
        bool $includeChecksum
    ): string {
        // Use hierarchical for simple cases, flat for complex edge cases
        $edgeCaseCount = count($extractionContext['edge_cases_detected'] ?? []);
        
        if ($edgeCaseCount > 2) {
            return $this->generateFlatName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
        } else {
            return $this->generateHierarchicalName($document, $pageNumber, $extractionContext, $includeTimestamp, $includeChecksum);
        }
    }
    
    /**
     * Sanitize filename to remove forbidden characters
     */
    protected function sanitizeFilename(string $filename): string
    {
        $forbiddenChars = config('pdf-viewer.file_naming.forbidden_chars', '<>:"/\\|?*');
        $forbiddenArray = str_split($forbiddenChars);
        
        // Replace forbidden characters with underscores
        $sanitized = str_replace($forbiddenArray, '_', $filename);
        
        // Remove multiple consecutive underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        
        // Remove leading/trailing underscores
        $sanitized = trim($sanitized, '_');
        
        // Handle case conversion
        $caseHandling = config('pdf-viewer.file_naming.case_handling', 'preserve');
        switch ($caseHandling) {
            case 'lower':
                $sanitized = strtolower($sanitized);
                break;
            case 'upper':
                $sanitized = strtoupper($sanitized);
                break;
            case 'preserve':
            default:
                // Keep original case
                break;
        }
        
        return $sanitized;
    }
    
    /**
     * Validate and truncate filename to respect length limits
     */
    protected function validateAndTruncateFilename(string $filename): string
    {
        $maxLength = config('pdf-viewer.file_naming.max_filename_length', 200);
        
        if (strlen($filename) <= $maxLength) {
            return $filename;
        }
        
        // Truncate while preserving file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        $maxNameLength = $maxLength - strlen($extension) - 1; // -1 for the dot
        $truncatedName = substr($nameWithoutExt, 0, $maxNameLength);
        
        // Remove any trailing underscores or hyphens from truncation
        $truncatedName = rtrim($truncatedName, '_-');
        
        return $truncatedName . '.' . $extension;
    }
    
    /**
     * Generate thumbnail filename based on page filename
     */
    public function generateThumbnailFileName(string $pageFileName): string
    {
        $baseName = pathinfo($pageFileName, PATHINFO_FILENAME);
        $format = config('pdf-viewer.thumbnails.format', 'jpg');
        
        return $baseName . '_thumb.' . $format;
    }
    
    /**
     * Generate unique filename to avoid conflicts
     */
    public function generateUniqueFileName(string $baseFileName, string $directory): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
        $fullPath = $directory . '/' . $baseFileName;
        
        // If file doesn't exist, use the base name
        if (!$disk->exists($fullPath)) {
            return $baseFileName;
        }
        
        // Generate unique variant
        $pathInfo = pathinfo($baseFileName);
        $name = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';
        
        $counter = 1;
        do {
            $uniqueName = $name . '_' . $counter;
            if (!empty($extension)) {
                $uniqueName .= '.' . $extension;
            }
            
            $uniquePath = $directory . '/' . $uniqueName;
            $counter++;
            
        } while ($disk->exists($uniquePath) && $counter < 1000);
        
        if ($counter >= 1000) {
            // Fallback to timestamp-based unique name
            $timestamp = now()->format('YmdHis') . '_' . uniqid();
            $uniqueName = $name . '_' . $timestamp;
            if (!empty($extension)) {
                $uniqueName .= '.' . $extension;
            }
        }
        
        return $uniqueName;
    }
    
    /**
     * Validate filename against security and compliance requirements
     */
    public function validateFileName(string $filename): array
    {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ];
        
        // Check length
        $maxLength = config('pdf-viewer.file_naming.max_filename_length', 200);
        if (strlen($filename) > $maxLength) {
            $validation['errors'][] = "Filename exceeds maximum length of {$maxLength} characters";
            $validation['valid'] = false;
        }
        
        // Check for forbidden characters
        $forbiddenChars = config('pdf-viewer.file_naming.forbidden_chars', '<>:"/\\|?*');
        $forbiddenArray = str_split($forbiddenChars);
        
        foreach ($forbiddenArray as $char) {
            if (strpos($filename, $char) !== false) {
                $validation['errors'][] = "Filename contains forbidden character: {$char}";
                $validation['valid'] = false;
            }
        }
        
        // Check for potentially problematic patterns
        $problematicPatterns = [
            '/\.\./' => 'path traversal attempt',
            '/^\./' => 'hidden file pattern',
            '/\s{2,}/' => 'excessive whitespace',
            '/[^\x20-\x7E]/' => 'non-ASCII characters',
        ];
        
        foreach ($problematicPatterns as $pattern => $issue) {
            if (preg_match($pattern, $filename)) {
                $validation['warnings'][] = "Potentially problematic pattern detected: {$issue}";
            }
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $validation['warnings'][] = "Unusual file extension: {$extension}";
        }
        
        return $validation;
    }
    
    /**
     * Generate directory structure for organized file storage
     */
    public function generateDirectoryStructure(PdfDocument $document, array $extractionContext = []): string
    {
        $strategy = config('pdf-viewer.file_naming.strategy', 'hierarchical');
        
        switch ($strategy) {
            case 'hierarchical':
                return $this->generateHierarchicalDirectory($document, $extractionContext);
                
            case 'flat':
                return $this->generateFlatDirectory($document);
                
            case 'hybrid':
                return $this->generateHybridDirectory($document, $extractionContext);
                
            default:
                return $this->generateHierarchicalDirectory($document, $extractionContext);
        }
    }
    
    /**
     * Generate hierarchical directory structure
     */
    protected function generateHierarchicalDirectory(PdfDocument $document, array $extractionContext): string
    {
        $parts = [config('pdf-viewer.storage.pages_path')];
        
        // Group by year/month for long-term organization
        $parts[] = $document->created_at->format('Y');
        $parts[] = $document->created_at->format('m');
        
        // Document hash for uniqueness
        $parts[] = $document->hash;
        
        // Special handling for edge cases
        if (!empty($extractionContext['edge_cases_detected'])) {
            $parts[] = 'special_handling';
        }
        
        return implode('/', $parts);
    }
    
    /**
     * Generate flat directory structure
     */
    protected function generateFlatDirectory(PdfDocument $document): string
    {
        return config('pdf-viewer.storage.pages_path') . '/' . $document->hash;
    }
    
    /**
     * Generate hybrid directory structure
     */
    protected function generateHybridDirectory(PdfDocument $document, array $extractionContext): string
    {
        $hasEdgeCases = !empty($extractionContext['edge_cases_detected']);
        
        if ($hasEdgeCases) {
            return $this->generateHierarchicalDirectory($document, $extractionContext);
        } else {
            return $this->generateFlatDirectory($document);
        }
    }
}