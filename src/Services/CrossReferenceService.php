<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class CrossReferenceService
{
    /**
     * Analyze and map cross-references within PDF document
     */
    public function analyzeCrossReferences(PdfDocument $document): array
    {
        $sourceFile = $this->getSourceFilePath($document);
        
        $crossRefMap = [
            'internal_links' => [],
            'page_destinations' => [],
            'bookmarks' => [],
            'table_of_contents' => [],
            'cross_page_references' => [],
            'link_resolution_strategy' => 'preserve_with_mapping',
        ];

        try {
            $content = file_get_contents($sourceFile);
            
            // Extract cross-reference table
            $crossRefMap['internal_links'] = $this->extractInternalLinks($content);
            $crossRefMap['page_destinations'] = $this->extractPageDestinations($content);
            $crossRefMap['bookmarks'] = $this->extractBookmarks($content);
            $crossRefMap['table_of_contents'] = $this->extractTableOfContents($content);
            $crossRefMap['cross_page_references'] = $this->mapCrossPageReferences($crossRefMap);
            
            // Determine optimal link resolution strategy
            $crossRefMap['link_resolution_strategy'] = $this->determineLinkStrategy($crossRefMap, $document);
            
            // Cache the cross-reference map for reuse during extraction
            $this->cacheCrossReferenceMap($document->hash, $crossRefMap);
            
        } catch (\Exception $e) {
            Log::warning('Cross-reference analysis failed', [
                'document_hash' => $document->hash,
                'error' => $e->getMessage(),
            ]);
        }
        
        return $crossRefMap;
    }
    
    /**
     * Extract internal links from PDF content
     */
    protected function extractInternalLinks(string $content): array
    {
        $links = [];
        
        // Pattern for internal link annotations
        if (preg_match_all('/\/Link.*?\/Dest\s*\[(.*?)\]/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $destination = trim($match[1]);
                $links[] = [
                    'type' => 'internal_link',
                    'destination' => $destination,
                    'raw_match' => $match[0],
                ];
            }
        }
        
        // Pattern for GoTo actions
        if (preg_match_all('/\/A.*?\/S\s*\/GoTo.*?\/D\s*\[(.*?)\]/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $destination = trim($match[1]);
                $links[] = [
                    'type' => 'goto_action',
                    'destination' => $destination,
                    'raw_match' => $match[0],
                ];
            }
        }
        
        return $links;
    }
    
    /**
     * Extract page destinations and named destinations
     */
    protected function extractPageDestinations(string $content): array
    {
        $destinations = [];
        
        // Extract named destinations
        if (preg_match_all('/\/Dests.*?<<(.*?)>>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Parse destination dictionary
                if (preg_match_all('/\/(\w+)\s*\[(.*?)\]/s', $match[1], $destMatches, PREG_SET_ORDER)) {
                    foreach ($destMatches as $destMatch) {
                        $name = $destMatch[1];
                        $target = trim($destMatch[2]);
                        
                        // Extract page number if possible
                        if (preg_match('/(\d+)\s+\d+\s+R/', $target, $pageMatch)) {
                            $destinations[$name] = [
                                'page_reference' => $pageMatch[1],
                                'target' => $target,
                                'type' => 'named_destination',
                            ];
                        }
                    }
                }
            }
        }
        
        return $destinations;
    }
    
    /**
     * Extract bookmarks/outlines
     */
    protected function extractBookmarks(string $content): array
    {
        $bookmarks = [];
        
        // Extract outline/bookmark structure
        if (preg_match('/\/Outlines.*?<<(.*?)>>/s', $content, $match)) {
            $outlineContent = $match[1];
            
            // Parse bookmark entries
            if (preg_match_all('/\/Title\s*\((.*?)\).*?\/Dest\s*\[(.*?)\]/s', $outlineContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $title = trim($match[1]);
                    $destination = trim($match[2]);
                    
                    $bookmarks[] = [
                        'title' => $title,
                        'destination' => $destination,
                        'type' => 'bookmark',
                    ];
                }
            }
        }
        
        return $bookmarks;
    }
    
    /**
     * Extract table of contents structure
     */
    protected function extractTableOfContents(string $content): array
    {
        $toc = [];
        
        // TOC is often embedded in bookmarks or specific content streams
        // This is a simplified implementation - real PDFs may have complex TOC structures
        
        return $toc;
    }
    
    /**
     * Map cross-page references to determine which pages link to which
     */
    protected function mapCrossPageReferences(array $crossRefData): array
    {
        $crossPageMap = [];
        
        // Analyze internal links to determine page-to-page relationships
        foreach ($crossRefData['internal_links'] as $link) {
            if (isset($crossRefData['page_destinations'][$link['destination']])) {
                $targetPage = $crossRefData['page_destinations'][$link['destination']]['page_reference'];
                
                // This is a simplified mapping - real implementation would need more sophisticated parsing
                $crossPageMap[] = [
                    'link_destination' => $link['destination'],
                    'target_page_ref' => $targetPage,
                    'link_type' => $link['type'],
                ];
            }
        }
        
        return $crossPageMap;
    }
    
    /**
     * Determine optimal strategy for handling cross-references
     */
    protected function determineLinkStrategy(array $crossRefMap, PdfDocument $document): string
    {
        $linkCount = count($crossRefMap['internal_links']);
        $crossPageCount = count($crossRefMap['cross_page_references']);
        
        if ($crossPageCount === 0) {
            return 'no_cross_page_links';
        }
        
        if ($crossPageCount > $document->page_count * 0.5) {
            return 'heavily_linked_document';
        }
        
        if ($linkCount > 20) {
            return 'link_intensive_document';
        }
        
        return 'moderate_linking';
    }
    
    /**
     * Generate cross-reference resolution URLs for extracted pages
     */
    public function generateCrossReferenceUrls(string $documentHash, array $crossRefMap): array
    {
        $urlMap = [];
        
        foreach ($crossRefMap['cross_page_references'] as $crossRef) {
            $targetPageNumber = $this->extractPageNumberFromReference($crossRef['target_page_ref']);
            
            if ($targetPageNumber) {
                // Generate URL to target page in the viewer
                $urlMap[$crossRef['link_destination']] = [
                    'type' => 'page_navigation',
                    'target_page' => $targetPageNumber,
                    'viewer_url' => "/pdf-viewer/{$documentHash}/page/{$targetPageNumber}",
                    'api_url' => "/api/pdf-viewer/documents/{$documentHash}/pages/{$targetPageNumber}",
                ];
            }
        }
        
        return $urlMap;
    }
    
    /**
     * Apply cross-reference handling during page extraction
     */
    public function applyCrossReferenceHandling(string $documentHash, int $pageNumber, array &$extractionContext): void
    {
        $crossRefMap = $this->getCachedCrossReferenceMap($documentHash);
        
        if (!$crossRefMap) {
            $extractionContext['cross_references'] = 'not_analyzed';
            return;
        }
        
        $strategy = $crossRefMap['link_resolution_strategy'];
        $extractionContext['cross_reference_strategy'] = $strategy;
        
        switch ($strategy) {
            case 'heavily_linked_document':
                $extractionContext['preserve_internal_links'] = true;
                $extractionContext['include_link_map'] = true;
                $extractionContext['link_resolution_urls'] = $this->generateCrossReferenceUrls($documentHash, $crossRefMap);
                break;
                
            case 'link_intensive_document':
                $extractionContext['preserve_internal_links'] = config('pdf-viewer.page_extraction.preserve_internal_links', false);
                $extractionContext['link_resolution_urls'] = $this->generateCrossReferenceUrls($documentHash, $crossRefMap);
                break;
                
            case 'moderate_linking':
                $extractionContext['preserve_internal_links'] = config('pdf-viewer.page_extraction.preserve_internal_links', false);
                break;
                
            case 'no_cross_page_links':
            default:
                $extractionContext['preserve_internal_links'] = false;
                break;
        }
        
        // Find links originating from this specific page
        $pageLinks = $this->findLinksFromPage($crossRefMap, $pageNumber);
        if (!empty($pageLinks)) {
            $extractionContext['page_outbound_links'] = $pageLinks;
        }
        
        // Find links targeting this specific page
        $inboundLinks = $this->findLinksToPage($crossRefMap, $pageNumber);
        if (!empty($inboundLinks)) {
            $extractionContext['page_inbound_links'] = $inboundLinks;
        }
    }
    
    /**
     * Handle resource subsetting for extracted pages
     */
    public function handleResourceSubsetting(string $sourceFile, int $pageNumber, array &$extractionContext): array
    {
        $resourceMap = [
            'fonts' => [],
            'images' => [],
            'color_profiles' => [],
            'subsetting_strategy' => config('pdf-viewer.page_extraction.resource_strategy', 'smart_copy'),
        ];
        
        try {
            $content = file_get_contents($sourceFile);
            
            // Analyze fonts used on this page
            $resourceMap['fonts'] = $this->analyzeFontsForPage($content, $pageNumber);
            
            // Analyze images used on this page
            $resourceMap['images'] = $this->analyzeImagesForPage($content, $pageNumber);
            
            // Analyze color profiles
            $resourceMap['color_profiles'] = $this->analyzeColorProfilesForPage($content, $pageNumber);
            
            // Apply subsetting strategy
            $this->applyResourceSubsetting($resourceMap, $extractionContext);
            
        } catch (\Exception $e) {
            Log::warning('Resource subsetting analysis failed', [
                'source_file' => $sourceFile,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            
            $extractionContext['issues_detected'][] = 'resource_subsetting_failed';
        }
        
        return $resourceMap;
    }
    
    /**
     * Extract page number from PDF object reference
     */
    protected function extractPageNumberFromReference(string $pageRef): ?int
    {
        // Simple extraction - real implementation would need more sophisticated parsing
        if (preg_match('/(\d+)\s+\d+\s+R/', $pageRef, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }
    
    /**
     * Find links originating from specific page
     */
    protected function findLinksFromPage(array $crossRefMap, int $pageNumber): array
    {
        // This would require more sophisticated PDF parsing to determine
        // which links originate from which pages
        return [];
    }
    
    /**
     * Find links targeting specific page
     */
    protected function findLinksToPage(array $crossRefMap, int $pageNumber): array
    {
        $inboundLinks = [];
        
        foreach ($crossRefMap['cross_page_references'] as $crossRef) {
            $targetPageNumber = $this->extractPageNumberFromReference($crossRef['target_page_ref']);
            
            if ($targetPageNumber === $pageNumber) {
                $inboundLinks[] = $crossRef;
            }
        }
        
        return $inboundLinks;
    }
    
    /**
     * Analyze fonts used on specific page
     */
    protected function analyzeFontsForPage(string $content, int $pageNumber): array
    {
        $fonts = [];
        
        // Extract font references - simplified implementation
        if (preg_match_all('/\/Font.*?\/BaseFont\s*\/([^\s\/]+)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fontName = $match[1];
                $fonts[] = [
                    'name' => $fontName,
                    'type' => 'embedded',
                    'subset_required' => true,
                ];
            }
        }
        
        return array_unique($fonts, SORT_REGULAR);
    }
    
    /**
     * Analyze images used on specific page
     */
    protected function analyzeImagesForPage(string $content, int $pageNumber): array
    {
        $images = [];
        
        // Extract image references
        if (preg_match_all('/\/Image.*?\/Width\s*(\d+).*?\/Height\s*(\d+)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $images[] = [
                    'width' => (int) $match[1],
                    'height' => (int) $match[2],
                    'shared_resource' => true,
                ];
            }
        }
        
        return $images;
    }
    
    /**
     * Analyze color profiles for specific page
     */
    protected function analyzeColorProfilesForPage(string $content, int $pageNumber): array
    {
        $colorProfiles = [];
        
        // Extract color space references
        if (preg_match_all('/\/ColorSpace.*?\/(\w+)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $colorSpace = $match[1];
                $colorProfiles[] = [
                    'color_space' => $colorSpace,
                    'shared_resource' => true,
                ];
            }
        }
        
        return array_unique($colorProfiles, SORT_REGULAR);
    }
    
    /**
     * Apply resource subsetting strategy
     */
    protected function applyResourceSubsetting(array $resourceMap, array &$extractionContext): void
    {
        $strategy = $resourceMap['subsetting_strategy'];
        
        switch ($strategy) {
            case 'smart_copy':
                $extractionContext['font_subsetting'] = 'page_specific';
                $extractionContext['image_handling'] = 'reference_preserving';
                $extractionContext['color_profile_handling'] = 'minimal_required';
                break;
                
            case 'duplicate_all':
                $extractionContext['font_subsetting'] = 'full_font_set';
                $extractionContext['image_handling'] = 'duplicate_all';
                $extractionContext['color_profile_handling'] = 'preserve_all';
                break;
                
            case 'minimal':
                $extractionContext['font_subsetting'] = 'minimal';
                $extractionContext['image_handling'] = 'minimal';
                $extractionContext['color_profile_handling'] = 'minimal';
                break;
        }
        
        // Add resource metadata to context
        $extractionContext['resource_analysis'] = [
            'font_count' => count($resourceMap['fonts']),
            'image_count' => count($resourceMap['images']),
            'color_profile_count' => count($resourceMap['color_profiles']),
        ];
    }
    
    /**
     * Generate JavaScript for cross-reference navigation in viewer
     */
    public function generateCrossReferenceNavigation(string $documentHash, array $crossRefMap): string
    {
        $urlMap = $this->generateCrossReferenceUrls($documentHash, $crossRefMap);
        
        $jsCode = "// Cross-reference navigation for document {$documentHash}\n";
        $jsCode .= "window.pdfViewerCrossRefs = " . json_encode($urlMap, JSON_PRETTY_PRINT) . ";\n\n";
        
        $jsCode .= "
// Function to handle cross-reference clicks
function handleCrossReferenceClick(destination) {
    const crossRef = window.pdfViewerCrossRefs[destination];
    if (crossRef && crossRef.type === 'page_navigation') {
        // Navigate to target page in viewer
        window.location.href = crossRef.viewer_url;
        return true;
    }
    return false;
}

// Function to get cross-reference target
function getCrossReferenceTarget(destination) {
    const crossRef = window.pdfViewerCrossRefs[destination];
    return crossRef ? crossRef.target_page : null;
}
";
        
        return $jsCode;
    }
    
    /**
     * Cache cross-reference map for document
     */
    protected function cacheCrossReferenceMap(string $documentHash, array $crossRefMap): void
    {
        $cacheKey = "pdf_viewer:cross_refs:{$documentHash}";
        $ttl = config('pdf-viewer.cache.ttl.document_metadata', 3600);
        
        Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->put($cacheKey, $crossRefMap, $ttl);
    }
    
    /**
     * Get cached cross-reference map
     */
    public function getCachedCrossReferenceMap(string $documentHash): ?array
    {
        $cacheKey = "pdf_viewer:cross_refs:{$documentHash}";
        
        return Cache::store(config('pdf-viewer.cache.store', 'redis'))
            ->get($cacheKey);
    }
    
    /**
     * Get source file path for analysis
     */
    protected function getSourceFilePath(PdfDocument $document): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
        
        // For S3, download to temp location
        if ($this->isS3Disk($disk)) {
            $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
            $tempFile = $tempDir . '/crossref_analysis_' . $document->hash . '.pdf';
            
            if (!file_exists($tempFile)) {
                $content = $disk->get($document->file_path);
                file_put_contents($tempFile, $content);
            }
            
            return $tempFile;
        }
        
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
     * Clean up temporary analysis files
     */
    public function cleanupAnalysisFiles(PdfDocument $document): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('pdf-viewer.storage.disk'));
        
        if ($this->isS3Disk($disk)) {
            $tempDir = config('pdf-viewer.vapor.temp_directory', '/tmp');
            $tempFile = $tempDir . '/crossref_analysis_' . $document->hash . '.pdf';
            
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}