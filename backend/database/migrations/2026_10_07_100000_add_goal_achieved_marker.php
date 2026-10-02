<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Remember which period a goal's "reached" notification went out for. */
    public function up(): void
    {
        Schema::table('goals', fn (Blueprint $t) => $t->string('achieved_for', 20)->nullable());
    }

    public function down(): void
    {
        Schema::table('goals', fn (Blueprint $t) => $t->dropColumn('achieved_for'));
    }
};
