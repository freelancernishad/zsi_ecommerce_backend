<?php

namespace AliExpress\Commands;

use Illuminate\Console\Command;
use AliExpress\Services\AliExpressImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

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
    public function handle(AliExpressImportService $importService)
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

        $itemsInput = $batchData['items_input'];
        $profitMargin = (float)($batchData['profit_margin_percent'] ?? 20);
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

        Log::info("Starting background CLI AliExpress bulk import batch {$batchId} with " . count($itemsInput) . " items.");

        try {
            $result = $importService->bulkImportAliExpressProducts(
                $itemsInput,
                $profitMargin,
                $categoryId,
                $defaultStock,
                $status,
                function ($current, $total, $itemResult) use ($filePath, &$batchData) {
                    $itemInput = $itemResult['product_id_or_url'];

                    if (isset($batchData['items']) && is_array($batchData['items'])) {
                        foreach ($batchData['items'] as $i => $item) {
                            if (($item['product_id_or_url'] ?? '') === $itemInput) {
                                $batchData['items'][$i] = $itemResult;
                                break;
                            }
                        }
                    }

                    $batchData['processed_count'] = $current;
                    $batchData['successful_count'] = count(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'completed'));
                    $batchData['failed_count'] = count(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'failed'));

                    if ($current >= $total) {
                        $batchData['status'] = 'completed';
                        $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
                    }

                    Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));
                }
            );

            $this->info("Completed AliExpress bulk import batch {$batchId}.");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $batchData['status'] = 'failed';
            $batchData['error'] = $e->getMessage();
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            $this->error("Failed bulk import batch {$batchId}: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
