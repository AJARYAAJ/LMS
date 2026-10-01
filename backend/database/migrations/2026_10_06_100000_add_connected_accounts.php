<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Each person's Google / Microsoft mailbox and calendar connection. */
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google | microsoft
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('sync_mail')->default(true);
            $table->boolean('sync_calendar')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        // Messages already on a timeline, so a sync never adds them twice.
        Schema::create('synced_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['connected_account_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_messages');
        Schema::dropIfExists('connected_accounts');
    }
};
