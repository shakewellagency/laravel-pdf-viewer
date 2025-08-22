<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $minLength = config('pdf-viewer.search.min_query_length', 3);
        $maxLength = config('pdf-viewer.search.max_query_length', 255);
        
        return [
            'q' => "required|string|min:{$minLength}|max:{$maxLength}",
            'status' => 'sometimes|string|in:uploaded,processing,completed,failed,cancelled',
            'created_by' => 'sometimes|uuid',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ];
    }

    public function messages(): array
    {
        $minLength = config('pdf-viewer.search.min_query_length', 3);
        $maxLength = config('pdf-viewer.search.max_query_length', 255);
        
        return [
            'q.required' => 'Search query is required.',
            'q.min' => "Search query must be at least {$minLength} characters long.",
            'q.max' => "Search query cannot exceed {$maxLength} characters.",
            'status.in' => 'Status must be one of: uploaded, processing, completed, failed, cancelled.',
            'created_by.uuid' => 'Created by must be a valid UUID.',
            'date_to.after_or_equal' => 'End date must be after or equal to start date.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 50.',
        ];
    }
}