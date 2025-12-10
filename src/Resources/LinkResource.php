<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type ?? $this->getTypeFromDestination(),
            'source_page' => $this->source_page,
            'rect' => [
                'x' => (float) $this->source_rect_x,
                'y' => (float) $this->source_rect_y,
                'width' => (float) $this->source_rect_width,
                'height' => (float) $this->source_rect_height,
            ],
            'normalized_rect' => [
                'x_percent' => (float) $this->coord_x_percent,
                'y_percent' => (float) $this->coord_y_percent,
                'width_percent' => (float) $this->coord_width_percent,
                'height_percent' => (float) $this->coord_height_percent,
            ],
            'destination_page' => $this->when(
                $this->destination_page !== null,
                $this->destination_page
            ),
            'destination_type' => $this->destination_type,
            'destination_name' => $this->when(
                $this->destination_name !== null,
                $this->destination_name
            ),
            'destination_url' => $this->when(
                $this->destination_url !== null,
                $this->destination_url
            ),
            'link_text' => $this->when(
                $this->link_text !== null,
                $this->link_text
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Derive type from destination_type for backwards compatibility
     */
    protected function getTypeFromDestination(): string
    {
        return match ($this->destination_type) {
            'page', 'named' => 'internal',
            'external' => 'external',
            default => 'unknown',
        };
    }
}
