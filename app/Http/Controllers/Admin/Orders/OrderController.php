<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items.product', 'shippingAddress'])
            ->withCount('items');

        // Filter by Status (Combined mapping for delivery_status or general status)
        if ($request->has('status') && $request->status !== 'all') {
            $status = $request->status;
            // Map the tab labels to actual column values
            if (in_array($status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
                $query->where('delivery_status', $status);
            } elseif ($status === 'in_progress') {
                $query->whereIn('delivery_status', ['pending', 'processing']);
            }
        }

        // Search by Order Number or Customer Name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 8);
        $orders = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $orders
        ]);
    }

    public function stats()
    {
        $stats = [
            'items_ordered' => DB::table('order_items')->sum('quantity'),
            'fulfilled' => Order::where('fulfillment_status', 'fulfilled')->count(),
            'cancelled' => Order::where('delivery_status', 'cancelled')->count(),
            'completed' => Order::where('delivery_status', 'delivered')->count(),
            'returns_loss' => Order::where('delivery_status', 'returned')->sum('total'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'items.product', 'items.variant', 'shippingAddress'])->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $order]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'nullable|string',
            'fulfillment_status' => 'nullable|string',
            'delivery_status' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $order = Order::findOrFail($id);
        $order->update($request->only([
            'payment_status', 'fulfillment_status', 'delivery_status', 'status'
        ]));

        return response()->json([
            'status' => 'success', 
            'message' => "Order status updated successfully",
            'data' => $order
        ]);
    }
}
