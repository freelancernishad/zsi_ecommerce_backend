<?php

namespace Amazon\Commands;

use Illuminate\Console\Command;
use Amazon\Services\AmazonImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

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
    public function handle(AmazonImportService $amazonImportService)
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

        $items = $batchData['items'];
        $profitMargin = (float) ($batchData['profit_margin_percent'] ?? 15);
        $categoryId = isset($batchData['category_id']) ? (int) $batchData['category_id'] : null;
        $defaultStock = (int) ($batchData['default_stock'] ?? 20);
        $status = $batchData['publish_status'] ?? 'published';

        $batchData['status'] = 'in_progress';
        $batchData['started_at'] = $batchData['started_at'] ?? now()->toDateTimeString();
        $batchData['processed_count'] = $batchData['processed_count'] ?? 0;
        $batchData['successful_count'] = $batchData['successful_count'] ?? 0;
        $batchData['failed_count'] = $batchData['failed_count'] ?? 0;
        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

        Log::info("Starting background CLI Amazon bulk import batch {$batchId} with " . count($items) . " items.");

        try {
            $result = $amazonImportService->bulkImportAmazonProducts(
                $items,
                $profitMargin,
                $categoryId,
                $defaultStock,
                $status,
                function ($current, $total, $itemResult) use ($filePath, &$batchData) {
                    $itemKey = $itemResult['asin_or_url'];

                    if (isset($batchData['items_progress']) && is_array($batchData['items_progress'])) {
                        $batchData['items_progress'][$itemKey] = $itemResult;
                    } else {
                        $batchData['items_progress'] = [$itemKey => $itemResult];
                    }

                    $batchData['processed_count'] = $current;
                    $batchData['successful_count'] = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'completed'));
                    $batchData['failed_count'] = count(array_filter($batchData['items_progress'], fn($i) => ($i['status'] ?? '') === 'failed'));

                    if ($current >= $total) {
                        $batchData['status'] = 'completed';
                        $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
                    }

                    Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));
                }
            );

            $batchData['status'] = 'completed';
            $batchData['completed_at'] = now()->toDateTimeString();
            $batchData['result'] = $result;
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            Log::info("Completed background CLI Amazon bulk import batch {$batchId}: Successful: {$result['successful']}, Failed: {$result['failed']}");
            $this->info("Batch {$batchId} processed successfully.");
            return Command::SUCCESS;
        } catch (Exception $e) {
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
