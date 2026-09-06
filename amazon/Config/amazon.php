<?php

return [
    'brightdata_api_key' => env('BRIGHTDATA_API_KEY', ''),
    'brightdata_zone' => env('BRIGHTDATA_ZONE', ''),
    'scraper_provider' => env('AMAZON_SCRAPER_PROVIDER', 'brightdata'),
    'default_profit_margin' => env('AMAZON_PROFIT_MARGIN', 15),
    'default_stock' => env('AMAZON_DEFAULT_STOCK', 20),
    'auto_sync' => env('AMAZON_AUTO_SYNC', true),
];
