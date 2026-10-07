<?php

function app_config(): array {
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.local.php';
        if (!file_exists($file)) {
            http_response_code(500);
            die(
                'Missing config.local.php. Copy config.local.php.example to config.local.php ' .
                'and fill in your Google API key and DB credentials.'
            );
        }
        $config = require $file;
    }
    return $config;
}

function has_google_api_key(): bool {
    $key = app_config()['google_api_key'] ?? '';
    return $key !== '' && $key !== 'YOUR_KEY_HERE';
}

function has_foursquare_api_key(): bool {
    $key = app_config()['foursquare_api_key'] ?? '';
    return $key !== '' && $key !== 'YOUR_KEY_HERE';
}

function has_google_search_key(): bool {
    $c = app_config();
    $key = $c['google_search_api_key'] ?? '';
    $engineId = $c['google_search_engine_id'] ?? '';
    return $key !== '' && $key !== 'YOUR_KEY_HERE' && $engineId !== '' && $engineId !== 'YOUR_KEY_HERE';
}

function has_abstractapi_key(): bool {
    $key = app_config()['abstractapi_key'] ?? '';
    return $key !== '' && $key !== 'YOUR_KEY_HERE';
}

function has_ai_api_key(): bool {
    $key = app_config()['ai_api_key'] ?? '';
    return $key !== '' && $key !== 'YOUR_KEY_HERE';
}

function has_smtp_config(): bool {
    $c = app_config();
    $pass = $c['smtp_pass'] ?? '';
    return ($c['smtp_host'] ?? '') !== '' && ($c['smtp_user'] ?? '') !== '' && $pass !== '' && $pass !== 'YOUR_PASSWORD';
}
