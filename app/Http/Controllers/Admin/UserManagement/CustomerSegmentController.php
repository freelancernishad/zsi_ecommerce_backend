<?php

namespace App\Http\Controllers\Admin\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerSegmentController extends Controller
{
    /**
     * Get real-time customer segments and analytics.
     */
    public function index()
    {
        // 1. VIP: 5+ orders AND $500+ spend
        $vipIds = Order::select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 5')
            ->havingRaw('SUM(total) >= 500')
            ->pluck('user_id');
        $vipCount = $vipIds->count();

        // 2. New: Joined in last 30 days AND 1+ orders
        $newCount = User::where('created_at', '>=', now()->subDays(30))
            ->whereHas('orders')
            ->count();

        // 3. Inactive: No orders in last 90 days
        $activeIn90DaysIds = Order::where('created_at', '>=', now()->subDays(90))
            ->distinct('user_id')
            ->pluck('user_id');
        $inactiveCount = User::whereNotIn('id', $activeIn90DaysIds)->count();

        // 4. Big Spenders: Average Order Value (AOV) > 100
        $bigSpenderCount = Order::select('user_id')
            ->groupBy('user_id')
            ->havingRaw('AVG(total) > 100')
            ->get()
            ->count();

        // 5. Loyal: 10+ orders
        $loyalCount = User::has('orders', '>=', 10)->count();

        // 6. Churn Risk: VIP criteria AND no orders in last 60 days
        $activeIn60DaysIds = Order::where('created_at', '>=', now()->subDays(60))
            ->distinct('user_id')
            ->pluck('user_id');
        $churnRiskCount = User::whereIn('id', $vipIds)
            ->whereNotIn('id', $activeIn60DaysIds)
            ->count();

        // Analytics Banner Stats
        $totalCustomersWithOrders = User::has('orders')->count();
        $usersWithTwoPlusOrders = User::has('orders', '>=', 2)->count();
        
        $retentionRate = $totalCustomersWithOrders > 0 
            ? round(($usersWithTwoPlusOrders / $totalCustomersWithOrders) * 100, 1) 
            : 0;

        $totalLtv = Order::sum('total');
        $avgLtv = $totalCustomersWithOrders > 0 
            ? round($totalLtv / $totalCustomersWithOrders, 2) 
            : 0;

        return response()->json([
            'segments' => [
                'vip' => $vipCount,
                'new' => $newCount,
                'inactive' => $inactiveCount,
                'big_spenders' => $bigSpenderCount,
                'loyal' => $loyalCount,
                'risky' => $churnRiskCount,
            ],
            'analytics' => [
                'retention_rate' => $retentionRate . '%',
                'avg_ltv' => '$' . $avgLtv
            ]
        ]);
    }
}
