<?php

namespace Shakewellagency\LaravelPdfViewer\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

class PdfDocumentFactory extends Factory
{
    protected $model = PdfDocument::class;

    public function definition(): array
    {
        return [
            'hash' => $this->faker->unique()->sha256(),
            'title' => $this->faker->sentence(3),
            'filename' => $this->faker->word() . '.pdf',
            'original_filename' => $this->faker->word() . '.pdf',
            'mime_type' => 'application/pdf',
            'file_path' => 'pdf-documents/' . $this->faker->uuid() . '.pdf',
            'file_size' => $this->faker->numberBetween(100000, 10000000), // 100KB - 10MB
            'page_count' => $this->faker->numberBetween(1, 100),
            'status' => $this->faker->randomElement(['uploaded', 'processing', 'completed', 'failed']),
            'is_searchable' => $this->faker->boolean(80), // 80% chance of being searchable
            'metadata' => [
                'author' => $this->faker->name(),
                'subject' => $this->faker->words(3, true),
                'keywords' => $this->faker->words(5, true),
                'creator' => $this->faker->company(),
            ],
            'processing_started_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 week', 'now'),
            'processing_completed_at' => $this->faker->optional(0.5)->dateTimeBetween('-1 week', 'now'),
            'processing_error' => $this->faker->optional(0.1)->sentence(),
            'created_by' => $this->faker->optional(0.5)->uuid(),
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function uploaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'uploaded',
            'processing_started_at' => null,
            'processing_completed_at' => null,
            'processing_error' => null,
            'is_searchable' => false,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'processing_started_at' => now(),
            'processing_completed_at' => null,
            'processing_error' => null,
            'is_searchable' => false,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processing_started_at' => now()->subMinutes(30),
            'processing_completed_at' => now(),
            'processing_error' => null,
            'is_searchable' => true,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'processing_started_at' => now()->subMinutes(15),
            'processing_completed_at' => null,
            'processing_error' => 'Processing failed due to corrupted PDF',
            'is_searchable' => false,
        ]);
    }

    public function searchable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_searchable' => true,
            'status' => 'completed',
        ]);
    }

    public function aviation(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $this->faker->randomElement([
                'Aviation Safety Manual',
                'Aircraft Maintenance Procedures',
                'Flight Operations Handbook',
                'Aviation Regulations Guide',
                'Emergency Procedures Manual',
            ]),
            'filename' => 'aviation_' . $this->faker->word() . '.pdf',
            'original_filename' => 'aviation_' . $this->faker->word() . '.pdf',
            'metadata' => [
                'author' => 'Aviation Authority',
                'subject' => 'Aviation Safety and Operations',
                'keywords' => 'aviation, safety, aircraft, procedures, maintenance',
                'creator' => 'Aviation Documentation System',
                'category' => 'Aviation Technical Manual',
            ],
        ]);
    }
}