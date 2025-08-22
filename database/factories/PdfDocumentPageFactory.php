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
        $content = $this->generatePageContent();
        
        return [
            'document_id' => PdfDocument::factory(),
            'page_number' => $this->faker->numberBetween(1, 100),
            'content' => $content,
            'content_length' => strlen($content),
            'word_count' => str_word_count($content),
            'thumbnail_path' => 'thumbnails/' . $this->faker->uuid() . '/page-' . $this->faker->randomNumber() . '.jpg',
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'processing_started_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 week', 'now'),
            'processing_completed_at' => $this->faker->optional(0.6)->dateTimeBetween('-1 week', 'now'),
            'error_message' => $this->faker->optional(0.1)->sentence(),
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'content' => null,
            'content_length' => 0,
            'word_count' => 0,
            'thumbnail_path' => null,
            'processing_started_at' => null,
            'processing_completed_at' => null,
            'error_message' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'content' => null,
            'content_length' => 0,
            'word_count' => 0,
            'processing_started_at' => now(),
            'processing_completed_at' => null,
            'error_message' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processing_started_at' => now()->subMinutes(5),
            'processing_completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'content' => null,
            'content_length' => 0,
            'word_count' => 0,
            'thumbnail_path' => null,
            'processing_started_at' => now()->subMinutes(5),
            'processing_completed_at' => null,
            'error_message' => 'Failed to extract text from page',
        ]);
    }

    public function aviation(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->generateAviationContent(),
        ])->afterMaking(function (PdfDocumentPage $page) {
            $page->content_length = strlen($page->content);
            $page->word_count = str_word_count($page->content);
        });
    }

    private function generatePageContent(): string
    {
        $paragraphs = $this->faker->paragraphs($this->faker->numberBetween(3, 8));
        
        // Add some technical terms and structured content
        $technicalTerms = [
            'procedure', 'specification', 'requirement', 'protocol', 'standard',
            'guideline', 'regulation', 'compliance', 'safety', 'operational',
            'technical', 'manual', 'documentation', 'reference', 'implementation'
        ];
        
        $content = implode("\n\n", $paragraphs);
        
        // Occasionally add technical terms
        if ($this->faker->boolean(30)) {
            $term = $this->faker->randomElement($technicalTerms);
            $content = $term . ' ' . $content;
        }
        
        return $content;
    }

    private function generateAviationContent(): string
    {
        $aviationPhrases = [
            'aircraft maintenance procedures',
            'safety inspection protocols',
            'flight operations manual',
            'emergency response procedures',
            'aviation regulatory compliance',
            'pilot training requirements',
            'aircraft systems documentation',
            'maintenance interval schedules',
            'safety management system',
            'operational procedures handbook',
        ];

        $sentences = [];
        for ($i = 0; $i < $this->faker->numberBetween(5, 12); $i++) {
            $phrase = $this->faker->randomElement($aviationPhrases);
            $sentences[] = ucfirst($phrase) . ' ' . $this->faker->sentence();
        }

        return implode(' ', $sentences);
    }
}