<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orders = DB::table('orders')->pluck('id');

        foreach ($orders as $id) {
            DB::table('orders')->where('id', $id)->update(['status' => 'migrated']);
        }
    }
};
