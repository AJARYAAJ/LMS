<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email campaigns with A/B tests and tracking, marketing touchpoints for
     * attribution, and inbound (receptionist) AI agents.
     */
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('conditions')->nullable();
            $table->json('variants'); // [{key: A|B, subject, body}]
            $table->unsignedTinyInteger('test_percent')->default(100); // share that gets the A/B test
            $table->string('winner_metric')->default('open'); // open | click
            $table->unsignedSmallInteger('winner_after_hours')->default(4);
            $table->string('winner_key', 1)->nullable();
            $table->string('status')->default('draft'); // draft | scheduled | testing | sending | sent | canceled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('winner_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('variant', 1)->nullable(); // null = waiting for the winner
            $table->string('status')->default('queued'); // queued | held | sent | skipped | failed
            $table->string('token', 40)->unique();
            $table->string('reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->unique(['broadcast_id', 'lead_id']);
        });

        Schema::create('touchpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel'); // capture | web_form | email_click | booking | phone
            $table->string('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['organization_id', 'lead_id', 'occurred_at']);
        });

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->string('mode')->default('outbound')->after('name'); // outbound | inbound
        });

        // Existing leads' first touch: how they arrived.
        DB::statement("insert into touchpoints (organization_id, lead_id, campaign_id, lead_source_id, channel, occurred_at)
            select organization_id, id, campaign_id, lead_source_id, 'capture', created_at from leads");
    }

    public function down(): void
    {
        Schema::table('ai_agents', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::dropIfExists('touchpoints');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
