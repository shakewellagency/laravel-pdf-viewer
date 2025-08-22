<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PDF Viewer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the Laravel PDF Viewer package
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('PDF_VIEWER_ROUTE_PREFIX', 'api/pdf-viewer'),
    'middleware' => ['api', 'auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('PDF_VIEWER_DISK', 'local'),
        'path' => env('PDF_VIEWER_PATH', 'pdf-documents'),
        'pages_path' => env('PDF_VIEWER_PAGES_PATH', 'pdf-pages'),
        'thumbnails_path' => env('PDF_VIEWER_THUMBNAILS_PATH', 'pdf-thumbnails'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    */
    'processing' => [
        'queue' => env('PDF_VIEWER_QUEUE', 'default'),
        'max_file_size' => env('PDF_VIEWER_MAX_FILE_SIZE', 104857600), // 100MB
        'allowed_extensions' => ['pdf'],
        'allowed_mime_types' => ['application/pdf'],
        'timeout' => env('PDF_VIEWER_TIMEOUT', 300), // 5 minutes
        'memory_limit' => env('PDF_VIEWER_MEMORY_LIMIT', '512M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    */
    'jobs' => [
        'document_processing' => [
            'queue' => env('PDF_VIEWER_DOCUMENT_QUEUE', 'pdf-processing'),
            'tries' => env('PDF_VIEWER_DOCUMENT_TRIES', 3),
            'timeout' => env('PDF_VIEWER_DOCUMENT_TIMEOUT', 300),
            'retry_after' => env('PDF_VIEWER_DOCUMENT_RETRY_AFTER', 60),
        ],
        'page_extraction' => [
            'queue' => env('PDF_VIEWER_PAGE_QUEUE', 'pdf-pages'),
            'tries' => env('PDF_VIEWER_PAGE_TRIES', 2),
            'timeout' => env('PDF_VIEWER_PAGE_TIMEOUT', 60),
            'retry_after' => env('PDF_VIEWER_PAGE_RETRY_AFTER', 30),
        ],
        'text_processing' => [
            'queue' => env('PDF_VIEWER_TEXT_QUEUE', 'pdf-text'),
            'tries' => env('PDF_VIEWER_TEXT_TRIES', 2),
            'timeout' => env('PDF_VIEWER_TEXT_TIMEOUT', 30),
            'retry_after' => env('PDF_VIEWER_TEXT_RETRY_AFTER', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('PDF_VIEWER_CACHE_ENABLED', true),
        'store' => env('PDF_VIEWER_CACHE_STORE', 'redis'),
        'prefix' => env('PDF_VIEWER_CACHE_PREFIX', 'pdf_viewer'),
        'ttl' => [
            'document_metadata' => env('PDF_VIEWER_CACHE_DOCUMENT_TTL', 3600), // 1 hour
            'page_content' => env('PDF_VIEWER_CACHE_PAGE_TTL', 7200), // 2 hours
            'search_results' => env('PDF_VIEWER_CACHE_SEARCH_TTL', 1800), // 30 minutes
        ],
        'tags' => [
            'documents' => 'pdf_viewer_documents',
            'pages' => 'pdf_viewer_pages',
            'search' => 'pdf_viewer_search',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    */
    'search' => [
        'min_query_length' => env('PDF_VIEWER_SEARCH_MIN_LENGTH', 3),
        'max_query_length' => env('PDF_VIEWER_SEARCH_MAX_LENGTH', 255),
        'results_per_page' => env('PDF_VIEWER_SEARCH_RESULTS_PER_PAGE', 15),
        'snippet_length' => env('PDF_VIEWER_SEARCH_SNIPPET_LENGTH', 200),
        'highlight_tag' => env('PDF_VIEWER_SEARCH_HIGHLIGHT_TAG', 'mark'),
        'mode' => env('PDF_VIEWER_SEARCH_MODE', 'IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Configuration
    |--------------------------------------------------------------------------
    */
    'thumbnails' => [
        'enabled' => env('PDF_VIEWER_THUMBNAILS_ENABLED', true),
        'width' => env('PDF_VIEWER_THUMBNAIL_WIDTH', 300),
        'height' => env('PDF_VIEWER_THUMBNAIL_HEIGHT', 400),
        'quality' => env('PDF_VIEWER_THUMBNAIL_QUALITY', 80),
        'format' => env('PDF_VIEWER_THUMBNAIL_FORMAT', 'jpg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        'hash_algorithm' => env('PDF_VIEWER_HASH_ALGORITHM', 'sha256'),
        'salt' => env('PDF_VIEWER_SALT', env('APP_KEY')),
        'max_upload_attempts' => env('PDF_VIEWER_MAX_UPLOAD_ATTEMPTS', 5),
        'rate_limit' => env('PDF_VIEWER_RATE_LIMIT', 60), // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'log_processing' => env('PDF_VIEWER_LOG_PROCESSING', true),
        'log_search' => env('PDF_VIEWER_LOG_SEARCH', false),
        'log_cache' => env('PDF_VIEWER_LOG_CACHE', false),
        'metrics_enabled' => env('PDF_VIEWER_METRICS_ENABLED', true),
    ],
];