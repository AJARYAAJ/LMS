<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom dashboards, speed-to-lead tracking with an SLA, and report delivery to chat.
     */
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_shared')->default(false);
            $table->json('tiles'); // [{id, kind: report|spec|kpis|goals, report_id?, spec?, type?, title?, span: 1|2}]
            $table->timestamps();
            $table->index(['organization_id', 'user_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable()->after('last_contacted_at');
            $table->timestamp('sla_alerted_at')->nullable()->after('first_responded_at');
        });

        Schema::table('saved_reports', function (Blueprint $table) {
            $table->boolean('post_to_chat')->default(false)->after('recipients');
        });

        // Backfill first response from the earliest outgoing touch on each lead.
        DB::statement("update leads set first_responded_at = (
            select min(occurred_at) from activities
            where activities.subject_type = 'lead' and activities.subject_id = leads.id
              and activities.type in ('call', 'email', 'meeting', 'sms', 'whatsapp')
              and (activities.direction is null or activities.direction <> 'inbound')
        )");
    }

    public function down(): void
    {
        Schema::table('saved_reports', fn (Blueprint $table) => $table->dropColumn('post_to_chat'));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['first_responded_at', 'sla_alerted_at']));
        Schema::dropIfExists('dashboards');
    }
};
