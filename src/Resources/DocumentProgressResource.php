<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'hash' => $this->hash,
            'title' => $this->title,
            'status' => $this->status,
            'progress_percentage' => $this->getProcessingProgress(),
            'total_pages' => $this->page_count,
            'completed_pages' => $this->completedPages()->count(),
            'failed_pages' => $this->failedPages()->count(),
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'processing_completed_at' => $this->processing_completed_at?->toISOString(),
            'processing_progress' => $this->processing_progress,
            'processing_error' => $this->when(
                $this->status === 'failed',
                $this->processing_error
            ),
            'is_searchable' => $this->is_searchable,
            'estimated_completion' => $this->when(
                $this->status === 'processing',
                $this->getEstimatedCompletion()
            ),
        ];
    }

    protected function getEstimatedCompletion(): ?string
    {
        if (!$this->processing_started_at || $this->page_count === 0) {
            return null;
        }

        $completedPages = $this->completedPages()->count();
        if ($completedPages === 0) {
            return null;
        }

        $elapsedMinutes = $this->processing_started_at->diffInMinutes(now());
        $averageTimePerPage = $elapsedMinutes / $completedPages;
        $remainingPages = $this->page_count - $completedPages;
        $estimatedRemainingMinutes = $remainingPages * $averageTimePerPage;

        return now()->addMinutes($estimatedRemainingMinutes)->toISOString();
    }
}