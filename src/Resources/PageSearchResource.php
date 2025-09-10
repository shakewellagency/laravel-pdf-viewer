<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'page_number' => $this->page_number,
            'content_length' => $this->content_length,
            'word_count' => $this->word_count,
            'has_thumbnail' => $this->hasThumbnail(),
            'document' => $this->document ? [
                'hash' => $this->document->hash,
                'title' => $this->document->title,
                'filename' => $this->document->original_filename,
            ] : null,
            'page_url' => $this->document ? route('pdf-viewer.documents.pages.show', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]) : null,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];

        if (isset($this->relevance_score)) {
            $data['relevance_score'] = round($this->relevance_score, 4);
        }

        if (isset($this->search_snippet)) {
            $data['search_snippet'] = $this->search_snippet;
        }

        if (isset($this->highlighted_content) && $request->boolean('highlight', true)) {
            $data['highlighted_content'] = $this->highlighted_content;
        }

        if ($request->boolean('include_full_content', false)) {
            $data['content'] = $this->content;
        }

        if ($this->hasThumbnail() && $this->document) {
            $data['thumbnail_url'] = route('pdf-viewer.documents.pages.thumbnail', [
                'document_hash' => $this->document->hash,
                'page_number' => $this->page_number,
            ]);
        }

        return $data;
    }
}