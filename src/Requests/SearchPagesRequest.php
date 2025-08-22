<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchPagesRequest extends FormRequest
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
            'per_page' => 'sometimes|integer|min:1|max:50',
            'highlight' => 'sometimes|boolean',
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
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 50.',
            'highlight.boolean' => 'Highlight must be true or false.',
        ];
    }
}