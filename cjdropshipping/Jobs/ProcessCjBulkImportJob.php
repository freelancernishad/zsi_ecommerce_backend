<?php

namespace CjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use CjDropshipping\Services\CjImportService;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessCjBulkImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes timeout

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    public array $cjProductIds;
    public float $profitMarginPercent;
    public ?int $categoryId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $cjProductIds, float $profitMarginPercent = 20.0, ?int $categoryId = null)
    {
        $this->cjProductIds = $cjProductIds;
        $this->profitMarginPercent = $profitMarginPercent;
        $this->categoryId = $categoryId;
    }

    /**
     * Execute the job.
     */
    public function handle(CjImportService $cjImportService): void
    {
        $count = count($this->cjProductIds);
        Log::info("Starting background CJ bulk import job for {$count} products.");

        try {
            $result = $cjImportService->bulkImportCjProducts(
                $this->cjProductIds,
                $this->profitMarginPercent,
                $this->categoryId
            );

            Log::info("Background CJ bulk import completed successfully.", [
                'total' => $result['total'],
                'successful' => $result['successful'],
                'failed' => $result['failed'],
            ]);
        } catch (Exception $e) {
            Log::error("Background CJ bulk import failed: " . $e->getMessage());
        }
    }
}
