<?php

namespace Amazon\Controllers;

use Amazon\Services\AmazonDatasetImportService;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;

class AmazonDatasetImportController extends Controller
{
    protected AmazonDatasetImportService $datasetService;

    public function __construct(AmazonDatasetImportService $datasetService)
    {
        $this->datasetService = $datasetService;
    }

    /**
     * Get Bright Data Dataset API import configuration status.
     */
    public function config()
    {
        return response()->json([
            'success' => true,
            'data' => $this->datasetService->getConfig(),
        ]);
    }

    /**
     * Fetch & preview Amazon products via Bright Data Dataset API for a target US ZIP code.
     */
    public function preview(Request $request)
    {
        @set_time_limit(600);

        $request->validate([
            'asins_or_urls' => 'required',
            'zipcode' => 'nullable|string|max:16',
            'enrich_variants' => 'nullable|boolean',
            'dataset_id' => 'nullable|string|max:120',
        ]);

        $items = $this->normalizeInputs($request->input('asins_or_urls'));
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide at least one Amazon ASIN or product URL.',
            ], 422);
        }

        $zipcode = (string) ($request->input('zipcode') ?: AmazonDatasetImportService::DEFAULT_ZIPCODE);
        $enrichVariants = true;
        $datasetId = $request->input('dataset_id');

        try {
            $result = $this->datasetService->previewProducts($items, $zipcode, $enrichVariants, $datasetId);
            return response()->json([
                'success' => true,
                'message' => "Checked {$result['summary']['total']} Amazon product(s) for ZIP {$result['zipcode']} via Bright Data Dataset API.",
                'data' => $result,
            ]);
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Import products via Bright Data Dataset API (from prefetched items or fresh fetch).
     */
    public function import(Request $request)
    {
        @set_time_limit(600);

        $request->validate([
            'asins_or_urls' => 'nullable',
            'prefetched_items' => 'nullable|array',
            'zipcode' => 'nullable|string|max:16',
            'dataset_id' => 'nullable|string|max:120',
            'enrich_variants' => 'nullable|boolean',
            'block_undeliverable' => 'nullable|boolean',
            'profit_margin_percent' => 'nullable|numeric|min:-99',
            'markup_type' => 'nullable|string|in:percentage,fixed',
            'fixed_markup_amount' => 'nullable|numeric',
            'category_id' => 'nullable|exists:categories,id',
            'default_stock' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:published,draft',
        ]);

        $items = $this->normalizeInputs($request->input('asins_or_urls'));
        $prefetchedItems = $request->input('prefetched_items');

        if (empty($items) && empty($prefetchedItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide asins_or_urls or prefetched_items to import.',
            ], 422);
        }

        $options = [
            'zipcode' => (string) ($request->input('zipcode') ?: AmazonDatasetImportService::DEFAULT_ZIPCODE),
            'dataset_id' => $request->input('dataset_id'),
            'enrich_variants' => true,
            'block_undeliverable' => true,
            'profit_margin_percent' => (float) $request->input('profit_margin_percent', 0),
            'markup_type' => (string) $request->input('markup_type', 'percentage'),
            'fixed_markup_amount' => (float) $request->input('fixed_markup_amount', 0),
            'category_id' => $request->input('category_id') ? (int) $request->input('category_id') : null,
            'default_stock' => (int) $request->input('default_stock', 20),
            'status' => (string) $request->input('status', 'published'),
        ];

        try {
            $result = $this->datasetService->importProducts($items, $options, $prefetchedItems);
            return response()->json([
                'success' => true,
                'message' => "Dataset import finished for ZIP {$result['zipcode']}: {$result['imported_count']} imported, {$result['skipped_count']} skipped, {$result['failed_count']} failed.",
                'data' => $result,
            ]);
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Normalize string (newline/comma-separated) or array input into trimmed non-empty lines.
     */
    protected function normalizeInputs($raw): array
    {
        if (is_array($raw)) {
            $list = [];
            foreach ($raw as $entry) {
                foreach (preg_split('/[\r\n,]+/', (string) $entry) as $part) {
                    $clean = trim($part);
                    if ($clean !== '') {
                        $list[] = $clean;
                    }
                }
            }
            return array_values(array_unique($list));
        }

        if (is_string($raw)) {
            $list = [];
            foreach (preg_split('/[\r\n,]+/', $raw) as $part) {
                $clean = trim($part);
                if ($clean !== '') {
                    $list[] = $clean;
                }
            }
            return array_values(array_unique($list));
        }

        return [];
    }
}
