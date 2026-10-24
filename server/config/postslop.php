<?php

declare(strict_types=1);

return [
    'sessions_file' => env('POSTSLOP_SESSIONS_FILE', dirname(__DIR__) . '/storage/sessions.json'),
    'lock_file' => env('POSTSLOP_LOCK_FILE', dirname(__DIR__) . '/storage/publishing.lock'),
    'media_path' => env('POSTSLOP_MEDIA_PATH', dirname(__DIR__) . '/storage/media'),
    // The access token, the web client's password; see AccessToken.
    'token' => (string) env('POSTSLOP_TOKEN', ''),
    'x_transport' => env('POSTSLOP_X_TRANSPORT', 'browser'),
    'node_binary' => env(
        'POSTSLOP_NODE_BINARY',
        is_executable('/opt/homebrew/bin/node') ? '/opt/homebrew/bin/node' : 'node',
    ),
    'x_browser_worker' => dirname(__DIR__) . '/browser-worker/x-worker.mjs',
    'x_browser_profile' => env('POSTSLOP_X_BROWSER_PROFILE', dirname(__DIR__) . '/storage/browser/x'),
    'x_url' => env('POSTSLOP_X_URL', 'https://x.com'),
    'x_bundle_url' => env('POSTSLOP_X_BUNDLE_URL', ''),
    'x_upload_url' => env('POSTSLOP_X_UPLOAD_URL', 'https://upload.x.com'),
    'bluesky_url' => env('POSTSLOP_BLUESKY_URL', ''),
    'dev_null_url' => env('POSTSLOP_DEV_NULL_URL', 'https://dev-null.sh'),
    'linkedin_url' => env('POSTSLOP_LINKEDIN_URL', 'https://www.linkedin.com'),
    'threads_url' => env('POSTSLOP_THREADS_URL', 'https://www.threads.com'),
];
