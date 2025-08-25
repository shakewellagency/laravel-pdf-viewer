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
        static $pageNumber = 0;
        $content = $this->generatePageContent();
        
        return [
            'pdf_document_id' => PdfDocument::factory(),
            'page_number' => ++$pageNumber,
            'content' => $content,
            'page_file_path' => 'pdf-pages/' . $this->faker->uuid() . '/page-' . $this->faker->randomNumber() . '.pdf',
            'thumbnail_path' => 'thumbnails/' . $this->faker->uuid() . '/page-' . $this->faker->randomNumber() . '.jpg',
            'metadata' => [
                'width' => $this->faker->numberBetween(200, 800),
                'height' => $this->faker->numberBetween(200, 1200),
            ],
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'processing_error' => $this->faker->optional(0.1)->sentence(),
            'is_parsed' => $this->faker->boolean(80),
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'content' => null,
            'thumbnail_path' => null,
            'processing_error' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'content' => null,
            'processing_error' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processing_error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'content' => null,
            'thumbnail_path' => null,
            'processing_error' => 'Failed to extract text from page',
        ]);
    }

    public function aviation(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->generateAviationContent(),
        ]);
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