<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'user_id', 'subtotal', 'discount', 
        'shipping_cost', 'tax', 'total', 'status', 
        'payment_method', 'payment_method_name', 'payment_gateway', 'transaction_id', 'payment_details',
        'shipping_method', 'shipping_address_id',
        'shipping_first_name', 'shipping_last_name', 'shipping_email', 
        'shipping_phone', 'shipping_country', 'shipping_street_address', 
        'shipping_city', 'shipping_state', 'shipping_zip_code',
        'payment_status', 'fulfillment_status', 'delivery_status',
        'courier_name', 'tracking_number', 'tracking_url', 'shipped_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function shippingAddress()
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }
}
