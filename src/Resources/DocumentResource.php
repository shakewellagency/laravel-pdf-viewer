<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
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
            'mime_type' => $this->mime_type,
            'page_count' => $this->page_count,
            'status' => $this->status,
            'is_searchable' => $this->is_searchable,
            'processing_progress' => $this->when(
                in_array($this->status, ['processing', 'failed']),
                $this->processing_progress
            ),
            'processing_error' => $this->when(
                $this->status === 'failed',
                $this->processing_error
            ),
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'processing_completed_at' => $this->processing_completed_at?->toISOString(),
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}