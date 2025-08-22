<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'page_number' => $this->page_number,
            'content_length' => $this->content_length,
            'word_count' => $this->word_count,
            'relevance_score' => $this->when(
                isset($this->relevance_score),
                round($this->relevance_score, 4)
            ),
            'search_snippet' => $this->when(
                isset($this->search_snippet),
                $this->search_snippet
            ),
            'highlighted_content' => $this->when(
                isset($this->highlighted_content) && $request->boolean('highlight', true),
                $this->highlighted_content
            ),
            'content' => $this->when(
                $request->boolean('include_full_content', false),
                $this->content
            ),
            'has_thumbnail' => $this->hasThumbnail(),
            'document' => [
                'hash' => $this->document->hash,
                'title' => $this->document->title,
                'filename' => $this->document->original_filename,
            ],
            'thumbnail_url' => $this->when(
                $this->hasThumbnail(),
                route('pdf-viewer.documents.pages.thumbnail', [
                    'document_hash' => $this->document->hash,
                    'page_number' => $this->page_number,
                ])
            ),
            'page_url' => route('pdf-viewer.documents.pages.show', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}