<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AliExpress Dropshipping Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for AliExpress product import, API integration,
    | scraper settings, profit margin defaults, and background syncing.
    |
    */

    'api_key' => env('ALIEXPRESS_API_KEY', ''),
    'app_key' => env('ALIEXPRESS_APP_KEY', ''),
    'app_secret' => env('ALIEXPRESS_APP_SECRET', ''),
    'tracking_id' => env('ALIEXPRESS_TRACKING_ID', ''),
    'rapidapi_key' => env('ALIEXPRESS_RAPIDAPI_KEY', ''),
    'rapidapi_host' => env('ALIEXPRESS_RAPIDAPI_HOST', 'aliexpress-datahub.p.rapidapi.com'),
    'scraper_provider' => env('ALIEXPRESS_SCRAPER_PROVIDER', 'auto'), // auto, rapidapi, direct_web
    'default_profit_margin' => (float) env('ALIEXPRESS_PROFIT_MARGIN', 20.0),
    'default_stock' => (int) env('ALIEXPRESS_DEFAULT_STOCK', 50),
    'auto_sync' => env('ALIEXPRESS_AUTO_SYNC', true),
    'currency' => env('ALIEXPRESS_CURRENCY', 'USD'),
    'ship_to_country' => env('ALIEXPRESS_SHIP_TO_COUNTRY', 'US'),
];
