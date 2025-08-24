<?php

namespace Shakewellagency\LaravelPdfViewer\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Shakewellagency\LaravelPdfViewer\Providers\PdfViewerServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setUpDatabase();
        $this->setUpStorage();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PdfViewerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up PDF viewer configuration for testing
        config()->set('pdf-viewer.storage.disk', 'testing');
        config()->set('pdf-viewer.cache.enabled', true); // Enable cache for testing but with array driver
        config()->set('pdf-viewer.cache.store', 'array'); // Use array cache store for testing
        config()->set('pdf-viewer.cache.ttl', 60); // Short TTL for testing
        config()->set('pdf-viewer.processing.max_file_size', 10485760); // 10MB for testing
        config()->set('pdf-viewer.thumbnails.enabled', false); // Disable thumbnails in tests
        
        // Set cache driver to array for testing
        config()->set('cache.default', 'array');
        
        // Configure auth for testing - use session instead of sanctum for testing
        config()->set('auth.defaults.guard', 'web');
        config()->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        config()->set('auth.guards.sanctum', [
            'driver' => 'session', // Use session instead of sanctum for testing
            'provider' => 'users',
        ]);
        config()->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \Illuminate\Foundation\Auth\User::class,
        ]);
    }

    protected function setUpDatabase(): void
    {
        $this->artisan('migrate', ['--database' => 'testing']);
    }

    protected function setUpStorage(): void
    {
        Storage::fake('testing');
    }

    protected function createSamplePdfFile(string $filename = 'sample.pdf', int $sizeKb = 100): UploadedFile
    {
        // Create a minimal PDF content for testing
        $pdfContent = $this->generateMinimalPdfContent();
        
        return UploadedFile::fake()->createWithContent(
            $filename,
            $pdfContent
        )->mimeType('application/pdf');
    }

    protected function createRealSamplePdf(): ?UploadedFile
    {
        // Check if sample PDFs exist in the project
        $samplePdfPath = base_path('SamplePDF');
        
        if (!is_dir($samplePdfPath)) {
            return null;
        }

        $pdfFiles = glob($samplePdfPath . '/*.pdf');
        
        if (empty($pdfFiles)) {
            return null;
        }

        $firstPdf = $pdfFiles[0];
        
        return new UploadedFile(
            $firstPdf,
            basename($firstPdf),
            'application/pdf',
            null,
            true // Mark as test file
        );
    }

    protected function generateMinimalPdfContent(): string
    {
        // This generates a minimal valid PDF for testing purposes
        return "%PDF-1.4\n" .
               "1 0 obj\n" .
               "<<\n" .
               "/Type /Catalog\n" .
               "/Pages 2 0 R\n" .
               ">>\n" .
               "endobj\n" .
               "2 0 obj\n" .
               "<<\n" .
               "/Type /Pages\n" .
               "/Kids [3 0 R]\n" .
               "/Count 1\n" .
               ">>\n" .
               "endobj\n" .
               "3 0 obj\n" .
               "<<\n" .
               "/Type /Page\n" .
               "/Parent 2 0 R\n" .
               "/MediaBox [0 0 612 792]\n" .
               "/Contents 4 0 R\n" .
               ">>\n" .
               "endobj\n" .
               "4 0 obj\n" .
               "<<\n" .
               "/Length 44\n" .
               ">>\n" .
               "stream\n" .
               "BT\n" .
               "/F1 12 Tf\n" .
               "100 700 Td\n" .
               "(Test PDF Content) Tj\n" .
               "ET\n" .
               "endstream\n" .
               "endobj\n" .
               "xref\n" .
               "0 5\n" .
               "0000000000 65535 f \n" .
               "0000000009 65535 n \n" .
               "0000000074 65535 n \n" .
               "0000000131 65535 n \n" .
               "0000000214 65535 n \n" .
               "trailer\n" .
               "<<\n" .
               "/Size 5\n" .
               "/Root 1 0 R\n" .
               ">>\n" .
               "startxref\n" .
               "309\n" .
               "%%EOF\n";
    }

    protected function createAuthenticatedUser(): \Illuminate\Foundation\Auth\User
    {
        // Create a simple User instance for testing
        $user = new class extends \Illuminate\Foundation\Auth\User {
            protected $fillable = ['id', 'email', 'name'];
            public $incrementing = false;
            protected $keyType = 'string';
        };
        
        $user->forceFill([
            'id' => $this->faker->uuid(),
            'email' => $this->faker->email(),
            'name' => $this->faker->name(),
        ]);
        
        return $user;
    }

    protected function actingAsUser(): static
    {
        $user = $this->createAuthenticatedUser();
        // Mock authentication - adjust based on your auth system
        $this->actingAs($user);
        return $this;
    }
}