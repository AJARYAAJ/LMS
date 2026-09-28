<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contacts', 'accounts', 'deals'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('custom_fields')->nullable());
        }

        // Indexes for the dashboard, reports and list filters at scale.
        Schema::table('leads', function (Blueprint $t) {
            $t->index(['organization_id', 'created_at']);
            $t->index(['organization_id', 'lead_source_id']);
            $t->index(['organization_id', 'converted_at']);
            $t->index(['organization_id', 'score']);
        });
        Schema::table('activities', fn (Blueprint $t) => $t->index(['organization_id', 'user_id', 'occurred_at']));
        Schema::table('tasks', fn (Blueprint $t) => $t->index(['organization_id', 'due_at']));
        Schema::table('deals', fn (Blueprint $t) => $t->index(['organization_id', 'status', 'closed_at']));
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropIndex(['organization_id', 'status', 'closed_at']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropIndex(['organization_id', 'due_at']));
        Schema::table('activities', fn (Blueprint $t) => $t->dropIndex(['organization_id', 'user_id', 'occurred_at']));
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['organization_id', 'created_at']);
            $t->dropIndex(['organization_id', 'lead_source_id']);
            $t->dropIndex(['organization_id', 'converted_at']);
            $t->dropIndex(['organization_id', 'score']);
        });
        foreach (['contacts', 'accounts', 'deals'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('custom_fields'));
        }
    }
};
