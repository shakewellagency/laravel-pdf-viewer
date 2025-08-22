<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'thumbnail_url' => $this->when(
                $this->hasThumbnail(),
                route('pdf-viewer.documents.pages.thumbnail', [
                    'document_hash' => $this->document->hash,
                    'page_number' => $this->page_number,
                ])
            ),
            'download_url' => route('pdf-viewer.documents.pages.download', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}