<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEcommerceDashboardController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period', 30); // days

        // ── Stats Cards ──────────────────────────────────────────────────────
        $currentRevenue  = Order::where('payment_status', 'paid')
            ->where('created_at', '>=', now()->subDays($period))->sum('total');
        $previousRevenue = Order::where('payment_status', 'paid')
            ->whereBetween('created_at', [now()->subDays($period * 2), now()->subDays($period)])->sum('total');
        $revenueTrend    = $previousRevenue > 0
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1) : 0;

        $currentOrders  = Order::where('created_at', '>=', now()->subDays($period))->count();
        $previousOrders = Order::whereBetween('created_at', [now()->subDays($period * 2), now()->subDays($period)])->count();
        $orderTrend     = $previousOrders > 0
            ? round((($currentOrders - $previousOrders) / $previousOrders) * 100, 1) : 0;

        $totalUsers  = User::where('role', 'user')->count();
        $newUsers    = User::where('role', 'user')->where('created_at', '>=', now()->subDays($period))->count();
        $prevUsers   = User::where('role', 'user')->whereBetween('created_at', [now()->subDays($period * 2), now()->subDays($period)])->count();
        $userTrend   = $prevUsers > 0 ? round((($newUsers - $prevUsers) / $prevUsers) * 100, 1) : 0;

        $netProfit = round($currentRevenue * 0.72, 2); // approx 72% margin

        // ── Monthly Statistics Chart ──────────────────────────────────────────
        $monthlyStats = Order::select(
                DB::raw("DATE_FORMAT(created_at, '%b') as month"),
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month_key"),
                DB::raw("SUM(total) as income"),
                DB::raw("COUNT(*) as sales")
            )
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month_key', 'month')
            ->orderBy('month_key')
            ->get()
            ->map(fn($r) => [
                'month'  => $r->month,
                'income' => (float) $r->income,
                'sales'  => (int) $r->sales,
            ]);

        // ── Balance Breakdown ─────────────────────────────────────────────────
        $totalRevenue = Order::where('payment_status', 'paid')->sum('total');
        $totalTax     = Order::where('payment_status', 'paid')->sum('tax');
        $expenses     = $totalRevenue * 0.18;
        $profit       = $totalRevenue - $totalTax - $expenses;

        $balanceBreakdown = [
            ['name' => 'Net Profit', 'value' => round($profit, 2),   'color' => '#2563EB'],
            ['name' => 'Expenses',   'value' => round($expenses, 2), 'color' => '#FBBF24'],
            ['name' => 'Tax',        'value' => round($totalTax, 2), 'color' => '#F97316'],
        ];

        // ── Recent Orders ─────────────────────────────────────────────────────
        $recentOrders = Order::with(['user', 'items.product'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($order) {
                $firstItem   = $order->items->first();
                $othersCount = $order->items->count() - 1;
                return [
                    'id'      => $order->order_number,
                    'product' => $firstItem ? ($firstItem->product->name ?? 'Product') : 'N/A',
                    'items'   => $othersCount > 0 ? "+{$othersCount} others" : '',
                    'date'    => $order->created_at->format('m/d/Y'),
                    'payment' => strtoupper($order->payment_method ?? 'CARD'),
                    'amount'  => (float) $order->total,
                    'status'  => ucfirst($order->status ?? 'pending'),
                ];
            });

        // ── Top Products ──────────────────────────────────────────────────────
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.payment_status', 'paid')
            ->select(
                'products.id',
                'products.name',
                'products.price',
                'products.original_price',
                DB::raw('SUM(order_items.quantity) as total_sold')
            )
            ->groupBy('products.id', 'products.name', 'products.price', 'products.original_price')
            ->orderByDesc('total_sold')
            ->take(4)
            ->get()
            ->map(function ($p) {
                $avg = Review::where('product_id', $p->id)->avg('rating') ?? 0;
                $cnt = Review::where('product_id', $p->id)->count();
                return [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'price'         => (float) $p->price,
                    'originalPrice' => $p->original_price ? (float) $p->original_price : null,
                    'rating'        => round($avg, 1),
                    'reviews'       => $cnt,
                    'status'        => 'In Stock',
                ];
            });

        // ── Top Customers ─────────────────────────────────────────────────────
        $topCustomers = Order::where('payment_status', 'paid')
            ->select('user_id',
                DB::raw('COUNT(*) as purchases'),
                DB::raw('SUM(total) as total_spent')
            )
            ->with('user:id,name')
            ->groupBy('user_id')
            ->orderByDesc('total_spent')
            ->take(5)
            ->get()
            ->map(fn($o) => [
                'id'        => $o->user_id,
                'name'      => $o->user->name ?? 'Unknown',
                'purchases' => (int) $o->purchases,
                'total'     => (float) $o->total_spent,
            ]);

        // ── Satisfaction Rate ─────────────────────────────────────────────────
        $avgRating = Review::avg('rating') ?? 0;
        $satisfactionRate = round(($avgRating / 5) * 100, 2);

        // ── Top Locations ─────────────────────────────────────────────────────
        $totalPaidOrders = Order::where('payment_status', 'paid')->count() ?: 1;
        $topLocations = Order::where('payment_status', 'paid')
            ->select('shipping_state as name', DB::raw('COUNT(*) as count'))
            ->whereNotNull('shipping_state')
            ->where('shipping_state', '!=', '')
            ->groupBy('shipping_state')
            ->orderByDesc('count')
            ->take(5)
            ->get()
            ->map(fn($l) => [
                'name'       => $l->name,
                'percentage' => round(($l->count / $totalPaidOrders) * 100, 1),
            ]);

        return response()->json([
            'isError' => false,
            'data' => [
                'stats' => [
                    'total_revenue'  => round($currentRevenue, 2),
                    'net_profit'     => $netProfit,
                    'total_orders'   => $currentOrders,
                    'total_users'    => $totalUsers,
                    'revenue_trend'  => $revenueTrend,
                    'order_trend'    => $orderTrend,
                    'user_trend'     => $userTrend,
                ],
                'monthly_stats'       => $monthlyStats,
                'balance_breakdown'   => $balanceBreakdown,
                'recent_orders'       => $recentOrders,
                'top_products'        => $topProducts,
                'top_customers'       => $topCustomers,
                'top_locations'       => $topLocations,
                'satisfaction_rate'   => $satisfactionRate,
            ],
        ]);
    }
}
