<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(\AliExpress\Services\AliExpressImportService::class);
$product = $service->importAliExpressProduct('100500123456789', 25.0);

echo "SUCCESSFULLY IMPORTED!\n";
echo "ID: " . $product->id . "\n";
echo "Name: " . $product->name . "\n";
echo "SKU: " . $product->sku . "\n";
echo "Price: " . $product->price . "\n";
echo "AliExpress Product ID: " . $product->aliexpress_product_id . "\n";
echo "Is AliExpress Product: " . ($product->is_aliexpress_product ? 'YES' : 'NO') . "\n";
