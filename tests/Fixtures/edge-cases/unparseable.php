<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function ($table) {
            $table->index('status')
        // missing closing paren and brace on purpose
