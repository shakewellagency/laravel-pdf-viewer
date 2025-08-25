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
        
        $data = [
            'id' => $this->id,
            'page_number' => $this->page_number,
            'content' => $this->content,
            'content_length' => $this->content_length,
            'word_count' => $this->word_count,
            'has_content' => $this->hasContent(),
            'has_thumbnail' => $this->hasThumbnail(),
            'status' => $this->status,
            'is_parsed' => $this->is_parsed,
            'metadata' => $this->metadata,
            'download_url' => $this->document ? route('pdf-viewer.documents.pages.download', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]) : null,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];

        if ($this->status === 'failed') {
            $data['processing_error'] = $this->processing_error;
        }

        if ($this->relationLoaded('document')) {
            $data['document'] = new DocumentResource($this->document);
        }

        if ($this->page_file_path) {
            $data['page_file_url'] = $documentService->getSignedUrl($this->page_file_path, 1800);
        }

        if ($this->hasThumbnail() && $this->thumbnail_path) {
            $data['thumbnail_url'] = $documentService->getSignedUrl($this->thumbnail_path, 3600);
        }

        if (config('pdf-viewer.page_extraction.preserve_internal_links', false) && $this->document) {
            $crossRefMap = $crossRefService->getCachedCrossReferenceMap($this->document->hash);
            if ($crossRefMap) {
                $data['cross_references'] = [
                    'outbound_links' => $this->metadata['extraction']['page_outbound_links'] ?? [],
                    'inbound_links' => $this->metadata['extraction']['page_inbound_links'] ?? [],
                    'navigation_script' => route('pdf-viewer.documents.cross-ref-script', [
                        'document_hash' => $this->document->hash,
                    ]),
                ];
            }
        }

        if (!empty($this->metadata['extraction'])) {
            $data['extraction_context'] = $this->metadata['extraction'];
        }

        return $data;
    }
}