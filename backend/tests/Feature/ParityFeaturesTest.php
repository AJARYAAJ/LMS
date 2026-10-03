<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Sequence;
use App\Models\Task;
use App\Models\User;
use App\Models\WebForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ParityFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_requires_fields_before_entering_a_status(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Blue'])->json('data.id');
        [$qualified, $lost] = $this->inTenant($admin, fn () => [LeadStatus::where('key', 'qualified')->first(), LeadStatus::where('key', 'lost')->first()]);

        $this->as($admin)->postJson("/api/v1/leads/{$id}/status", ['lead_status_id' => $qualified->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.required_fields', ['email', 'company']);

        $this->as($admin)->postJson("/api/v1/leads/{$id}/status", ['lead_status_id' => $lost->id])->assertUnprocessable();
        $this->as($admin)->postJson("/api/v1/leads/{$id}/status", ['lead_status_id' => $lost->id, 'lost_reason' => 'Budget'])
            ->assertOk()->assertJsonPath('data.lost_reason', 'Budget');
    }

    public function test_qualification_checklist_and_insights(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Q', 'phone' => '+1 555 0100 222'])->json('data.id');

        $this->as($admin)->getJson("/api/v1/leads/{$id}/insights")
            ->assertOk()
            ->assertJsonPath('data.qualification.percent', 0)
            ->assertJsonPath('data.next_action.type', 'assign');

        $this->as($admin)->putJson("/api/v1/leads/{$id}/qualification", ['answers' => ['budget' => true, 'need' => true, 'bogus' => true]])
            ->assertOk()
            ->assertJsonPath('data.progress.percent', 50)
            ->assertJsonMissingPath('data.qualification.bogus');
    }

    public function test_reps_can_claim_leads_from_the_queue(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $other = $this->member($admin);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Queued'])->json('data.id');

        $this->as($rep)->getJson('/api/v1/leads/queue')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->as($rep)->postJson("/api/v1/leads/{$id}/claim")->assertOk()->assertJsonPath('data.owner_id', $rep->id);
        $this->as($other)->postJson("/api/v1/leads/{$id}/claim")->assertUnprocessable();
        $this->as($rep)->getJson("/api/v1/leads/{$id}")->assertOk();
    }

    public function test_merge_moves_history_and_recycle_bin_restores(): void
    {
        $admin = $this->organization();
        $primary = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Pat', 'email' => 'pat@corp.io'])->json('data.id');
        $dupe = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Patrick', 'phone' => '+1 555 999 1234', 'company' => 'Corp', 'allow_duplicate' => true])->json('data.id');
        $this->as($admin)->postJson("/api/v1/leads/{$dupe}/notes", ['body' => 'Met at expo'])->assertCreated();

        $this->as($admin)->postJson("/api/v1/leads/{$primary}/merge", ['duplicate_id' => $dupe])
            ->assertOk()
            ->assertJsonPath('data.phone', '+1 555 999 1234')
            ->assertJsonPath('data.company', 'Corp');

        $this->as($admin)->getJson("/api/v1/leads/{$primary}/notes")->assertJsonCount(1, 'data');
        $this->as($admin)->getJson("/api/v1/leads/{$dupe}")->assertNotFound();

        $this->as($admin)->getJson('/api/v1/leads/trash')->assertJsonPath('data.0.id', $dupe);
        $this->as($admin)->postJson("/api/v1/leads/trash/{$dupe}/restore")->assertOk();
        $this->as($admin)->getJson("/api/v1/leads/{$dupe}")->assertOk();
    }

    public function test_email_templates_render_merge_fields_and_log_activity(): void
    {
        Mail::fake();
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada', 'email' => 'ada@engines.io', 'company' => 'Engines'])->json('data.id');
        $template = $this->inTenant($admin, fn () => EmailTemplate::first());

        $this->as($admin)->postJson("/api/v1/leads/{$id}/email/preview", ['subject' => $template->subject, 'body' => 'Hi {first_name} from {company}'])
            ->assertJsonPath('data.subject', 'Great to meet you, Ada')
            ->assertJsonPath('data.body', 'Hi Ada from Engines');

        $this->as($admin)->postJson("/api/v1/leads/{$id}/email", ['email_template_id' => $template->id, 'subject' => $template->subject, 'body' => $template->body])
            ->assertOk();

        $this->as($admin)->getJson("/api/v1/leads/{$id}/activities?type=email")
            ->assertJsonPath('data.0.title', 'Great to meet you, Ada')
            ->assertJsonPath('data.0.direction', 'outbound');
        $this->assertSame(1, $template->fresh()->usage_count);
    }

    public function test_sequences_schedule_tasks_and_can_be_stopped(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Seq', 'owner_id' => $admin->id])->json('data.id');
        $sequence = $this->inTenant($admin, fn () => Sequence::where('name', 'like', 'New inbound%')->first());

        $enrollment = $this->as($admin)->postJson("/api/v1/leads/{$id}/enrollments", ['sequence_id' => $sequence->id])->assertCreated()->json('data.id');
        $this->as($admin)->postJson("/api/v1/leads/{$id}/enrollments", ['sequence_id' => $sequence->id])->assertUnprocessable();

        $this->assertSame(5, $this->inTenant($admin, fn () => Task::where('sequence_enrollment_id', $enrollment)->count()));
        $this->as($admin)->getJson("/api/v1/leads/{$id}/enrollments")->assertJsonPath('data.0.tasks_count', 5);

        $this->as($admin)->deleteJson("/api/v1/enrollments/{$enrollment}")->assertNoContent();
        $this->assertSame(0, $this->inTenant($admin, fn () => Task::where('sequence_enrollment_id', $enrollment)->count()));
    }

    public function test_hosted_web_form_creates_a_tagged_lead(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $form = $this->inTenant($admin, fn () => WebForm::first());

        $this->getJson("/api/v1/forms/{$form->slug}")->assertOk()->assertJsonPath('data.organization', 'Acme');
        $this->postJson("/api/v1/forms/{$form->slug}", ['name' => 'Wendy Web'])->assertUnprocessable();
        $this->postJson("/api/v1/forms/{$form->slug}", ['name' => 'Wendy Web', 'email' => 'wendy@shop.co', 'requirements' => 'Need 50 seats'])
            ->assertCreated();

        $lead = $this->inTenant($admin, fn () => Lead::with('source')->first());
        $this->assertSame('Wendy', $lead->first_name);
        $this->assertSame('Website', $lead->source->name);
        $this->assertSame('shop.co', $lead->website); // enriched
        $this->assertSame(1, $form->fresh()->submissions_count);
    }

    public function test_bulk_status_skips_leads_failing_blueprint(): void
    {
        $admin = $this->organization();
        $ok = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'A', 'email' => 'a@x.io', 'company' => 'X'])->json('data.id');
        $bad = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'B'])->json('data.id');
        $qualified = $this->inTenant($admin, fn () => LeadStatus::where('key', 'qualified')->first());

        $this->as($admin)->postJson('/api/v1/leads/bulk', ['ids' => [$ok, $bad], 'action' => 'status', 'lead_status_id' => $qualified->id])
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('skipped', 1);
    }

    public function test_managers_manage_templates_but_reps_only_read(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin, User::SALES_REP);
        $manager = $this->member($admin, User::MANAGER);

        $this->as($rep)->getJson('/api/v1/settings/email-templates')->assertOk()->assertJsonCount(3, 'data');
        $this->as($rep)->postJson('/api/v1/settings/email-templates', ['name' => 'x', 'subject' => 'y', 'body' => 'z'])->assertForbidden();
        $this->as($manager)->postJson('/api/v1/settings/email-templates', ['name' => 'x', 'subject' => 'y', 'body' => 'z'])->assertCreated();
    }

    public function test_dashboard_includes_activity_heatmap(): void
    {
        $admin = $this->organization();
        $this->as($admin)->getJson('/api/v1/dashboard')->assertOk()->assertJsonStructure(['data' => ['heatmap' => [['date', 'count']]]]);
    }
}
