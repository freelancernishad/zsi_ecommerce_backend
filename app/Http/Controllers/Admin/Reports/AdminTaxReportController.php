<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTaxReportController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $country = $request->input('country');
        $state = $request->input('state');
        $paymentStatus = $request->input('payment_status', 'all');
        $search = $request->input('search');

        $query = Order::query();

        if (!empty($startDate)) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if (!empty($endDate)) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        if (!empty($country)) {
            $query->where('shipping_country', 'LIKE', '%' . trim($country) . '%');
        }

        if (!empty($state)) {
            $query->where('shipping_state', 'LIKE', '%' . trim($state) . '%');
        }

        if ($paymentStatus !== 'all' && !empty($paymentStatus)) {
            $query->where('payment_status', $paymentStatus);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                  ->orWhere('shipping_first_name', 'LIKE', "%{$search}%")
                  ->orWhere('shipping_last_name', 'LIKE', "%{$search}%")
                  ->orWhere('shipping_city', 'LIKE', "%{$search}%");
            });
        }

        // Summary calculations
        $totalOrdersCount = (clone $query)->count();
        $taxedOrdersCount = (clone $query)->where('tax', '>', 0)->count();
        $totalTaxCollected = (float) (clone $query)->sum('tax');
        $totalSubtotal = (float) (clone $query)->sum('subtotal');
        $totalSalesWithTax = (float) (clone $query)->sum('total');

        $averageEffectiveRate = $totalSubtotal > 0 ? round(($totalTaxCollected / $totalSubtotal) * 100, 2) : 0;

        // Breakdown by State and Country
        $stateBreakdown = (clone $query)
            ->select(
                'shipping_country',
                'shipping_state',
                DB::raw('COUNT(id) as order_count'),
                DB::raw('SUM(tax) as total_tax'),
                DB::raw('SUM(subtotal) as total_subtotal')
            )
            ->whereNotNull('shipping_state')
            ->groupBy('shipping_country', 'shipping_state')
            ->orderBy('total_tax', 'desc')
            ->get();

        // Paginated Order List
        $orders = (clone $query)
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        // Available Filter Options
        $availableCountries = Order::whereNotNull('shipping_country')
            ->distinct()
            ->pluck('shipping_country')
            ->filter()
            ->values();

        $availableStates = Order::whereNotNull('shipping_state')
            ->distinct()
            ->pluck('shipping_state')
            ->filter()
            ->values();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_orders' => $totalOrdersCount,
                'taxed_orders' => $taxedOrdersCount,
                'total_tax_collected' => round($totalTaxCollected, 2),
                'total_taxable_subtotal' => round($totalSubtotal, 2),
                'total_sales_with_tax' => round($totalSalesWithTax, 2),
                'average_effective_rate' => $averageEffectiveRate,
            ],
            'state_breakdown' => $stateBreakdown,
            'available_filters' => [
                'countries' => $availableCountries,
                'states' => $availableStates,
            ],
            'orders' => $orders,
        ]);
    }
}
