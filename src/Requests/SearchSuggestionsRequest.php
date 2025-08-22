<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => 'required|string|min:2|max:100',
            'limit' => 'sometimes|integer|min:1|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Search query is required for suggestions.',
            'q.min' => 'Search query must be at least 2 characters long.',
            'q.max' => 'Search query cannot exceed 100 characters.',
            'limit.min' => 'Limit must be at least 1.',
            'limit.max' => 'Limit cannot exceed 50.',
        ];
    }
}