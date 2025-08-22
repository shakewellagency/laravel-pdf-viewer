<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:1000',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
            'metadata' => 'sometimes|array',
            'metadata.author' => 'sometimes|string|max:255',
            'metadata.subject' => 'sometimes|string|max:255',
            'metadata.keywords' => 'sometimes|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'The title cannot exceed 255 characters.',
            'description.max' => 'The description cannot exceed 1000 characters.',
            'tags.array' => 'Tags must be provided as an array.',
            'tags.*.string' => 'Each tag must be a string.',
            'tags.*.max' => 'Each tag cannot exceed 50 characters.',
            'metadata.array' => 'Metadata must be provided as an object.',
            'metadata.author.max' => 'Author cannot exceed 255 characters.',
            'metadata.subject.max' => 'Subject cannot exceed 255 characters.',
            'metadata.keywords.max' => 'Keywords cannot exceed 500 characters.',
        ];
    }
}