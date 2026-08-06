<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('small_lookup_table', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->default(DB::raw('now()'));
        });
    }
};
