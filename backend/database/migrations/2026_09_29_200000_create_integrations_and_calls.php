<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integrations hub (vendor connections), AI voice agents and calls.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // email | messaging | voice | ai | chat
            $table->string('provider'); // smtp | sendgrid | twilio | meta_whatsapp | vapi | retell | bland | simulator | anthropic | slack | teams
            $table->text('config'); // encrypted JSON
            $table->string('inbound_token', 48)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('connected'); // connected | error
            $table->text('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->text('goal');
            $table->text('first_message');
            $table->string('voice')->default('alloy');
            $table->string('language', 12)->default('en-US');
            $table->json('questions')->nullable(); // [{key, question}]
            $table->unsignedSmallInteger('max_duration_seconds')->default(300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign_key', 40)->nullable()->index();
            $table->string('direction')->default('outbound');
            $table->string('provider');
            $table->string('provider_call_id')->nullable()->index();
            $table->string('to_number', 40);
            // queued | ringing | in_progress | completed | no_answer | voicemail | failed | canceled
            $table->string('status')->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('recording_url', 1000)->nullable();
            $table->json('transcript')->nullable(); // [{role: agent|lead, text}]
            $table->text('summary')->nullable();
            $table->string('outcome')->nullable(); // interested | not_interested | callback | meeting_booked | wrong_number | voicemail | no_answer
            $table->string('sentiment')->nullable(); // positive | neutral | negative
            $table->json('extracted')->nullable(); // qualification answers, callback_at, notes
            $table->decimal('cost', 10, 4)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
        Schema::dropIfExists('ai_agents');
        Schema::dropIfExists('integrations');
    }
};
