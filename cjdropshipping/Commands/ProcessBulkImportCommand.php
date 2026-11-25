<?php

namespace CjDropshipping\Commands;

use Illuminate\Console\Command;
use CjDropshipping\Services\CjImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessBulkImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cj:process-bulk-import {batchId : The ID of the bulk import batch file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process CJ Dropshipping bulk import batch in an asynchronous background CLI process';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchId = $this->argument('batchId');
        $filePath = "cj_imports/{$batchId}.json";

        if (!Storage::disk('local')->exists($filePath)) {
            $this->error("Batch file {$filePath} not found.");
            return Command::FAILURE;
        }

        $batchData = json_decode(Storage::disk('local')->get($filePath), true);

        if (!$batchData || empty($batchData['pids'])) {
            $this->error("Invalid batch data in {$filePath}.");
            return Command::FAILURE;
        }

        if (!empty($batchData['database'])) {
            config(['database.connections.mysql.database' => $batchData['database']]);
            \Illuminate\Support\Facades\DB::purge('mysql');
            \Illuminate\Support\Facades\DB::reconnect('mysql');
        }

        $cjImportService = app(CjImportService::class);

        $pids = $batchData['pids'];
        $profitMargin = (float) ($batchData['profit_margin_percent'] ?? 0);
        $categoryId = isset($batchData['category_id']) ? (int) $batchData['category_id'] : null;

        // Initialize items array if not present
        if (empty($batchData['items'])) {
            $itemsMap = [];
            foreach ($pids as $p) {
                $itemsMap[$p] = [
                    'pid' => $p,
                    'status' => 'pending',
                ];
            }
            $batchData['items'] = array_values($itemsMap);
        }

        // Mark status as in_progress
        $batchData['status'] = 'in_progress';
        $batchData['started_at'] = $batchData['started_at'] ?? now()->toDateTimeString();
        $batchData['processed_count'] = $batchData['processed_count'] ?? 0;
        $batchData['successful_count'] = $batchData['successful_count'] ?? 0;
        $batchData['failed_count'] = $batchData['failed_count'] ?? 0;
        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

        $completedPids = [];
        foreach ($batchData['items'] as $existingItem) {
            if (($existingItem['status'] ?? '') === 'completed' && !empty($existingItem['pid'])) {
                $completedPids[(string) $existingItem['pid']] = true;
            }
        }
        $remainingPids = array_values(array_filter($pids, fn($p) => !isset($completedPids[(string) $p])));
        $totalPids = count($pids);

        Log::info("Starting background CLI CJ bulk import batch {$batchId} with " . count($remainingPids) . " remaining of {$totalPids} items.");

        try {
            if (!empty($remainingPids)) {
                $cjImportService->bulkImportCjProducts(
                    $remainingPids,
                    $profitMargin,
                    $categoryId,
                    function ($current, $total, $itemResult) use ($filePath, $totalPids, &$batchData) {
                        $pid = $itemResult['pid'];

                        if (isset($batchData['items']) && is_array($batchData['items'])) {
                            foreach ($batchData['items'] as $i => $item) {
                                if (($item['pid'] ?? '') === $pid) {
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

                        if ($processedCount >= $totalPids) {
                            $batchData['status'] = 'completed';
                            $batchData['completed_at'] = $batchData['completed_at'] ?? now()->toDateTimeString();
                        }

                        Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));
                    }
                );
            }

            $importedProducts = array_values(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'completed'));
            $errors = array_values(array_filter($batchData['items'], fn($i) => ($i['status'] ?? '') === 'failed'));
            $result = [
                'total' => $totalPids,
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
            $batchData['result'] = $result;
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            Log::info("Completed background CLI CJ bulk import batch {$batchId}: Successful: {$result['successful']}, Failed: {$result['failed']}");
            $this->info("Batch {$batchId} processed successfully.");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $batchData['status'] = 'failed';
            $batchData['error'] = $e->getMessage();
            $batchData['failed_at'] = now()->toDateTimeString();
            Storage::disk('local')->put($filePath, json_encode($batchData, JSON_PRETTY_PRINT));

            Log::error("Failed background CLI CJ bulk import batch {$batchId}: " . $e->getMessage());
            $this->error("Batch {$batchId} failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
