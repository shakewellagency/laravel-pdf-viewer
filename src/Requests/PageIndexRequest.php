<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PageIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|string|in:pending,processing,completed,failed',
            'parsed_only' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of: pending, processing, completed, failed.',
            'parsed_only.boolean' => 'Parsed only filter must be true or false.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
            'page.min' => 'Page number must be at least 1.',
        ];
    }
}