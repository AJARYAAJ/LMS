<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Where the AI receptionist hands a caller over to a person. */
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            $table->string('transfer_mode')->default('none')->after('mode'); // none | owner | number
            $table->string('transfer_number', 40)->nullable()->after('transfer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', fn (Blueprint $t) => $t->dropColumn(['transfer_mode', 'transfer_number']));
    }
};
