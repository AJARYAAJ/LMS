<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-step login, per-channel consent and erasure, REST-hook subscriptions.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('consent')->nullable()->after('custom_fields'); // {email|sms|whatsapp: {status, at, source}}
            $table->timestamp('erased_at')->nullable()->after('converted_at');
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('is_active'); // manual | rest_hook
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['consent', 'erased_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
