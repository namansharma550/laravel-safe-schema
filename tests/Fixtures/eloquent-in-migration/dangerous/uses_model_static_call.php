<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::where('active', true)->update(['status' => 'confirmed']);
    }
};
