<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface;
use Shakewellagency\LaravelPdfViewer\Services\CrossReferenceService;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $documentService = app(DocumentServiceInterface::class);
        $crossRefService = app(CrossReferenceService::class);
        
        return [
            'id' => $this->id,
            'page_number' => $this->page_number,
            'content' => $this->content,
            'content_length' => $this->content_length,
            'word_count' => $this->word_count,
            'has_content' => $this->hasContent(),
            'has_thumbnail' => $this->hasThumbnail(),
            'status' => $this->status,
            'is_parsed' => $this->is_parsed,
            'processing_error' => $this->when(
                $this->status === 'failed',
                $this->processing_error
            ),
            'metadata' => $this->metadata,
            'document' => $this->when(
                $this->relationLoaded('document'),
                new DocumentResource($this->document)
            ),
            'page_file_url' => $this->when(
                $this->page_file_path,
                fn() => $documentService->getSignedUrl($this->page_file_path, 1800)
            ),
            'thumbnail_url' => $this->when(
                $this->hasThumbnail(),
                fn() => $documentService->getSignedUrl($this->thumbnail_path, 3600)
            ),
            'download_url' => route('pdf-viewer.documents.pages.download', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]),
            'cross_references' => $this->when(
                config('pdf-viewer.page_extraction.preserve_internal_links', false),
                function () use ($crossRefService) {
                    // Get cross-reference data for this page
                    $crossRefMap = $crossRefService->getCachedCrossReferenceMap($this->document->hash);
                    if ($crossRefMap) {
                        return [
                            'outbound_links' => $this->metadata['extraction']['page_outbound_links'] ?? [],
                            'inbound_links' => $this->metadata['extraction']['page_inbound_links'] ?? [],
                            'navigation_script' => route('pdf-viewer.documents.cross-ref-script', [
                                'document_hash' => $this->document->hash,
                            ]),
                        ];
                    }
                    return null;
                }
            ),
            'extraction_context' => $this->when(
                !empty($this->metadata['extraction']),
                $this->metadata['extraction']
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}