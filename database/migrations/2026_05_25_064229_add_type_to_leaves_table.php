<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->enum('type', ['Izin', 'Sakit', 'Cuti'])->after('user_id')->default('Izin');
        });
    }

    public function down()
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};