<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('chassis_number')->nullable()->change();
        });

        DB::table('vehicles')->where('chassis_number', '')->update(['chassis_number' => null]);
    }

    public function down(): void
    {
        $nulls = DB::table('vehicles')->whereNull('chassis_number')->pluck('id');
        foreach ($nulls as $id) {
            DB::table('vehicles')->where('id', $id)->update(['chassis_number' => 'UNKNOWN-'.$id]);
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('chassis_number')->nullable(false)->change();
        });
    }
};
