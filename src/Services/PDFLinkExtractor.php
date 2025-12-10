<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

class PDFLinkExtractor
{
    protected Parser $parser;

    public const LINK_TYPE_INTERNAL = 'internal';
    public const LINK_TYPE_EXTERNAL = 'external';
    public const LINK_TYPE_UNKNOWN = 'unknown';

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Extract all links from a PDF file
     *
     * @param string $filePath Absolute path to the PDF file
     * @return array Array of links grouped by page number
     */
    public function extract(string $filePath): array
    {
        try {
            $document = $this->parser->parseFile($filePath);
            return $this->extractLinksFromDocument($document);
        } catch (\Exception $e) {
            Log::warning('Failed to extract PDF links', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract links for a specific page
     *
     * @param string $filePath Absolute path to the PDF file
     * @param int $pageNumber Page number (1-indexed)
     * @return array Array of links for the specified page
     */
    public function extractForPage(string $filePath, int $pageNumber): array
    {
        try {
            $document = $this->parser->parseFile($filePath);
            $pages = $document->getPages();

            if (!isset($pages[$pageNumber - 1])) {
                return [];
            }

            return $this->extractLinksFromPage($document, $pages[$pageNumber - 1], $pageNumber);
        } catch (\Exception $e) {
            Log::warning('Failed to extract page links', [
                'file_path' => $filePath,
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract links from all pages of a document
     */
    protected function extractLinksFromDocument(Document $document): array
    {
        $allLinks = [];
        $pages = $document->getPages();
        $pageNumber = 1;

        foreach ($pages as $page) {
            $pageLinks = $this->extractLinksFromPage($document, $page, $pageNumber);
            if (!empty($pageLinks)) {
                $allLinks[$pageNumber] = $pageLinks;
            }
            $pageNumber++;
        }

        return $allLinks;
    }

    /**
     * Extract links from a single page
     */
    protected function extractLinksFromPage(Document $document, Page $page, int $pageNumber): array
    {
        $links = [];

        try {
            // Get page dimensions for coordinate conversion
            $pageDimensions = $this->getPageDimensions($page);

            // Get annotations from page
            $annotations = $this->getPageAnnotations($document, $page);

            foreach ($annotations as $annotation) {
                $link = $this->parseAnnotation($document, $annotation, $pageDimensions, $pageNumber);
                if ($link) {
                    $links[] = $link;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract links from page', [
                'page_number' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
        }

        return $links;
    }

    /**
     * Get page dimensions
     */
    protected function getPageDimensions(Page $page): array
    {
        $details = $page->getDetails();

        // Default dimensions (letter size in points)
        $width = 612;
        $height = 792;

        if (isset($details['MediaBox'])) {
            $mediaBox = $details['MediaBox'];
            if (is_array($mediaBox) && count($mediaBox) >= 4) {
                $width = abs($mediaBox[2] - $mediaBox[0]);
                $height = abs($mediaBox[3] - $mediaBox[1]);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Get annotations from a page
     */
    protected function getPageAnnotations(Document $document, Page $page): array
    {
        $annotations = [];

        try {
            $details = $page->getDetails();

            if (!isset($details['Annots'])) {
                return [];
            }

            $annotRefs = $details['Annots'];

            // Handle different formats
            if (is_string($annotRefs)) {
                // Parse array of references
                preg_match_all('/(\d+)\s+(\d+)\s+R/', $annotRefs, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $objectId = $match[1] . '_' . $match[2];
                    $obj = $document->getObjectById($objectId);
                    if ($obj) {
                        $annotations[] = $obj;
                    }
                }
            } elseif (is_array($annotRefs)) {
                foreach ($annotRefs as $ref) {
                    $objectId = $this->extractObjectId($ref);
                    if ($objectId) {
                        $obj = $document->getObjectById($objectId);
                        if ($obj) {
                            $annotations[] = $obj;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Failed to get page annotations', ['error' => $e->getMessage()]);
        }

        return $annotations;
    }

    /**
     * Parse a single annotation object
     */
    protected function parseAnnotation(Document $document, PDFObject $annotation, array $pageDimensions, int $sourcePageNumber): ?array
    {
        try {
            $header = $annotation->getHeader();

            // Check if this is a Link annotation
            if (!$header->has('Subtype')) {
                return null;
            }

            $subtype = $header->get('Subtype');
            $subtypeValue = $this->extractStringValue($subtype);

            if ($subtypeValue !== 'Link') {
                return null;
            }

            // Get the rectangle (coordinates)
            $rect = $this->extractRect($header, $pageDimensions);
            if (!$rect) {
                return null;
            }

            // Determine link type and destination
            $linkInfo = $this->extractLinkDestination($document, $header);

            return [
                'source_page' => $sourcePageNumber,
                'type' => $linkInfo['type'],
                'destination_page' => $linkInfo['destination_page'],
                'destination_url' => $linkInfo['destination_url'],
                'coordinates' => [
                    'x' => $rect['x'],
                    'y' => $rect['y'],
                    'width' => $rect['width'],
                    'height' => $rect['height'],
                ],
                'normalized_coordinates' => [
                    'x_percent' => round($rect['x'] / $pageDimensions['width'] * 100, 4),
                    'y_percent' => round($rect['y'] / $pageDimensions['height'] * 100, 4),
                    'width_percent' => round($rect['width'] / $pageDimensions['width'] * 100, 4),
                    'height_percent' => round($rect['height'] / $pageDimensions['height'] * 100, 4),
                ],
            ];
        } catch (\Exception $e) {
            Log::debug('Failed to parse annotation', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract rectangle coordinates from annotation
     */
    protected function extractRect($header, array $pageDimensions): ?array
    {
        if (!$header->has('Rect')) {
            return null;
        }

        $rect = $header->get('Rect');
        $rectValues = $this->parseRectValues($rect);

        if (count($rectValues) < 4) {
            return null;
        }

        // PDF coordinates are from bottom-left, convert to top-left for web
        $x1 = floatval($rectValues[0]);
        $y1 = floatval($rectValues[1]);
        $x2 = floatval($rectValues[2]);
        $y2 = floatval($rectValues[3]);

        $x = min($x1, $x2);
        $width = abs($x2 - $x1);
        $height = abs($y2 - $y1);

        // Convert Y coordinate from bottom-left to top-left origin
        $y = $pageDimensions['height'] - max($y1, $y2);

        return [
            'x' => max(0, $x),
            'y' => max(0, $y),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Parse rectangle values from various formats
     */
    protected function parseRectValues($rect): array
    {
        if (is_array($rect)) {
            return array_map('floatval', $rect);
        }

        if (is_string($rect)) {
            // Parse format like "[0 0 100 50]" or "0 0 100 50"
            $rect = trim($rect, '[]');
            preg_match_all('/[-+]?[\d.]+/', $rect, $matches);
            return array_map('floatval', $matches[0] ?? []);
        }

        if (is_object($rect) && method_exists($rect, 'getContent')) {
            $content = $rect->getContent();
            return $this->parseRectValues($content);
        }

        return [];
    }

    /**
     * Extract link destination information
     */
    protected function extractLinkDestination(Document $document, $header): array
    {
        $result = [
            'type' => self::LINK_TYPE_UNKNOWN,
            'destination_page' => null,
            'destination_url' => null,
        ];

        // Check for direct destination
        if ($header->has('Dest')) {
            $dest = $header->get('Dest');
            $pageNum = $this->extractDestinationPage($document, $dest);
            if ($pageNum !== null) {
                $result['type'] = self::LINK_TYPE_INTERNAL;
                $result['destination_page'] = $pageNum;
                return $result;
            }
        }

        // Check for action
        if ($header->has('A')) {
            $action = $header->get('A');
            return $this->parseAction($document, $action);
        }

        return $result;
    }

    /**
     * Parse action object to determine link type and destination
     */
    protected function parseAction(Document $document, $action): array
    {
        $result = [
            'type' => self::LINK_TYPE_UNKNOWN,
            'destination_page' => null,
            'destination_url' => null,
        ];

        try {
            $actionHeader = null;

            if (is_object($action) && method_exists($action, 'getHeader')) {
                $actionHeader = $action->getHeader();
            } elseif (is_string($action)) {
                // May be a reference
                $objectId = $this->extractObjectId($action);
                if ($objectId) {
                    $actionObj = $document->getObjectById($objectId);
                    if ($actionObj) {
                        $actionHeader = $actionObj->getHeader();
                    }
                }
            }

            if (!$actionHeader) {
                return $result;
            }

            // Check action type
            $actionType = '';
            if ($actionHeader->has('S')) {
                $actionType = $this->extractStringValue($actionHeader->get('S'));
            }

            switch ($actionType) {
                case 'GoTo':
                    // Internal link
                    if ($actionHeader->has('D')) {
                        $dest = $actionHeader->get('D');
                        $pageNum = $this->extractDestinationPage($document, $dest);
                        if ($pageNum !== null) {
                            $result['type'] = self::LINK_TYPE_INTERNAL;
                            $result['destination_page'] = $pageNum;
                        }
                    }
                    break;

                case 'URI':
                    // External URL
                    if ($actionHeader->has('URI')) {
                        $uri = $this->extractStringValue($actionHeader->get('URI'));
                        $result['type'] = self::LINK_TYPE_EXTERNAL;
                        $result['destination_url'] = $uri;
                    }
                    break;

                case 'GoToR':
                    // Link to another PDF file
                    $result['type'] = self::LINK_TYPE_EXTERNAL;
                    if ($actionHeader->has('F')) {
                        $result['destination_url'] = $this->extractStringValue($actionHeader->get('F'));
                    }
                    break;

                case 'Launch':
                    // Launch action (external application/file)
                    $result['type'] = self::LINK_TYPE_EXTERNAL;
                    if ($actionHeader->has('F')) {
                        $result['destination_url'] = $this->extractStringValue($actionHeader->get('F'));
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::debug('Failed to parse action', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Extract page number from destination
     */
    protected function extractDestinationPage(Document $document, $dest): ?int
    {
        try {
            $destContent = '';

            if (is_string($dest)) {
                $destContent = $dest;
            } elseif (is_object($dest) && method_exists($dest, 'getContent')) {
                $destContent = $dest->getContent();
            } elseif (is_array($dest)) {
                // First element should be page reference
                if (!empty($dest)) {
                    $firstElement = reset($dest);
                    $objectId = $this->extractObjectId($firstElement);
                    if ($objectId) {
                        return $this->getPageNumberFromObject($document, $objectId);
                    }
                }
            }

            // Parse format like "[5 0 R /XYZ 0 0 0]"
            if (preg_match('/(\d+)\s+\d+\s+R/', $destContent, $matches)) {
                $pageObjId = $matches[1] . '_0';
                return $this->getPageNumberFromObject($document, $pageObjId);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract destination page', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get page number from page object ID
     */
    protected function getPageNumberFromObject(Document $document, string $objectId): ?int
    {
        try {
            $pages = $document->getPages();
            $pageNumber = 1;

            // First try: check if object ID matches directly
            $targetObj = $document->getObjectById($objectId);

            foreach ($pages as $page) {
                // The page object should match our target
                if ($targetObj && $page === $targetObj) {
                    return $pageNumber;
                }
                $pageNumber++;
            }

            // Second try: extract page number from object ID
            // Object IDs often correspond to page positions
            $parts = explode('_', $objectId);
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                $objNum = intval($parts[0]);
                // This is a heuristic - in many PDFs, early object numbers correspond to pages
                if ($objNum > 0 && $objNum <= count($pages) * 2) {
                    // Try to find a matching page
                    $pageNumber = 1;
                    foreach ($pages as $page) {
                        $details = $page->getDetails();
                        if (isset($details['_object_id']) && $details['_object_id'] === $objectId) {
                            return $pageNumber;
                        }
                        $pageNumber++;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Failed to get page number from object', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract object ID from reference
     */
    protected function extractObjectId($ref): ?string
    {
        if (is_string($ref)) {
            if (preg_match('/(\d+)\s+(\d+)\s+R/', $ref, $matches)) {
                return $matches[1] . '_' . $matches[2];
            }
            return $ref;
        }

        if (is_object($ref)) {
            if (method_exists($ref, 'getContent')) {
                $content = $ref->getContent();
                if (preg_match('/(\d+)\s+(\d+)\s+R/', $content, $matches)) {
                    return $matches[1] . '_' . $matches[2];
                }
                return $content;
            }
        }

        return null;
    }

    /**
     * Extract string value from PDF element
     */
    protected function extractStringValue($element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if (is_object($element)) {
            if (method_exists($element, 'getContent')) {
                return $element->getContent();
            }
            if (method_exists($element, '__toString')) {
                return (string) $element;
            }
        }

        return '';
    }

    /**
     * Get only internal links
     */
    public function getInternalLinks(array $allLinks): array
    {
        $internalLinks = [];

        foreach ($allLinks as $pageNum => $links) {
            $pageInternalLinks = array_filter($links, fn($link) => $link['type'] === self::LINK_TYPE_INTERNAL);
            if (!empty($pageInternalLinks)) {
                $internalLinks[$pageNum] = array_values($pageInternalLinks);
            }
        }

        return $internalLinks;
    }

    /**
     * Get only external links
     */
    public function getExternalLinks(array $allLinks): array
    {
        $externalLinks = [];

        foreach ($allLinks as $pageNum => $links) {
            $pageExternalLinks = array_filter($links, fn($link) => $link['type'] === self::LINK_TYPE_EXTERNAL);
            if (!empty($pageExternalLinks)) {
                $externalLinks[$pageNum] = array_values($pageExternalLinks);
            }
        }

        return $externalLinks;
    }

    /**
     * Flatten all links to a simple array
     */
    public function flatten(array $allLinks): array
    {
        $flat = [];

        foreach ($allLinks as $pageNum => $links) {
            foreach ($links as $link) {
                $flat[] = $link;
            }
        }

        return $flat;
    }
}
