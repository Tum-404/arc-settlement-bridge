<?php

use Opcodes\LogViewer\Enums\SortingMethod;
use Opcodes\LogViewer\Enums\SortingOrder;
use Opcodes\LogViewer\Enums\Theme;
use Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer;

$appEnvironment = env('APP_ENV', 'local');
$appEnvironment = is_string($appEnvironment) && $appEnvironment !== '' ? $appEnvironment : 'local';
$apiStatefulDomains = env('LOG_VIEWER_API_STATEFUL_DOMAINS');

return [
    'enabled' => env('LOG_VIEWER_ENABLED', true),
    'api_only' => env('LOG_VIEWER_API_ONLY', false),
    'require_auth_in_production' => true,
    'route_domain' => null,
    'route_path' => 'log-viewer',
    'assets_path' => 'vendor/log-viewer',
    'back_to_system_url' => config('app.url', null),
    'back_to_system_label' => null,
    'timezone' => null,
    'datetime_format' => 'Y-m-d H:i:s',

    // Logs can contain provider payloads and operational metadata; always require an operator session.
    'middleware' => [
        'web',
        'auth',
        AuthorizeLogViewer::class,
    ],

    'api_middleware' => [
        'web',
        'auth',
        AuthorizeLogViewer::class,
    ],

    'api_stateful_domains' => is_string($apiStatefulDomains) && $apiStatefulDomains !== '' ? explode(',', $apiStatefulDomains) : null,
    'hosts' => [
        'local' => [
            'name' => ucfirst($appEnvironment),
        ],
    ],
    'include_files' => [
        '*.log',
        '**/*.log',
        '/var/log/httpd/*' => 'Apache',
        '/var/log/nginx/*' => 'Nginx',
        '/opt/homebrew/var/log/nginx/*',
        '/opt/homebrew/var/log/httpd/*',
        '/opt/homebrew/var/log/php-fpm.log',
        '/opt/homebrew/var/log/postgres*log',
        '/opt/homebrew/var/log/redis*log',
        '/opt/homebrew/var/log/supervisor*log',
    ],
    'exclude_files' => [],
    'hide_unknown_files' => true,
    'shorter_stack_trace_excludes' => [
        '/vendor/symfony/',
        '/vendor/laravel/framework/',
        '/vendor/barryvdh/laravel-debugbar/',
    ],
    'cache_driver' => env('LOG_VIEWER_CACHE_DRIVER', null),
    'cache_key_prefix' => 'lv',
    'lazy_scan_chunk_size_in_mb' => 50,
    'strip_extracted_context' => true,
    'per_page_options' => [10, 25, 50, 100, 250, 500],
    'defaults' => [
        'use_local_storage' => true,
        'folder_sorting_method' => SortingMethod::ModifiedTime,
        'folder_sorting_order' => SortingOrder::Descending,
        'file_sorting_method' => SortingMethod::ModifiedTime,
        'file_sorting_order' => SortingOrder::Descending,
        'log_sorting_order' => SortingOrder::Descending,
        'per_page' => 25,
        'theme' => Theme::System,
        'shorter_stack_traces' => false,
    ],
    'exclude_ip_from_identifiers' => env('LOG_VIEWER_EXCLUDE_IP_FROM_IDENTIFIERS', false),
    'root_folder_prefix' => 'root',
];
