<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'hash' => $this->hash,
            'title' => $this->title,
            'filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'page_count' => $this->page_count,
            'status' => $this->status,
            'is_searchable' => $this->is_searchable,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];

        if (isset($this->relevance_score)) {
            $data['relevance_score'] = round($this->relevance_score, 4);
        }

        if (isset($this->search_snippets)) {
            $data['search_snippets'] = $this->search_snippets;
        }

        if ($this->relationLoaded('pages')) {
            $data['matching_pages'] = $this->pages->count();
        }

        return $data;
    }
}