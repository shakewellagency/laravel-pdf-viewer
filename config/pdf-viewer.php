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
        'disk' => env('PDF_VIEWER_DISK', 's3'),
        'path' => env('PDF_VIEWER_PATH', 'pdf-documents'),
        'pages_path' => env('PDF_VIEWER_PAGES_PATH', 'pdf-pages'),
        'thumbnails_path' => env('PDF_VIEWER_THUMBNAILS_PATH', 'pdf-thumbnails'),
        // Vapor/S3 specific settings
        'temp_disk' => env('PDF_VIEWER_TEMP_DISK', 'local'),
        'signed_url_expires' => env('PDF_VIEWER_SIGNED_URL_EXPIRES', 3600), // 1 hour
        'multipart_threshold' => env('PDF_VIEWER_MULTIPART_THRESHOLD', 52428800), // 50MB
        'stream_reads' => env('PDF_VIEWER_STREAM_READS', true),
        // Package-specific AWS credentials to avoid conflicts
        'aws' => [
            'key' => env('PDF_VIEWER_AWS_ACCESS_KEY_ID'),
            'secret' => env('PDF_VIEWER_AWS_SECRET_ACCESS_KEY'),
            'region' => env('PDF_VIEWER_AWS_REGION', 'ap-southeast-2'),
            'bucket' => env('PDF_VIEWER_AWS_BUCKET'),
            'use_path_style_endpoint' => env('PDF_VIEWER_AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
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
    | Page Extraction Configuration
    |--------------------------------------------------------------------------
    */
    'page_extraction' => [
        // Font and Resource Management
        'preserve_fonts' => env('PDF_VIEWER_PRESERVE_FONTS', true),
        'font_fallback_strategy' => env('PDF_VIEWER_FONT_FALLBACK', 'preserve'), // preserve, substitute, ignore
        'validate_fonts' => env('PDF_VIEWER_VALIDATE_FONTS', true),
        'compression' => env('PDF_VIEWER_PAGE_COMPRESSION', true),
        'optimize_resources' => env('PDF_VIEWER_OPTIMIZE_RESOURCES', true),
        'resource_strategy' => env('PDF_VIEWER_RESOURCE_STRATEGY', 'smart_copy'), // smart_copy, duplicate_all, minimal
        
        // Cross-Reference and Link Handling
        'preserve_internal_links' => env('PDF_VIEWER_PRESERVE_INTERNAL_LINKS', false),
        'strip_navigation' => env('PDF_VIEWER_STRIP_NAVIGATION', true),
        'handle_form_fields' => env('PDF_VIEWER_HANDLE_FORM_FIELDS', 'isolate'), // isolate, preserve, remove
        'preserve_annotations' => env('PDF_VIEWER_PRESERVE_ANNOTATIONS', 'page_only'), // page_only, all, none
        
        // Document Structure
        'copy_metadata' => env('PDF_VIEWER_COPY_METADATA', 'basic'), // basic, full, none
        'handle_javascript' => env('PDF_VIEWER_HANDLE_JAVASCRIPT', 'remove'), // remove, preserve, isolate
        'rotation_handling' => env('PDF_VIEWER_ROTATION_HANDLING', 'preserve'), // preserve, normalize, detect
        
        // Security and Compatibility
        'handle_encryption' => env('PDF_VIEWER_HANDLE_ENCRYPTION', true),
        'linearization_check' => env('PDF_VIEWER_LINEARIZATION_CHECK', true),
        'incremental_update_detection' => env('PDF_VIEWER_INCREMENTAL_UPDATE_DETECTION', true),
        'max_resource_size' => env('PDF_VIEWER_MAX_RESOURCE_SIZE', 10485760), // 10MB per page
        
        // Edge Case Handling
        'detect_portfolio_pdfs' => env('PDF_VIEWER_DETECT_PORTFOLIO', true),
        'handle_form_calculations' => env('PDF_VIEWER_HANDLE_FORM_CALC', 'isolate'), // isolate, preserve, remove
        'detect_spread_layouts' => env('PDF_VIEWER_DETECT_SPREADS', true),
        'preserve_embedded_files' => env('PDF_VIEWER_PRESERVE_EMBEDDED', 'document_level'), // document_level, duplicate, remove
        'handle_color_separations' => env('PDF_VIEWER_HANDLE_COLOR_SEP', 'preserve'), // preserve, flatten, detect
        
        // Performance & Recovery
        'chunk_processing' => env('PDF_VIEWER_CHUNK_PROCESSING', true),
        'chunk_size' => env('PDF_VIEWER_CHUNK_SIZE', 100), // pages per chunk
        'max_processing_time' => env('PDF_VIEWER_MAX_PROCESSING_TIME', 1800), // 30 minutes
        'enable_resume' => env('PDF_VIEWER_ENABLE_RESUME', true),
        'performance_monitoring' => env('PDF_VIEWER_PERF_MONITORING', true),
        
        // Validation & Integrity
        'enable_checksums' => env('PDF_VIEWER_ENABLE_CHECKSUMS', true),
        'validate_page_count' => env('PDF_VIEWER_VALIDATE_PAGE_COUNT', true),
        'integrity_checks' => env('PDF_VIEWER_INTEGRITY_CHECKS', true),
        'corruption_detection' => env('PDF_VIEWER_CORRUPTION_DETECTION', true),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Legal & Compliance Configuration
    |--------------------------------------------------------------------------
    */
    'compliance' => [
        'audit_trail' => env('PDF_VIEWER_AUDIT_TRAIL', true),
        'preserve_original' => env('PDF_VIEWER_PRESERVE_ORIGINAL', true),
        'retention_policy' => env('PDF_VIEWER_RETENTION_DAYS', 2555), // 7 years default
        'compliance_flags' => env('PDF_VIEWER_COMPLIANCE_FLAGS', 'GDPR,HIPAA,SOX'), // Comma-separated
        'require_extraction_reason' => env('PDF_VIEWER_REQUIRE_REASON', false),
        'log_user_actions' => env('PDF_VIEWER_LOG_USER_ACTIONS', true),
        'enable_data_lineage' => env('PDF_VIEWER_DATA_LINEAGE', true),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | File Naming & Organization
    |--------------------------------------------------------------------------
    */
    'file_naming' => [
        'strategy' => env('PDF_VIEWER_NAMING_STRATEGY', 'hierarchical'), // hierarchical, flat, hybrid
        'include_timestamp' => env('PDF_VIEWER_INCLUDE_TIMESTAMP', true),
        'include_checksum' => env('PDF_VIEWER_INCLUDE_CHECKSUM', true),
        'max_filename_length' => env('PDF_VIEWER_MAX_FILENAME_LENGTH', 200),
        'forbidden_chars' => env('PDF_VIEWER_FORBIDDEN_CHARS', '<>:"/\\|?*'),
        'case_handling' => env('PDF_VIEWER_CASE_HANDLING', 'preserve'), // preserve, lower, upper
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

    /*
    |--------------------------------------------------------------------------
    | Laravel Vapor Configuration
    |--------------------------------------------------------------------------
    */
    'vapor' => [
        'enabled' => env('PDF_VIEWER_VAPOR_ENABLED', false),
        'lambda_timeout' => env('PDF_VIEWER_LAMBDA_TIMEOUT', 900), // 15 minutes max
        'lambda_memory' => env('PDF_VIEWER_LAMBDA_MEMORY', 3008), // Max memory for Lambda
        'temp_directory' => env('PDF_VIEWER_TEMP_DIR', '/tmp'),
        'chunk_size' => env('PDF_VIEWER_CHUNK_SIZE', 1048576), // 1MB chunks for S3 streaming
        'concurrent_uploads' => env('PDF_VIEWER_CONCURRENT_UPLOADS', 5),
    ],
];