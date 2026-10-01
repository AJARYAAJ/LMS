<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hosted landing pages and UTM-tagged short links for campaigns. */
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('web_form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_published')->default(false);
            $table->json('blocks');
            $table->string('accent_color', 16)->default('#7c3aed');
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('submissions')->default(0);
            $table->timestamps();
        });

        Schema::create('tracked_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 12)->unique();
            $table->string('label')->nullable();
            $table->text('destination');
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_links');
        Schema::dropIfExists('landing_pages');
    }
};
