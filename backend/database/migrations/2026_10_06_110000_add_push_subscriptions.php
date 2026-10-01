<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Devices that receive push notifications (browsers via Web Push, the native app via FCM). */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('webpush'); // webpush | fcm
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint'); // push service URL, or the FCM device token
            $table->string('p256dh')->nullable();
            $table->string('auth')->nullable();
            $table->string('device')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
