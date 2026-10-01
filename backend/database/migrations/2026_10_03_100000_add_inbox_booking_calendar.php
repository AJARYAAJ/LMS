<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared conversations inbox, public booking pages and personal calendar feeds.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('inbox_read_at')->nullable()->after('sla_alerted_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 48)->nullable()->unique()->after('preferences');
        });

        Schema::create('booking_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(10);
            $table->unsignedSmallInteger('notice_hours')->default(4);
            $table->unsignedSmallInteger('days_ahead')->default(14);
            $table->json('weekdays'); // ISO 1 (Mon) … 7 (Sun)
            $table->string('start_time', 5)->default('09:00');
            $table->string('end_time', 5)->default('17:00');
            $table->string('timezone')->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_pages');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('calendar_token'));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('inbox_read_at'));
    }
};
