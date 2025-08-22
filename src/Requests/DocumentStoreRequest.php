<?php

namespace Shakewellagency\LaravelPdfViewer\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxFileSize = config('pdf-viewer.processing.max_file_size', 104857600); // 100MB in bytes
        
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:' . ($maxFileSize / 1024), // Laravel expects KB
            ],
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
        $maxSizeMB = config('pdf-viewer.processing.max_file_size', 104857600) / 1024 / 1024;
        
        return [
            'file.required' => 'A PDF file is required.',
            'file.file' => 'The uploaded file is not valid.',
            'file.mimes' => 'Only PDF files are allowed.',
            'file.max' => "The file size cannot exceed {$maxSizeMB}MB.",
            'title.max' => 'The title cannot exceed 255 characters.',
            'description.max' => 'The description cannot exceed 1000 characters.',
            'tags.array' => 'Tags must be provided as an array.',
            'tags.*.string' => 'Each tag must be a string.',
            'tags.*.max' => 'Each tag cannot exceed 50 characters.',
            'metadata.array' => 'Metadata must be provided as an object.',
        ];
    }

    public function prepareForValidation(): void
    {
        // Ensure metadata is an array if provided
        if ($this->has('metadata') && is_string($this->metadata)) {
            $this->merge([
                'metadata' => json_decode($this->metadata, true) ?: [],
            ]);
        }

        // Ensure tags is an array if provided
        if ($this->has('tags') && is_string($this->tags)) {
            $this->merge([
                'tags' => json_decode($this->tags, true) ?: [],
            ]);
        }
    }
}