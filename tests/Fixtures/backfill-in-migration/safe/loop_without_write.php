<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = [];

        foreach (DB::table('orders')->pluck('id') as $id) {
            $ids[] = $id;
        }
    }
};
