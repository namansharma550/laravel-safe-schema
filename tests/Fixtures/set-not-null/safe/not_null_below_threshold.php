<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('small_lookup_table', function (Blueprint $table) {
            $table->string('code')->nullable(false)->change();
        });
    }
};
