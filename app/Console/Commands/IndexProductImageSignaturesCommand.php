<?php

namespace App\Console\Commands;

use App\Services\ProductImageSearchService;
use Illuminate\Console\Command;

class IndexProductImageSignaturesCommand extends Command
{
    protected $signature = 'products:index-image-signatures {--fresh : Re-index all published products from scratch}';

    protected $description = 'Build perceptual dHash & color histogram visual search signatures for all published products';

    public function handle(ProductImageSearchService $service): int
    {
        $fresh = (bool) $this->option('fresh');
        $this->info($fresh ? 'Re-indexing all published product images...' : 'Indexing new/updated published product images...');

        $stats = $service->indexProductSignatures($fresh, 20);

        $this->info("Done! Total: {$stats['total']}, Indexed: {$stats['indexed']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}");

        return self::SUCCESS;
    }
}
