<?php

namespace Amazon\Commands;

use Illuminate\Console\Command;
use Amazon\Services\AmazonImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class ProcessAmazonBulkImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazon:process-bulk-import {batchId : The ID of the bulk import batch file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Amazon bulk import batch in an asynchronous background CLI process';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchId = $this->argument('batchId');
        $filePath = "amazon_imports/{$batchId}.json";

        if (!Storage::disk('local')->exists($filePath)) {
            $this->error("Batch file {$filePath} not found.");
            return Command::FAILURE;
        }

        $batchData = json_decode(Storage::disk('local')->get($filePath), true);

        if (!$batchData || empty($batchData['items'])) {
            $this->error("Invalid batch data in {$filePath}.");
            return Command::FAILURE;
        }

        if (!empty($batchData['database'])) {
            config(['database.connections.mysql.database' => $batchData['database']]);
            \Illuminate\Support\Facades\DB::purge('mysql');
            \Illuminate\Support\Facades\DB::reconnect('mysql');
        }

        $amazonImportService = app(AmazonImportService::class);

        $items = $batchData['items'];
        $totalItems = count($items);
        $profitMargin = (float) ($batchData['profit_margin_percent'] ?? 0);
        $markupType = $batchData['markup_type'] ?? 'percentage';
        $fixedMarkup = (float) ($batchData['fixed_markup_amount'] ?? 0);
        $categoryId = isset($batchData['category_id']) ? (int) $batchData['category_id'] : null;
        $defaultStock = (int) ($batchData['default_stock'] ?? 20);
        $status = $batchData['publish_status'] ?? 'published';

        if (!isset($batchData['items_progress']) || !is_array($batchData['items_progress'])) {
            $batchData['items_progress'] = [];
        }

        foreach ($items as $itemKey) {
            if (!isset($batchData['items_progress'][$itemKey])) {
                $batchData['items_progress'][$itemKey] = [
                    'asin_or_url' => $itemKey,
                    'status' => 'pending',
                ];
            }
        }

        $successfulCount = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'completed'));
        $failedCount = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'failed'));

        $batchData['status'] = 'in_progress';
        $batchData['started_at'] = now()->toDateTimeString();
        $batchData['processed_count'] = $successfulCount + $failedCount;
        $batchData['successful_count'] = $successfulCount;
        $batchData['failed_count'] = $failedCount;
        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

        $itemsToProcess = array_values(array_filter($items, function ($itemKey) use ($batchData) {
            return ($batchData['items_progress'][$itemKey]['status'] ?? 'pending') !== 'completed';
        }));

        Log::info("Starting background CLI Amazon bulk import batch {$batchId} with " . count($itemsToProcess) . " remaining of {$totalItems} items.");

        try {
            if (!empty($itemsToProcess)) {
                $amazonImportService->bulkImportAmazonProducts(
                    $itemsToProcess,
                    $profitMargin,
                    $categoryId,
                    $defaultStock,
                    $status,
                    function ($current, $total, $itemResult) use ($filePath, $totalItems, &$batchData) {
                        $itemKey = $itemResult['asin_or_url'];
                        $batchData['items_progress'][$itemKey] = $itemResult;

                        $successfulCount = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'completed'));
                        $failedCount = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'failed'));
                        $processedCount = $successfulCount + $failedCount;

                        $batchData['processed_count'] = $processedCount;
                        $batchData['successful_count'] = $successfulCount;
                        $batchData['failed_count'] = $failedCount;

                        if ($processedCount >= $totalItems) {
                            $batchData['status'] = 'completed';
                            $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
                        }

                        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));
                    },
                    $markupType,
                    $fixedMarkup
                );
            }

            $importedProducts = array_values(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'completed'));
            $errors = array_values(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'failed'));

            $finalResult = [
                'total' => $totalItems,
                'successful' => count($importedProducts),
                'failed' => count($errors),
                'imported_products' => $importedProducts,
                'errors' => $errors,
            ];

            $batchData['processed_count'] = count($importedProducts) + count($errors);
            $batchData['successful_count'] = count($importedProducts);
            $batchData['failed_count'] = count($errors);
            $batchData['status'] = 'completed';
            $batchData['completed_at'] = now()->toDateTimeString();
            $batchData['result'] = $finalResult;
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            Log::info("Completed background CLI Amazon bulk import batch {$batchId}: Successful: {$finalResult['successful']}, Failed: {$finalResult['failed']}");
            $this->info("Batch {$batchId} processed successfully.");
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $batchData['status'] = 'failed';
            $batchData['error'] = $e->getMessage();
            $batchData['failed_at'] = now()->toDateTimeString();
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            Log::error("Failed background CLI Amazon bulk import batch {$batchId}: " . $e->getMessage());
            $this->error("Batch {$batchId} failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
