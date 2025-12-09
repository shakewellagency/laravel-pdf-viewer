<?php

namespace Shakewellagency\LaravelPdfViewer\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\PDFObject;

class PDFOutlineExtractor
{
    protected Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Extract TOC/Outline from a PDF file
     *
     * @param string $filePath Absolute path to the PDF file
     * @return array Hierarchical array of outline entries
     */
    public function extract(string $filePath): array
    {
        try {
            $document = $this->parser->parseFile($filePath);
            return $this->extractOutlineFromDocument($document);
        } catch (\Exception $e) {
            Log::warning('Failed to extract PDF outline', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract outline from parsed PDF document
     */
    protected function extractOutlineFromDocument(Document $document): array
    {
        try {
            // Get the document catalog
            $objects = $document->getObjects();
            $catalog = null;
            $outlinesRef = null;

            // Find the Catalog object which contains the Outlines reference
            foreach ($objects as $object) {
                if ($object instanceof PDFObject) {
                    $header = $object->getHeader();
                    if ($header->has('Type')) {
                        $type = $header->get('Type');
                        if ($type && method_exists($type, 'getContent') && $type->getContent() === 'Catalog') {
                            $catalog = $object;
                            if ($header->has('Outlines')) {
                                $outlinesRef = $header->get('Outlines');
                            }
                            break;
                        }
                    }
                }
            }

            if (!$outlinesRef) {
                Log::info('PDF has no outline/bookmarks');
                return [];
            }

            // Get the Outlines object
            $outlinesId = $this->extractObjectId($outlinesRef);
            if (!$outlinesId) {
                return [];
            }

            $outlinesObject = $document->getObjectById($outlinesId);
            if (!$outlinesObject) {
                return [];
            }

            // Parse the outline tree
            return $this->parseOutlineTree($document, $outlinesObject);

        } catch (\Exception $e) {
            Log::warning('Failed to parse PDF outline structure', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse the outline tree starting from the Outlines object
     */
    protected function parseOutlineTree(Document $document, PDFObject $outlinesObject): array
    {
        $outline = [];
        $header = $outlinesObject->getHeader();

        // Get the first child
        if (!$header->has('First')) {
            return [];
        }

        $firstRef = $header->get('First');
        $firstId = $this->extractObjectId($firstRef);

        if (!$firstId) {
            return [];
        }

        $firstItem = $document->getObjectById($firstId);
        if (!$firstItem) {
            return [];
        }

        // Traverse all siblings at this level
        $outline = $this->traverseOutlineItems($document, $firstItem, 0);

        return $outline;
    }

    /**
     * Traverse outline items recursively
     */
    protected function traverseOutlineItems(Document $document, PDFObject $item, int $level): array
    {
        $items = [];
        $current = $item;
        $maxIterations = 10000; // Prevent infinite loops
        $iteration = 0;

        while ($current !== null && $iteration < $maxIterations) {
            $iteration++;
            $header = $current->getHeader();

            // Extract item data
            $entry = $this->extractOutlineEntry($document, $current, $level);
            if ($entry) {
                // Check for children
                if ($header->has('First')) {
                    $firstChildRef = $header->get('First');
                    $firstChildId = $this->extractObjectId($firstChildRef);
                    if ($firstChildId) {
                        $firstChild = $document->getObjectById($firstChildId);
                        if ($firstChild) {
                            $entry['children'] = $this->traverseOutlineItems($document, $firstChild, $level + 1);
                        }
                    }
                }
                $items[] = $entry;
            }

            // Move to next sibling
            if ($header->has('Next')) {
                $nextRef = $header->get('Next');
                $nextId = $this->extractObjectId($nextRef);
                if ($nextId) {
                    $current = $document->getObjectById($nextId);
                } else {
                    $current = null;
                }
            } else {
                $current = null;
            }
        }

        return $items;
    }

    /**
     * Extract data from a single outline entry
     */
    protected function extractOutlineEntry(Document $document, PDFObject $item, int $level): ?array
    {
        $header = $item->getHeader();

        // Get title
        $title = '';
        if ($header->has('Title')) {
            $titleElement = $header->get('Title');
            if ($titleElement) {
                $title = $this->extractStringValue($titleElement);
            }
        }

        if (empty($title)) {
            return null;
        }

        // Get destination page
        $destinationPage = null;

        // Try to get destination from 'Dest' key
        if ($header->has('Dest')) {
            $dest = $header->get('Dest');
            $destinationPage = $this->extractDestinationPage($document, $dest);
        }

        // Try to get destination from 'A' (Action) key
        if ($destinationPage === null && $header->has('A')) {
            $action = $header->get('A');
            $destinationPage = $this->extractActionDestination($document, $action);
        }

        return [
            'title' => $this->cleanTitle($title),
            'level' => $level,
            'destination_page' => $destinationPage,
            'children' => [],
        ];
    }

    /**
     * Extract page number from destination
     */
    protected function extractDestinationPage(Document $document, $dest): ?int
    {
        try {
            if (is_array($dest) || (is_object($dest) && method_exists($dest, 'getContent'))) {
                $content = is_array($dest) ? $dest : null;

                if (is_object($dest) && method_exists($dest, 'getContent')) {
                    $rawContent = $dest->getContent();
                    // Parse array format like [5 0 R /XYZ 0 0 0]
                    if (preg_match('/(\d+)\s+\d+\s+R/', $rawContent, $matches)) {
                        $pageObjId = $matches[1] . '_0';
                        return $this->getPageNumberFromObject($document, $pageObjId);
                    }
                }

                if (is_array($content) && !empty($content)) {
                    $firstElement = reset($content);
                    if (is_object($firstElement)) {
                        $pageObjId = $this->extractObjectId($firstElement);
                        if ($pageObjId) {
                            return $this->getPageNumberFromObject($document, $pageObjId);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract destination page', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract destination from Action object
     */
    protected function extractActionDestination(Document $document, $action): ?int
    {
        try {
            if (is_object($action) && method_exists($action, 'getHeader')) {
                $actionHeader = $action->getHeader();
                if ($actionHeader->has('D')) {
                    return $this->extractDestinationPage($document, $actionHeader->get('D'));
                }
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract action destination', ['error' => $e->getMessage()]);
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

            foreach ($pages as $page) {
                // Try to match the page object ID
                $pageDetails = $page->getDetails();
                if (isset($pageDetails['id']) && $pageDetails['id'] === $objectId) {
                    return $pageNumber;
                }
                $pageNumber++;
            }

            // Fallback: try to extract page number from object structure
            $pageObj = $document->getObjectById($objectId);
            if ($pageObj) {
                // Count position in pages array
                $pageNumber = 1;
                foreach ($pages as $page) {
                    if ($page === $pageObj) {
                        return $pageNumber;
                    }
                    $pageNumber++;
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
            // Format: "5 0 R" -> "5_0"
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
            if (method_exists($ref, '__toString')) {
                return (string) $ref;
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
     * Clean and normalize title string
     */
    protected function cleanTitle(string $title): string
    {
        // Remove PDF escape sequences
        $title = preg_replace('/\\\\([0-7]{3})/', '', $title);

        // Decode UTF-16BE if present
        if (substr($title, 0, 2) === "\xFE\xFF") {
            $title = mb_convert_encoding(substr($title, 2), 'UTF-8', 'UTF-16BE');
        }

        // Remove control characters
        $title = preg_replace('/[\x00-\x1F\x7F]/', '', $title);

        // Normalize whitespace
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    /**
     * Flatten the hierarchical outline to a simple list with level info
     */
    public function flatten(array $outline, int $level = 0): array
    {
        $flat = [];

        foreach ($outline as $item) {
            $entry = [
                'title' => $item['title'],
                'level' => $level,
                'destination_page' => $item['destination_page'],
            ];
            $flat[] = $entry;

            if (!empty($item['children'])) {
                $flat = array_merge($flat, $this->flatten($item['children'], $level + 1));
            }
        }

        return $flat;
    }
}
