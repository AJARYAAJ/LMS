<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parity pack: blueprint rules, email templates, sequences (cadences)
     * and hosted web forms.
     */
    public function up(): void
    {
        Schema::table('lead_statuses', function (Blueprint $table) {
            // Lead fields that must be filled before a lead can enter this status.
            $table->json('required_fields')->nullable()->after('is_terminal');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->default('general');
            $table->string('subject');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('steps'); // [{day_offset, type, title, email_template_id?}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active'); // active | completed | stopped
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('sequence_enrollment_id')->nullable()->after('taskable_id')->constrained()->nullOnDelete();
            $table->foreignId('email_template_id')->nullable()->after('sequence_enrollment_id')->constrained()->nullOnDelete();
        });

        Schema::create('web_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->json('fields'); // [{key, label, type, required}]
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tag_ids')->nullable();
            $table->string('submit_label')->default('Submit');
            $table->string('success_message')->default('Thanks! We will be in touch shortly.');
            $table->string('redirect_url')->nullable();
            $table->string('accent_color', 16)->default('#7c3aed');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_forms');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropConstrainedForeignId('sequence_enrollment_id');
        });
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('email_templates');
        Schema::table('lead_statuses', fn (Blueprint $table) => $table->dropColumn('required_fields'));
    }
};
