<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|string|in:uploaded,processing,completed,failed,cancelled',
            'is_searchable' => 'sometimes|boolean',
            'created_by' => 'sometimes|uuid',
            'search' => 'sometimes|string|max:255',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'sort_by' => 'sometimes|string|in:created_at,updated_at,title,file_size,page_count',
            'sort_order' => 'sometimes|string|in:asc,desc',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of: uploaded, processing, completed, failed, cancelled.',
            'is_searchable.boolean' => 'Searchable filter must be true or false.',
            'created_by.uuid' => 'Created by must be a valid UUID.',
            'search.max' => 'Search term cannot exceed 255 characters.',
            'date_to.after_or_equal' => 'End date must be after or equal to start date.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
            'sort_by.in' => 'Sort by must be one of: created_at, updated_at, title, file_size, page_count.',
            'sort_order.in' => 'Sort order must be either asc or desc.',
        ];
    }
}