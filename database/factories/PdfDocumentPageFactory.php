<?php

namespace Shakewellagency\LaravelPdfViewer\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentPage;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class PdfDocumentPageFactory extends Factory
{
    protected $model = PdfDocumentPage::class;

    public function definition(): array
    {
        return [
            'pdf_document_id' => PdfDocument::factory(),
            'page_number' => $this->faker->numberBetween(1, 100),
            'page_file_path' => 'pdf-pages/' . $this->faker->uuid() . '/page_' . $this->faker->numberBetween(1, 100) . '.pdf',
            'thumbnail_path' => 'thumbnails/' . $this->faker->uuid() . '/page-' . $this->faker->randomNumber() . '.jpg',
            'metadata' => json_encode(['word_count' => $this->faker->numberBetween(50, 500)]),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'processing_error' => $this->faker->optional(0.1)->sentence(),
            'is_parsed' => $this->faker->boolean(80),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'page_file_path' => null,
            'thumbnail_path' => null,
            'metadata' => null,
            'processing_error' => null,
            'is_parsed' => false,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'page_file_path' => null,
            'thumbnail_path' => null,
            'metadata' => null,
            'processing_error' => null,
            'is_parsed' => false,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processing_error' => null,
            'is_parsed' => true,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'page_file_path' => null,
            'thumbnail_path' => null,
            'processing_error' => 'Failed to extract text from page',
            'is_parsed' => false,
        ]);
    }

    public function aviation(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata' => json_encode(['word_count' => $this->faker->numberBetween(100, 800)]),
                'is_parsed' => true,
            ];
        });
    }

}