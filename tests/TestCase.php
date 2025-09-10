<?php

namespace Shakewellagency\LaravelPdfViewer\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Shakewellagency\LaravelPdfViewer\Providers\PdfViewerServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStorage();
        $this->configureCache();
        $this->loadFactories();
        $this->bindPackageServices();
        $this->loadPackageRoutes();
    }
    
    protected function configureCache(): void
    {
        // Override cache configuration after service provider has loaded
        config(['pdf-viewer.cache.enabled' => true]);
        config(['pdf-viewer.cache.store' => 'array']);
    }
    
    protected function loadFactories(): void
    {
        // Configure factory discovery for package models
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            if (str_starts_with($modelName, 'Shakewellagency\\LaravelPdfViewer\\Models\\')) {
                $modelName = str_replace('Shakewellagency\\LaravelPdfViewer\\Models\\', '', $modelName);
                return 'Shakewellagency\\LaravelPdfViewer\\Database\\Factories\\' . $modelName . 'Factory';
            }
            
            return null; // Use Laravel's default guessing for other models
        });
    }
    
    protected function bindPackageServices(): void
    {
        // Manually bind services if the service provider isn't working correctly
        $this->app->bind(
            \Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface::class,
            \Shakewellagency\LaravelPdfViewer\Services\SearchService::class
        );
        
        $this->app->bind(
            \Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface::class,
            \Shakewellagency\LaravelPdfViewer\Services\DocumentService::class
        );
        
        $this->app->bind(
            \Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface::class,
            \Shakewellagency\LaravelPdfViewer\Services\CacheService::class
        );
    }
    
    protected function loadPackageRoutes(): void
    {
        // Routes are automatically loaded by the service provider
        // This method exists for future customization if needed
    }

    protected function getPackageProviders($app): array
    {
        return [
            PdfViewerServiceProvider::class,
            \Laravel\Sanctum\SanctumServiceProvider::class,
        ];
    }
    
    protected function defineEnvironment($app)
    {
        // Set up cache stores first
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
        ]);
        
        // Set environment variables that match the package config structure
        $app['config']->set('env', array_merge($app['config']->get('env', []), [
            'PDF_VIEWER_CACHE_ENABLED' => true,
            'PDF_VIEWER_CACHE_STORE' => 'array',
        ]));
        
        // Directly set the config values to override any merging issues
        $app['config']->set('pdf-viewer.cache.enabled', true);
        $app['config']->set('pdf-viewer.cache.store', 'array');
        $app['config']->set('pdf-viewer.cache.prefix', 'pdf_viewer');
        $app['config']->set('pdf-viewer.cache.ttl', [
            'document_metadata' => 3600,
            'page_content' => 7200,
            'search_results' => 1800,
        ]);
        $app['config']->set('pdf-viewer.cache.tags', [
            'documents' => 'pdf_viewer_documents',
            'pages' => 'pdf_viewer_pages',
            'search' => 'pdf_viewer_search',
        ]);
        $app['config']->set('pdf-viewer.storage.disk', 'testing');
        $app['config']->set('pdf-viewer.monitoring.log_cache', false);
        
        // Configure package routes
        $app['config']->set('pdf-viewer.route_prefix', 'api/pdf-viewer');
        $app['config']->set('pdf-viewer.middleware', ['api', 'auth:sanctum']);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
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
        config()->set('pdf-viewer.cache.enabled', false); // Disable cache for testing
        config()->set('pdf-viewer.processing.max_file_size', 10485760); // 10MB for testing
        config()->set('pdf-viewer.thumbnails.enabled', false); // Disable thumbnails in tests
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

        if (! is_dir($samplePdfPath)) {
            return null;
        }

        $pdfFiles = glob($samplePdfPath.'/*.pdf');

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
        return "%PDF-1.4\n".
               "1 0 obj\n".
               "<<\n".
               "/Type /Catalog\n".
               "/Pages 2 0 R\n".
               ">>\n".
               "endobj\n".
               "2 0 obj\n".
               "<<\n".
               "/Type /Pages\n".
               "/Kids [3 0 R]\n".
               "/Count 1\n".
               ">>\n".
               "endobj\n".
               "3 0 obj\n".
               "<<\n".
               "/Type /Page\n".
               "/Parent 2 0 R\n".
               "/MediaBox [0 0 612 792]\n".
               "/Contents 4 0 R\n".
               ">>\n".
               "endobj\n".
               "4 0 obj\n".
               "<<\n".
               "/Length 44\n".
               ">>\n".
               "stream\n".
               "BT\n".
               "/F1 12 Tf\n".
               "100 700 Td\n".
               "(Test PDF Content) Tj\n".
               "ET\n".
               "endstream\n".
               "endobj\n".
               "xref\n".
               "0 5\n".
               "0000000000 65535 f \n".
               "0000000009 65535 n \n".
               "0000000074 65535 n \n".
               "0000000131 65535 n \n".
               "0000000214 65535 n \n".
               "trailer\n".
               "<<\n".
               "/Size 5\n".
               "/Root 1 0 R\n".
               ">>\n".
               "startxref\n".
               "309\n".
               "%%EOF\n";
    }

    protected function createAuthenticatedUser(): Authenticatable
    {
        // Create a simple test user model for authentication
        return new class extends Authenticatable {
            protected $fillable = ['id', 'name', 'email'];
            
            public function __construct()
            {
                $this->id = fake()->uuid();
                $this->name = fake()->name();
                $this->email = fake()->email();
            }
            
            public function getAuthIdentifier(): string
            {
                return $this->id;
            }
        };
    }

    protected function actingAsUser(): static
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);

        return $this;
    }
}
