<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    \DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
}

public function down(): void
{
    \DB::statement("UPDATE users SET password = 'placeholder' WHERE password IS NULL");
    \DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
}
};
