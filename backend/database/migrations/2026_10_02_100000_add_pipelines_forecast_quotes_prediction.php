<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple pipelines, forecast categories, products & quotes, predicted conversion.
     */
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->nullable()->after('pipeline_stage_id')->constrained()->nullOnDelete();
            $table->string('forecast_category')->nullable()->after('probability'); // pipeline | best_case | commit | closed | omitted
            $table->boolean('forecast_override')->default(false)->after('forecast_category');
            $table->index(['organization_id', 'pipeline_id', 'forecast_category']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->string('billing')->default('one_time'); // one_time | monthly | yearly
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number');
            $table->string('title');
            $table->string('status')->default('draft'); // draft | sent | accepted | declined | expired
            $table->string('currency', 3);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('public_token', 48)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('signed_name')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('decline_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('conversion_likelihood')->nullable()->after('score');
        });

        // Every organization gets a default "Sales" pipeline holding its existing stages and deals.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $id = DB::table('pipelines')->insertGetId(['organization_id' => $orgId, 'name' => 'Sales', 'is_default' => true, 'display_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pipeline_stages')->where('organization_id', $orgId)->update(['pipeline_id' => $id]);
            DB::table('deals')->where('organization_id', $orgId)->update(['pipeline_id' => $id]);
        }
        DB::statement("update deals set forecast_category = case
            when status = 'won' then 'closed' when status = 'lost' then 'omitted'
            when probability >= 70 then 'commit' when probability >= 40 then 'best_case' else 'pipeline' end");
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('conversion_likelihood'));
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('products');
        Schema::table('deals', function (Blueprint $t) {
            $t->dropIndex(['organization_id', 'pipeline_id', 'forecast_category']);
            $t->dropConstrainedForeignId('pipeline_id');
            $t->dropColumn(['forecast_category', 'forecast_override']);
        });
        Schema::table('pipeline_stages', fn (Blueprint $t) => $t->dropConstrainedForeignId('pipeline_id'));
        Schema::dropIfExists('pipelines');
    }
};
