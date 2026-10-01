<?php

namespace Database\Seeders;

use App\Jobs\SimulateCallResult;
use App\Models\Activity;
use App\Models\AiAgent;
use App\Models\AssignmentRule;
use App\Models\BookingPage;
use App\Models\Broadcast;
use App\Models\Campaign;
use App\Models\Dashboard;
use App\Models\Deal;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\SavedReport;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Reports\ReportEngine;
use App\Services\BroadcastService;
use App\Services\CallService;
use App\Services\ConversionPredictor;
use App\Services\LeadService;
use App\Services\OrganizationProvisioner;
use App\Services\QuoteService;
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
                    // First touch within minutes-to-hours (speed to lead), later ones a day apart.
                    $when = ($n === 1 ? $createdAt->copy()->addMinutes($faker->numberBetween(4, 420)) : $createdAt->copy()->addDays($n))->min(now());
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

            // The AI receptionist answered a few calls: one from a known lead, two new callers.
            $receptionist = AiAgent::where('mode', 'inbound')->first();
            collect([Lead::whereNotNull('phone')->whereNull('converted_at')->inRandomOrder()->value('phone'), '+15550104477', '+15550109321'])->filter()->values()
                ->each(fn ($from, $i) => SimulateCallResult::dispatchSync(
                    app(CallService::class)->receiveInbound($receptionist->integration, $from, "sim_in_demo_{$i}", $receptionist)->id,
                ));

            // An email campaign that A/B tested two subject lines, with opens and clicks.
            $broadcasts = app(BroadcastService::class);
            $broadcast = Broadcast::create([
                'name' => 'Q3 product update', 'created_by' => $admin->id, 'campaign_id' => Campaign::value('id'),
                'conditions' => [['field' => 'industry', 'operator' => 'in', 'value' => 'Software,Retail']],
                'variants' => [
                    ['key' => 'A', 'subject' => 'What’s new in Q3', 'body' => "Hi {first_name},\n\nHere is what we shipped this quarter: https://example.com/q3-update\n\nBest,\n{sender.name}"],
                    ['key' => 'B', 'subject' => '{first_name}, 3 things your team asked for', 'body' => "Hi {first_name},\n\nYou asked, we built it: https://example.com/q3-update\n\nBest,\n{sender.name}"],
                ],
                'test_percent' => 30, 'winner_metric' => 'click', 'winner_after_hours' => 4,
            ]);
            // During the test the personal subject line (B) clearly does better; afterwards it's a normal mix.
            $engage = function (bool $test) use ($broadcast, $broadcasts, $faker) {
                $broadcast->recipients()->where('status', 'sent')->whereNull('opened_at')->get()->each(function ($r, $i) use ($broadcasts, $faker, $test) {
                    if ($test ? $r->variant === 'B' || $i % 2 === 0 : $faker->boolean(55)) {
                        $broadcasts->trackOpen($r->token);
                        if ($test ? $r->variant === 'B' : $faker->boolean(30)) {
                            $url = 'https://example.com/q3-update';
                            $broadcasts->trackClick($r->token, BroadcastService::linkSignature($r->token, $url), $url);
                        }
                    }
                });
            };
            $broadcasts->launch($broadcast);
            $broadcasts->sendQueued($broadcast->refresh());
            $engage(true);
            $broadcasts->pickWinner($broadcast->refresh());
            $broadcasts->sendQueued($broadcast->refresh());
            $engage(false);

            Deal::whereNotNull('pipeline_stage_id')->get()->each(function (Deal $deal) use ($stages) {
                $stage = $stages->firstWhere('id', $deal->pipeline_stage_id);
                $deal->update([
                    'probability' => $stage->probability,
                    'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
                    'closed_at' => ($stage->is_won || $stage->is_lost) ? now()->subDays(random_int(0, min(20, now()->day - 1))) : null,
                    'expected_close_date' => now()->addDays(random_int(5, 60)),
                ]);
            });

            // Closed-out history (4–12 months ago) with realistic patterns, so the
            // conversion model and the reports have something to learn from.
            $convertRate = ['referral' => 0.6, 'partner' => 0.5, 'website' => 0.35, 'trade_show' => 0.3, 'email_campaign' => 0.2, 'api' => 0.25, 'social' => 0.12, 'cold_call' => 0.1];
            foreach (range(1, 90) as $i) {
                $source = $sources->random();
                $rating = $faker->randomElement(['cold', 'warm', 'hot', 'very_high']);
                $p = ($convertRate[$source->key] ?? 0.25) + ['cold' => -0.08, 'warm' => 0, 'hot' => 0.12, 'very_high' => 0.2][$rating];
                $won = $faker->randomFloat(2, 0, 1) < $p;
                $created = now()->subDays(random_int(120, 365))->setTime(random_int(8, 18), random_int(0, 59));
                $lead = Lead::create([
                    'first_name' => $faker->firstName(), 'last_name' => $faker->lastName(), 'company' => $faker->company(),
                    'email' => $faker->unique()->safeEmail(), 'phone' => $faker->boolean(70) ? $faker->e164PhoneNumber() : null,
                    'industry' => $faker->randomElement($industries), 'lead_source_id' => $source->id, 'rating' => $rating,
                    'priority' => $faker->randomElement(['low', 'medium', 'high']), 'score' => $won ? random_int(45, 95) : random_int(5, 70),
                    'owner_id' => $reps->random()->id,
                    'lead_status_id' => $statuses[$won ? 'converted' : $faker->randomElement(['lost', 'not_interested'])]->id,
                    'converted_at' => $won ? $created->copy()->addDays(random_int(5, 60)) : null,
                    'last_contacted_at' => $created->copy()->addDays(random_int(1, 20)),
                ]);
                $lead->forceFill([
                    'created_at' => $created, 'updated_at' => $created,
                    'first_responded_at' => $created->copy()->addMinutes($won ? random_int(5, 600) : random_int(30, 4000)),
                ])->saveQuietly();
            }
            app(ConversionPredictor::class)->refresh();

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
            // Product catalog, a second pipeline and a couple of quotes.
            foreach ([['Starter plan', 'PLAN-S', 49, 'monthly'], ['Growth plan', 'PLAN-G', 199, 'monthly'], ['Enterprise plan', 'PLAN-E', 999, 'monthly'],
                ['Onboarding package', 'SRV-ONB', 2500, 'one_time'], ['Premium support', 'SRV-SUP', 1200, 'yearly']] as [$name, $sku, $price, $billing]) {
                Product::create(['name' => $name, 'sku' => $sku, 'unit_price' => $price, 'billing' => $billing]);
            }
            $partners = Pipeline::create(['name' => 'Partnerships', 'display_order' => 1]);
            foreach ([['Intro', 10, '#64748b'], ['Pilot', 40, '#8b5cf6'], ['Contract', 70, '#f59e0b'], ['Signed', 100, '#10b981', true], ['Dropped', 0, '#ef4444', false, true]] as $i => $st) {
                PipelineStage::create(['pipeline_id' => $partners->id, 'name' => $st[0], 'probability' => $st[1], 'color' => $st[2], 'display_order' => $i, 'is_won' => $st[3] ?? false, 'is_lost' => $st[4] ?? false]);
            }
            $pilot = PipelineStage::where('pipeline_id', $partners->id)->where('name', 'Pilot')->first();
            Deal::create(['name' => 'Northwind reseller pilot', 'pipeline_stage_id' => $pilot->id, 'owner_id' => $manager->id, 'amount' => 36000, 'currency' => 'USD', 'probability' => 40, 'status' => 'open', 'expected_close_date' => now()->addDays(20)]);

            // A steady open pipeline across stages and reps, closing this quarter (forecast demo).
            $openStages = $stages->where('is_won', false)->where('is_lost', false)->values();
            foreach (['Globex expansion', 'Initech renewal', 'Umbrella onboarding', 'Stark analytics', 'Wayne logistics', 'Wonka retail', 'Hooli platform', 'Pied Piper pilot'] as $i => $name) {
                $stage = $openStages[$i % $openStages->count()];
                Deal::create(['name' => $name, 'pipeline_stage_id' => $stage->id, 'owner_id' => $reps->concat([$manager])->values()[$i % 4]->id,
                    'amount' => [12000, 48000, 9500, 75000, 22000, 31000, 64000, 15000][$i], 'currency' => 'USD', 'probability' => $stage->probability,
                    'status' => 'open', 'expected_close_date' => now()->startOfQuarter()->addDays(10 + $i * 9)->max(now()->addDays(2))]);
            }

            $quotes = app(QuoteService::class);
            $products = Product::orderBy('id')->get();
            Deal::where('status', 'open')->orderByDesc('id')->limit(2)->get()->each(function (Deal $deal, int $i) use ($quotes, $products, $admin) {
                $quote = $quotes->save($deal, ['title' => "{$deal->name} — proposal", 'discount_percent' => 10, 'tax_percent' => 8, 'items' => [
                    ['product_id' => $products[1]->id, 'name' => $products[1]->name, 'quantity' => 12, 'unit_price' => $products[1]->unit_price],
                    ['product_id' => $products[3]->id, 'name' => $products[3]->name, 'quantity' => 1, 'unit_price' => $products[3]->unit_price],
                ]], $admin);
                $quote->update(['status' => 'sent', 'sent_at' => now()->subDays(3)]);
                if ($i === 1) {
                    $quotes->respond($quote, true, 'Jamie Buyer', null, '127.0.0.1');
                }
            });

            foreach ([[$admin, 'avery', 'Intro call with Avery'], [$reps->first(), 'riley', 'Discovery call with Riley']] as [$host, $slug, $title]) {
                BookingPage::create(['user_id' => $host->id, 'slug' => $slug, 'title' => $title, 'description' => 'A quick call to understand your goals and see if we are a fit.', 'duration_minutes' => 30, 'weekdays' => [1, 2, 3, 4, 5], 'start_time' => '09:00', 'end_time' => '17:00', 'timezone' => 'UTC']);
            }

            $reports = SavedReport::orderBy('id')->pluck('id');
            Dashboard::create(['user_id' => $admin->id, 'name' => 'Sales leadership', 'is_shared' => true, 'tiles' => [
                ['id' => 'k1', 'kind' => 'kpis', 'type' => 'pipeline', 'span' => 2, 'title' => null],
                ['id' => 'r1', 'kind' => 'report', 'report_id' => $reports[0], 'span' => 1, 'title' => null],
                ['id' => 'g1', 'kind' => 'goals', 'span' => 1, 'title' => null],
                ['id' => 's1', 'kind' => 'spec', 'span' => 1, 'title' => 'Speed to lead by owner', 'spec' => app(ReportEngine::class)->normalize(['entity' => 'leads', 'metric' => 'response_hours', 'dimension' => 'owner', 'range' => 'last_90'])],
                ['id' => 'r2', 'kind' => 'report', 'report_id' => $reports[1], 'span' => 1, 'title' => null],
            ]]);
            Goal::create(['metric' => 'revenue_won', 'period' => 'month', 'target' => 150000, 'created_by' => $admin->id]);
            Goal::create(['metric' => 'leads_converted', 'period' => 'quarter', 'target' => 30, 'created_by' => $admin->id]);
            $reps->each(fn (User $rep) => Goal::create(['user_id' => $rep->id, 'metric' => 'calls_logged', 'period' => 'month', 'target' => 12, 'created_by' => $admin->id]));
            $reps->take(2)->each(fn (User $rep) => Goal::create(['user_id' => $rep->id, 'metric' => 'revenue_won', 'period' => 'month', 'target' => 40000, 'created_by' => $admin->id]));
        });
    }
}
