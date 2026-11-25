<?php

namespace AliExpress\Commands;

use Illuminate\Console\Command;
use AliExpress\Services\AliExpressImportService;
use AliExpress\Services\AliExpressService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class ProcessAliExpressBulkImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aliexpress:process-bulk-import {batchId : The ID of the bulk import batch file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process AliExpress bulk import batch in an asynchronous background CLI process';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchId = $this->argument('batchId');
        $filePath = "aliexpress_imports/{$batchId}.json";

        if (!Storage::disk('local')->exists($filePath)) {
            $this->error("Batch file {$filePath} not found.");
            return Command::FAILURE;
        }

        $batchData = json_decode(Storage::disk('local')->get($filePath), true);

        if (!$batchData || empty($batchData['items_input'])) {
            $this->error("Invalid batch data in {$filePath}.");
            return Command::FAILURE;
        }

        if (!empty($batchData['database'])) {
            config(['database.connections.mysql.database' => $batchData['database']]);
            \Illuminate\Support\Facades\DB::purge('mysql');
            \Illuminate\Support\Facades\DB::reconnect('mysql');
        }

        app(AliExpressService::class)->loadSettings();
        $importService = app(AliExpressImportService::class);

        $itemsInput = $batchData['items_input'];
        $profitMargin = (float)($batchData['profit_margin_percent'] ?? 0);
        $categoryId = isset($batchData['category_id']) ? (int)$batchData['category_id'] : null;
        $defaultStock = (int)($batchData['default_stock'] ?? 50);
        $status = $batchData['product_status'] ?? 'published';

        if (empty($batchData['items'])) {
            $itemsMap = [];
            foreach ($itemsInput as $p) {
                $itemsMap[$p] = [
                    'product_id_or_url' => $p,
                    'status' => 'pending',
                ];
            }
            $batchData['items'] = array_values($itemsMap);
        }

        $batchData['status'] = 'in_progress';
        $batchData['started_at'] = $batchData['started_at'] ?? now()->toDateTimeString();
        $batchData['processed_count'] = $batchData['processed_count'] ?? 0;
        $batchData['successful_count'] = $batchData['successful_count'] ?? 0;
        $batchData['failed_count'] = $batchData['failed_count'] ?? 0;
        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

        $completedInputs = [];
        foreach ($batchData['items'] as $existingItem) {
            if (($existingItem['status'] ?? '') === 'completed' && !empty($existingItem['product_id_or_url'])) {
                $completedInputs[(string) $existingItem['product_id_or_url']] = true;
            }
        }
        $remainingInputs = array_values(array_filter($itemsInput, fn($p) => !isset($completedInputs[(string) $p])));
        $totalItems = count($itemsInput);

        Log::info("Starting background CLI AliExpress bulk import batch {$batchId} with " . count($remainingInputs) . " remaining of {$totalItems} items.");

        try {
            if (!empty($remainingInputs)) {
                $importService->bulkImportAliExpressProducts(
                    $remainingInputs,
                    $profitMargin,
                    $categoryId,
                    $defaultStock,
                    $status,
                    function ($current, $total, $itemResult) use ($filePath, $totalItems, &$batchData) {
                        $itemInput = $itemResult['product_id_or_url'];

                        if (isset($batchData['items']) && is_array($batchData['items'])) {
                            foreach ($batchData['items'] as $i => $item) {
                                if (($item['product_id_or_url'] ?? '') === $itemInput) {
                                    $batchData['items'][$i] = $itemResult;
                                    break;
                                }
                            }
                        }

                        $successfulCount = count(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'completed'));
                        $failedCount = count(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'failed'));
                        $processedCount = $successfulCount + $failedCount;

                        $batchData['processed_count'] = $processedCount;
                        $batchData['successful_count'] = $successfulCount;
                        $batchData['failed_count'] = $failedCount;

                        if ($processedCount >= $totalItems) {
                            $batchData['status'] = 'completed';
                            $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
                        }

                        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));
                    }
                );
            }

            $importedProducts = array_values(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'completed'));
            $errors = array_values(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'failed'));
            $batchData['processed_count'] = count($importedProducts) + count($errors);
            $batchData['successful_count'] = count($importedProducts);
            $batchData['failed_count'] = count($errors);
            $batchData['status'] = 'completed';
            $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            $this->info("Completed AliExpress bulk import batch {$batchId}.");
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $batchData['status'] = 'failed';
            $batchData['error'] = $e->getMessage();
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            $this->error("Failed bulk import batch {$batchId}: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
