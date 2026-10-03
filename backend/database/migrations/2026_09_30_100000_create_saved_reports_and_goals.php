<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report studio (saved, pinned and scheduled reports) and sales goals.
     */
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->json('spec'); // entity, dimension, metric, split, filters, chart, range
            $table->boolean('is_shared')->default(false);
            $table->boolean('pinned')->default(false);
            $table->string('schedule')->default('none'); // none | weekly | monthly
            $table->json('recipients')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole team
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('metric');
            $table->string('period')->default('month'); // month | quarter
            $table->decimal('target', 14, 2);
            $table->timestamps();
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
        Schema::dropIfExists('saved_reports');
    }
};
