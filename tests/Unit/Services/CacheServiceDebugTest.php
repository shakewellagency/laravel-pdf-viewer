<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Shakewellagency\LaravelPdfViewer\Services\CacheService;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class CacheServiceDebugTest extends TestCase
{
    public function test_debug_cache_configuration(): void
    {
        $cacheService = new CacheService();
        
        // Debug config values
        dump('PDF cache enabled: ' . var_export(config('pdf-viewer.cache.enabled'), true));
        dump('PDF cache store: ' . config('pdf-viewer.cache.store'));
        dump('Default cache store: ' . config('cache.default'));
        dump('Full pdf-viewer config: ', config('pdf-viewer'));
        dump('Environment cache enabled: ' . env('PDF_VIEWER_CACHE_ENABLED', 'not-set'));
        
        // Test basic caching
        $result = Cache::put('test_key', 'test_value', 3600);
        dump('Basic cache put result: ' . var_export($result, true));
        
        $cached = Cache::get('test_key');
        dump('Basic cache get result: ' . var_export($cached, true));
        
        $this->assertTrue(true); // Just pass the test
    }
}