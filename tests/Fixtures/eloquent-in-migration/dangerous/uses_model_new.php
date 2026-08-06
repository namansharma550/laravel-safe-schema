<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $order = new Order;
        $order->status = 'confirmed';
        $order->save();
    }
};
