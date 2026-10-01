<?php

namespace Database\Seeders;

use App\Jobs\SimulateCallResult;
use App\Models\Activity;
use App\Models\AiAgent;
use App\Models\AssignmentRule;
use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\SavedReport;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\CallService;
use App\Services\LeadService;
use App\Services\OrganizationProvisioner;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A realistic demo organization. Log in with admin@lms.test / password.
 */
class DemoSeeder extends Seeder
{
    public function run(OrganizationProvisioner $provisioner, LeadService $leads): void
    {
        $faker = fake();
        $faker->seed(2026);

        $admin = $provisioner->provision(
            ['name' => 'Acme Growth', 'industry' => 'Software', 'currency' => 'USD'],
            ['name' => 'Avery Admin', 'email' => 'admin@lms.test', 'password' => 'password', 'job_title' => 'Head of Sales'],
        );

        Tenant::run($admin->organization_id, function () use ($admin, $faker, $leads) {
            Notification::fake(); // keep seeding quiet

            $manager = User::create(['name' => 'Morgan Manager', 'email' => 'manager@lms.test', 'password' => 'password', 'role' => User::MANAGER, 'job_title' => 'Sales Manager', 'avatar_color' => '#0ea5e9']);
            $reps = collect([
                ['Riley Chen', 'riley@lms.test', '#10b981'],
                ['Sam Patel', 'sam@lms.test', '#f59e0b'],
                ['Jordan Diaz', 'jordan@lms.test', '#ec4899'],
            ])->map(fn ($r) => User::create(['name' => $r[0], 'email' => $r[1], 'password' => 'password', 'role' => User::SALES_REP, 'job_title' => 'Account Executive', 'avatar_color' => $r[2]]));
            User::create(['name' => 'Val Viewer', 'email' => 'viewer@lms.test', 'password' => 'password', 'role' => User::VIEWER, 'job_title' => 'Analyst', 'avatar_color' => '#64748b']);

            $team = Team::create(['name' => 'Inbound Sales', 'description' => 'Handles website & referral leads', 'manager_id' => $manager->id, 'color' => '#6366f1']);
            $team->members()->sync([$admin->id, $manager->id, ...$reps->pluck('id')]);

            AssignmentRule::create([
                'name' => 'Inbound round robin', 'priority' => 10, 'strategy' => 'round_robin', 'team_id' => $team->id,
                'conditions' => [],
            ]);

            $sources = LeadSource::all();
            $campaigns = collect([
                ['Spring Webinar Series', 'webinar', 4500, 'email_campaign'],
                ['LinkedIn Q3 Ads', 'paid social', 8000, 'social_media'],
                ['SaaS Expo 2026', 'event', 12000, 'trade_show'],
            ])->map(fn ($c) => Campaign::create([
                'name' => $c[0], 'channel' => $c[1], 'budget' => $c[2], 'actual_cost' => $c[2] * 0.9,
                'lead_source_id' => $sources->firstWhere('key', $c[3])?->id,
                'starts_on' => now()->subDays(80), 'ends_on' => now()->addDays(20), 'status' => 'active',
            ]));

            $statuses = LeadStatus::orderBy('display_order')->get()->keyBy('key');
            $tags = Tag::all();
            $stages = PipelineStage::orderBy('display_order')->get();
            $industries = ['Software', 'Healthcare', 'Manufacturing', 'Retail', 'Finance', 'Education', 'Logistics'];
            $titles = ['CEO', 'CTO', 'Operations Director', 'Marketing Manager', 'Procurement Lead', 'IT Director', 'Founder', 'VP Sales'];
            $flow = ['new', 'new', 'contacted', 'contacted', 'qualification', 'nurturing', 'qualified', 'qualified', 'converted', 'not_interested', 'lost'];

            for ($i = 0; $i < 64; $i++) {
                $first = $faker->firstName();
                $last = $faker->lastName();
                $company = $faker->company();
                $business = $faker->boolean(70);
                $createdAt = now()->subDays($faker->numberBetween(0, 88))->subHours($faker->numberBetween(0, 20));

                $lead = $leads->create([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => strtolower($first.'.'.$last).'@'.($business ? Str::slug($company).'.com' : 'gmail.com'),
                    'phone' => $faker->boolean(80) ? $faker->numerify('+1 (###) ###-####') : null,
                    'company' => $company,
                    'job_title' => $faker->randomElement($titles),
                    'industry' => $faker->randomElement($industries),
                    'company_size' => $faker->randomElement(['1-10', '11-50', '51-200', '201-1000', '1000+']),
                    'city' => $faker->city(),
                    'country' => $faker->randomElement(['United States', 'India', 'United Kingdom', 'Germany', 'Canada']),
                    'lead_source_id' => $sources->random()->id,
                    'campaign_id' => $faker->boolean(35) ? $campaigns->random()->id : null,
                    'priority' => $faker->randomElement(['low', 'medium', 'medium', 'high', 'urgent']),
                    'budget' => $faker->boolean(60) ? $faker->numberBetween(2, 60) * 1000 : null,
                    'expected_value' => $faker->numberBetween(3, 80) * 1000,
                    'timeline' => $faker->randomElement(['This month', 'This quarter', 'Next quarter', '6+ months']),
                    'requirements' => $faker->sentence(14),
                    'tag_ids' => $tags->random($faker->numberBetween(0, 2))->pluck('id')->all(),
                    'next_follow_up_at' => $faker->boolean(60) ? now()->addDays($faker->numberBetween(-4, 10))->setTime($faker->numberBetween(9, 17), 0) : null,
                ], $admin, $faker->randomElement(['manual', 'web_form', 'import', 'api']));

                $lead->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
                $lead->activities()->update(['occurred_at' => $createdAt, 'created_at' => $createdAt]);

                for ($n = 1, $touches = $faker->numberBetween(0, 4); $n <= $touches; $n++) {
                    $type = $faker->randomElement(['call', 'email', 'meeting', 'email', 'call', 'sms', 'whatsapp']);
                    $inbound = in_array($type, ['email', 'sms', 'whatsapp'], true) && $faker->boolean(30);
                    $when = $createdAt->copy()->addDays($n)->min(now());
                    Activity::create([
                        'subject_type' => 'lead', 'subject_id' => $lead->id, 'user_id' => $lead->owner_id,
                        'type' => $type,
                        'title' => match (true) {
                            $inbound => 'Reply from '.$first,
                            $type === 'call' => 'Discovery call',
                            $type === 'email' => 'Sent product overview',
                            $type === 'sms' => 'SMS follow-up',
                            $type === 'whatsapp' => 'WhatsApp follow-up',
                            default => 'Demo meeting',
                        },
                        'description' => $faker->sentence(12),
                        'direction' => $inbound ? 'inbound' : 'outbound',
                        'outcome' => $type === 'call' || $type === 'meeting' ? $faker->randomElement(['Connected', 'Left voicemail', 'Interested', 'Requested pricing', null]) : null,
                        'duration_minutes' => in_array($type, ['call', 'meeting'], true) ? $faker->numberBetween(5, 45) : null,
                        'occurred_at' => $when,
                    ]);
                    $lead->forceFill(['last_contacted_at' => $when])->saveQuietly();
                }

                if ($faker->boolean(45)) {
                    $lead->notes()->create(['organization_id' => $lead->organization_id, 'user_id' => $lead->owner_id, 'body' => $faker->paragraph(2), 'is_pinned' => $faker->boolean(20)]);
                }

                if ($faker->boolean(55)) {
                    Task::create([
                        'taskable_type' => 'lead', 'taskable_id' => $lead->id, 'assigned_to' => $lead->owner_id, 'created_by' => $admin->id,
                        'title' => $faker->randomElement(['Call back', 'Send proposal', 'Share case study', 'Book demo', 'Confirm budget']).' – '.$lead->full_name,
                        'type' => $faker->randomElement(['call', 'email', 'meeting', 'follow_up']),
                        'priority' => $faker->randomElement(['low', 'medium', 'high']),
                        'due_at' => now()->addDays($faker->numberBetween(-3, 7))->setTime($faker->numberBetween(9, 17), 30),
                        'completed_at' => $faker->boolean(25) ? now()->subDay() : null,
                    ]);
                }

                $target = $faker->randomElement($flow);
                if ($target === 'converted') {
                    $leads->changeStatus($lead, $statuses['qualified']->id, $admin);
                    $leads->convert($lead->refresh(), ['deal_amount' => $lead->expected_value, 'pipeline_stage_id' => $stages->random()->id], $admin);
                } elseif ($target !== 'new') {
                    $leads->changeStatus($lead, $statuses[$target]->id, $admin, null, in_array($target, ['lost', 'not_interested']) ? $faker->randomElement(['Budget', 'Went with competitor', 'No response', 'Timing']) : null);
                }

                $lead->statusHistory()->update(['created_at' => $createdAt]);
            }

            // Guarantee a few closed-won deals this month so revenue widgets have data.
            $won = $stages->firstWhere('is_won', true);
            Deal::inRandomOrder()->limit(3)->update(['pipeline_stage_id' => $won->id]);

            // A handful of simulated AI calls so the Calls workspace has history.
            $agent = AiAgent::first();
            Lead::whereNotNull('phone')->whereNull('converted_at')->orderByDesc('score')->limit(8)->get()
                ->each(function (Lead $lead) use ($agent, $admin) {
                    $call = app(CallService::class)->start($lead, $agent, $admin);
                    SimulateCallResult::dispatchSync($call->id);
                });

            Deal::whereNotNull('pipeline_stage_id')->get()->each(function (Deal $deal) use ($stages) {
                $stage = $stages->firstWhere('id', $deal->pipeline_stage_id);
                $deal->update([
                    'probability' => $stage->probability,
                    'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
                    'closed_at' => ($stage->is_won || $stage->is_lost) ? now()->subDays(random_int(0, min(20, now()->day - 1))) : null,
                    'expected_close_date' => now()->addDays(random_int(5, 60)),
                ]);
            });

            // A few lost deals with reasons, so loss analysis has something to show.
            $lostStage = $stages->firstWhere('is_lost', true);
            if ($lostStage) {
                Deal::where('status', 'open')->inRandomOrder()->limit(3)->get()->each(fn (Deal $deal) => $deal->update([
                    'pipeline_stage_id' => $lostStage->id, 'status' => 'lost', 'probability' => 0,
                    'lost_reason' => $faker->randomElement(['Budget', 'Went with competitor', 'No decision', 'Timing']),
                    'closed_at' => now()->subDays(random_int(1, 25)),
                ]));
            }

            // Report studio examples (two pinned to the admin's dashboard) and this month's goals.
            foreach ([
                ['Revenue by owner this quarter', ['entity' => 'deals', 'metric' => 'won_amount', 'dimension' => 'owner', 'date_field' => 'closed', 'range' => 'this_quarter', 'chart' => 'bar'], true, 'weekly'],
                ['Lead flow by source', ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'created', 'split' => 'source', 'range' => 'last_90', 'chart' => 'stacked'], true, 'none'],
                ['Conversion rate by industry', ['entity' => 'leads', 'metric' => 'conversion_rate', 'dimension' => 'industry', 'range' => 'last_90', 'chart' => 'bar'], false, 'monthly'],
            ] as [$name, $spec, $pinned, $schedule]) {
                SavedReport::create(['user_id' => $admin->id, 'name' => $name, 'spec' => $spec, 'is_shared' => true, 'pinned' => $pinned, 'schedule' => $schedule]);
            }
            Goal::create(['metric' => 'revenue_won', 'period' => 'month', 'target' => 150000, 'created_by' => $admin->id]);
            Goal::create(['metric' => 'leads_converted', 'period' => 'quarter', 'target' => 30, 'created_by' => $admin->id]);
            $reps->each(fn (User $rep) => Goal::create(['user_id' => $rep->id, 'metric' => 'calls_logged', 'period' => 'month', 'target' => 12, 'created_by' => $admin->id]));
            $reps->take(2)->each(fn (User $rep) => Goal::create(['user_id' => $rep->id, 'metric' => 'revenue_won', 'period' => 'month', 'target' => 40000, 'created_by' => $admin->id]));
        });
    }
}
