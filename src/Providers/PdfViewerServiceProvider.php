<?php

namespace Shakewellagency\LaravelPdfViewer\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\DocumentProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\PageProcessingServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\CacheServiceInterface;
use Shakewellagency\LaravelPdfViewer\Contracts\SearchServiceInterface;
use Shakewellagency\LaravelPdfViewer\Services\DocumentService;
use Shakewellagency\LaravelPdfViewer\Services\DocumentProcessingService;
use Shakewellagency\LaravelPdfViewer\Services\PageProcessingService;
use Shakewellagency\LaravelPdfViewer\Services\CacheService;
use Shakewellagency\LaravelPdfViewer\Services\SearchService;

class PdfViewerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/pdf-viewer.php',
            'pdf-viewer'
        );

        // Bind service interfaces to implementations
        $this->app->bind(DocumentServiceInterface::class, DocumentService::class);
        $this->app->bind(DocumentProcessingServiceInterface::class, DocumentProcessingService::class);
        $this->app->bind(PageProcessingServiceInterface::class, PageProcessingService::class);
        $this->app->bind(CacheServiceInterface::class, CacheService::class);
        $this->app->bind(SearchServiceInterface::class, SearchService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/pdf-viewer.php' => config_path('pdf-viewer.php'),
            ], 'pdf-viewer-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'pdf-viewer-migrations');

            // Publish views if needed in future
            // $this->publishes([
            //     __DIR__ . '/../../resources/views' => resource_path('views/vendor/pdf-viewer'),
            // ], 'pdf-viewer-views');
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Configure factory discovery for package models
        $this->configureFactories();

        // Register routes
        $this->registerRoutes();
    }

    /**
     * Configure factory discovery for package models.
     */
    protected function configureFactories(): void
    {
        if ($this->app->environment('testing')) {
            Factory::guessFactoryNamesUsing(function (string $modelName) {
                if (str_starts_with($modelName, 'Shakewellagency\\LaravelPdfViewer\\Models\\')) {
                    $modelName = str_replace('Shakewellagency\\LaravelPdfViewer\\Models\\', '', $modelName);
                    return 'Shakewellagency\\LaravelPdfViewer\\Database\\Factories\\' . $modelName . 'Factory';
                }
                
                return null; // Use Laravel's default guessing for other models
            });
        }
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => config('pdf-viewer.route_prefix', 'api/pdf-viewer'),
            'middleware' => config('pdf-viewer.middleware', ['api', 'auth:sanctum']),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        });
    }

}