<?php

namespace Shakewellagency\LaravelPdfViewer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'level' => $this->level,
            'destination_page' => $this->destination_page,
            'destination_type' => $this->destination_type,
            'destination_name' => $this->when(
                $this->destination_name !== null,
                $this->destination_name
            ),
            'order_index' => $this->order_index,
            'children' => OutlineResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
