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

        // ── Best Selling Products ─────────────────────────────────────────────
        $bestSellingProducts = DB::table('order_items')
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
            ->take(5)
            ->get()
            ->map(function ($p) {
                // Get first image
                $image = DB::table('product_images')->where('product_id', $p->id)->first();
                $imagePath = $image ? asset('storage/' . $image->image_path) : null;

                $avg = Review::where('product_id', $p->id)->avg('rating') ?? 0;
                $cnt = Review::where('product_id', $p->id)->count();
                return [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'price'         => (float) $p->price,
                    'originalPrice' => $p->original_price ? (float) $p->original_price : null,
                    'image'         => $imagePath,
                    'rating'        => round($avg, 1),
                    'reviews'       => $cnt,
                    'status'        => 'Top Sales',
                    'color'         => 'bg-emerald-50 text-emerald-700 border-emerald-100'
                ];
            });

        // ── Best Rated Products ───────────────────────────────────────────────
        $bestRatedProducts = Product::withCount(['reviews as reviews_count'])
            ->withAvg('reviews as rating', 'rating')
            ->having('reviews_count', '>', 0)
            ->orderByDesc('rating')
            ->orderByDesc('reviews_count')
            ->take(5)
            ->get()
            ->map(function ($p) {
                // Get first image
                $image = DB::table('product_images')->where('product_id', $p->id)->first();
                $imagePath = $image ? asset('storage/' . $image->image_path) : null;

                return [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'price'         => (float) $p->price,
                    'originalPrice' => $p->original_price ? (float) $p->original_price : null,
                    'image'         => $imagePath,
                    'rating'        => round($p->rating, 1),
                    'reviews'       => $p->reviews_count,
                    'status'        => 'Best Rated',
                    'color'         => 'bg-amber-50 text-amber-700 border-amber-100'
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

        // ── High Revenue Categories ───────────────────────────────────────────
        $categoryRevenueCurrent = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.created_at', '>=', now()->subDays($period))
            ->select('categories.id', DB::raw('SUM(order_items.quantity * order_items.price) as revenue'))
            ->groupBy('categories.id')
            ->get()
            ->pluck('revenue', 'id');

        $categoryRevenuePrevious = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [now()->subDays($period * 2), now()->subDays($period)])
            ->select('categories.id', DB::raw('SUM(order_items.quantity * order_items.price) as revenue'))
            ->groupBy('categories.id')
            ->get()
            ->pluck('revenue', 'id');

        $topCategories = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.payment_status', 'paid')
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('SUM(order_items.quantity * order_items.price) as total_revenue')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->take(4)
            ->get()
            ->map(function ($c) use ($categoryRevenueCurrent, $categoryRevenuePrevious) {
                $current  = $categoryRevenueCurrent[$c->id] ?? 0;
                $previous = $categoryRevenuePrevious[$c->id] ?? 0;
                $growth   = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : 0;

                return [
                    'id'      => $c->id,
                    'name'    => $c->name,
                    'sold'    => (int) $c->total_sold,
                    'revenue' => (float) $c->total_revenue,
                    'growth'  => ($growth >= 0 ? '+' : '') . $growth . '%',
                ];
            });

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
                'best_selling_products' => $bestSellingProducts,
                'best_review_products'  => $bestRatedProducts,
                'top_customers'       => $topCustomers,
                'top_locations'       => $topLocations,
                'top_categories'      => $topCategories,
                'satisfaction_rate'   => $satisfactionRate,
            ],
        ]);
    }

    public function categoryAnalytics(Request $request)
    {
        $period = $request->get('period', 30);

        // ── Category Performance List ─────────────────────────────────────────
        $currentStart  = now()->subDays($period);
        $previousStart = now()->subDays($period * 2);

        $categories = DB::table('categories')
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->select(
                'categories.id',
                'categories.name',
                'categories.image',
                DB::raw('COUNT(DISTINCT products.id) as products_count'),
                DB::raw('SUM(CASE WHEN orders.payment_status = "paid" THEN order_items.quantity ELSE 0 END) as total_sold'),
                DB::raw('SUM(CASE WHEN orders.payment_status = "paid" THEN order_items.quantity * order_items.price ELSE 0 END) as total_revenue'),
                DB::raw('COUNT(DISTINCT CASE WHEN orders.payment_status = "paid" THEN orders.id ELSE NULL END) as orders_count')
            )
            ->groupBy('categories.id', 'categories.name', 'categories.image')
            ->orderByDesc('total_revenue')
            ->get();

        // Calculate growth per category
        $currentRevenue = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.created_at', '>=', $currentStart)
            ->select('products.category_id', DB::raw('SUM(order_items.quantity * order_items.price) as revenue'))
            ->groupBy('products.category_id')
            ->get()
            ->pluck('revenue', 'category_id');

        $previousRevenue = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$previousStart, $currentStart])
            ->select('products.category_id', DB::raw('SUM(order_items.quantity * order_items.price) as revenue'))
            ->groupBy('products.category_id')
            ->get()
            ->pluck('revenue', 'category_id');

        $categoryData = $categories->map(function ($c) use ($currentRevenue, $previousRevenue) {
            $curr = $currentRevenue[$c->id] ?? 0;
            $prev = $previousRevenue[$c->id] ?? 0;
            $growth = $prev > 0 ? round((($curr - $prev) / $prev) * 100, 1) : 0;
            $aov = $c->orders_count > 0 ? round($c->total_revenue / $c->orders_count, 2) : 0;

            return [
                'id'             => $c->id,
                'name'           => $c->name,
                'image'          => $c->image ? asset('storage/' . $c->image) : null,
                'products_count' => (int) $c->products_count,
                'total_sold'     => (int) $c->total_sold,
                'total_revenue'  => (float) $c->total_revenue,
                'orders_count'   => (int) $c->orders_count,
                'growth'         => (float) $growth,
                'aov'            => (float) $aov,
            ];
        });

        // ── Revenue Share (Pie Chart Data) ────────────────────────────────────
        $revenueShare = $categoryData->map(fn($c) => [
            'name'  => $c['name'],
            'value' => $c['total_revenue']
        ])->sortByDesc('value')->take(8)->values();

        // ── Performance Over Time (Bar Chart Data) ────────────────────────────
        // Group by month for the last 6 months for the top 5 categories
        $top5Ids = $categoryData->take(5)->pluck('id');
        $timeData = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.created_at', '>=', now()->subMonths(6))
            ->whereIn('categories.id', $top5Ids)
            ->select(
                DB::raw("DATE_FORMAT(orders.created_at, '%b') as month"),
                DB::raw("DATE_FORMAT(orders.created_at, '%Y-%m') as month_key"),
                'categories.name',
                DB::raw('SUM(order_items.quantity * order_items.price) as revenue')
            )
            ->groupBy('month_key', 'month', 'categories.name')
            ->orderBy('month_key')
            ->get();

        $performanceOverTime = [];
        $months = $timeData->pluck('month')->unique()->values();
        foreach ($months as $month) {
            $row = ['month' => $month];
            foreach ($categoryData->take(5) as $cat) {
                $rev = $timeData->where('month', $month)->where('name', $cat['name'])->first();
                $row[$cat['name']] = $rev ? (float) $rev->revenue : 0;
            }
            $performanceOverTime[] = $row;
        }

        return response()->json([
            'isError' => false,
            'category_performance' => $categoryData,
            'revenue_share'        => $revenueShare,
            'performance_trends'   => $performanceOverTime,
        ]);
    }

    public function bestSellingProductsFull(Request $request)
    {
        $limit = $request->get('limit', 20);

        $bestSellingProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.payment_status', 'paid')
            ->select(
                'products.id',
                'products.name',
                'products.price',
                'products.original_price',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('SUM(order_items.quantity * order_items.price) as total_revenue')
            )
            ->groupBy('products.id', 'products.name', 'products.price', 'products.original_price', 'products.sku')
            ->orderByDesc('total_sold')
            ->paginate($limit);

        $bestSellingProducts->getCollection()->transform(function ($p) {
            $image = DB::table('product_images')->where('product_id', $p->id)->first();
            $imagePath = $image ? asset('storage/' . $image->image_path) : null;

            $avg = Review::where('product_id', $p->id)->avg('rating') ?? 0;
            $cnt = Review::where('product_id', $p->id)->count();

            return [
                'id'            => $p->id,
                'name'          => $p->name,
                'price'         => (float) $p->price,
                'originalPrice' => $p->original_price ? (float) $p->original_price : null,
                'sku'           => $p->sku,
                'total_sold'    => (int) $p->total_sold,
                'total_revenue' => (float) $p->total_revenue,
                'image'         => $imagePath,
                'rating'        => round($avg, 1),
                'reviews'       => $cnt,
            ];
        });

        return response()->json([
            'isError' => false,
            'data' => $bestSellingProducts,
        ]);
    }

    public function bestRatedProductsFull(Request $request)
    {
        $limit = $request->get('limit', 20);

        $bestRatedProducts = Product::withCount(['reviews as reviews_count'])
            ->withAvg('reviews as avg_rating', 'rating')
            ->having('reviews_count', '>', 0)
            ->orderByDesc('avg_rating')
            ->orderByDesc('reviews_count')
            ->paginate($limit);

        $bestRatedProducts->getCollection()->transform(function ($p) {
            $image = DB::table('product_images')->where('product_id', $p->id)->first();
            $imagePath = $image ? asset('storage/' . $image->image_path) : null;

            return [
                'id'            => $p->id,
                'name'          => $p->name,
                'price'         => (float) $p->price,
                'originalPrice' => $p->original_price ? (float) $p->original_price : null,
                'sku'           => $p->sku,
                'rating'        => round($p->avg_rating, 1),
                'reviews'       => $p->reviews_count,
                'image'         => $imagePath,
                'status'        => 'Best Rated',
            ];
        });

        return response()->json([
            'isError' => false,
            'data' => $bestRatedProducts,
        ]);
    }
}
