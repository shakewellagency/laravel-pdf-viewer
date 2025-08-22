<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'title' => $this->title,
            'filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'page_count' => $this->page_count,
            'status' => $this->status,
            'is_searchable' => $this->is_searchable,
            'relevance_score' => $this->when(
                isset($this->relevance_score),
                round($this->relevance_score, 4)
            ),
            'search_snippets' => $this->when(
                isset($this->search_snippets),
                $this->search_snippets
            ),
            'matching_pages' => $this->when(
                $this->relationLoaded('pages'),
                $this->pages->count()
            ),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}