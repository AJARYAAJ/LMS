<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Touchpoint;
use App\Models\WebForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_landing_page_shows_blocks_counts_views_and_captures_leads_with_utm(): void
    {
        $admin = $this->organization();
        [$form, $campaign] = $this->inTenant($admin, fn () => [WebForm::first(), Campaign::create(['name' => 'Spring launch'])]);
        $this->as($admin)->getJson('/api/v1/landing-pages/suggest-slug?name=Spring Launch!')->assertJsonPath('data.slug', 'spring-launch');

        $page = $this->as($admin)->postJson('/api/v1/landing-pages', [
            'name' => 'Spring launch', 'slug' => 'spring-launch', 'campaign_id' => $campaign->id,
            'blocks' => [
                ['type' => 'hero', 'heading' => 'Close more deals', 'subheading' => 'In half the time', 'button_label' => 'Get a demo', 'secret' => 'dropped'],
                ['type' => 'features', 'heading' => 'Why', 'items' => [['title' => 'Fast', 'body' => 'Really fast']]],
                ['type' => 'form', 'heading' => 'Talk to us'],
            ],
        ])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('secret', $page['blocks'][0]);
        $this->getJson('/api/v1/public/pages/spring-launch')->assertNotFound(); // drafts are private

        $this->as($admin)->putJson("/api/v1/landing-pages/{$page['id']}", ['is_published' => true])->assertStatus(422); // form block needs a form
        $this->as($admin)->putJson("/api/v1/landing-pages/{$page['id']}", ['is_published' => true, 'web_form_id' => $form->id])->assertOk();

        $this->getJson('/api/v1/public/pages/spring-launch')->assertOk()
            ->assertJsonPath('data.blocks.0.heading', 'Close more deals')->assertJsonPath('data.form.submit_label', $form->submit_label);
        $this->postJson('/api/v1/public/pages/spring-launch/submit', ['name' => 'Pia Page', 'email' => 'pia@example.org', 'utm_source' => 'linkedin', 'utm_medium' => 'paid'])->assertCreated();

        $lead = $this->inTenant($admin, fn () => Lead::where('email', 'pia@example.org')->first());
        $this->assertSame($campaign->id, $lead->campaign_id);
        $this->assertSame('linkedin', $lead->custom_fields['utm_source']);
        $this->assertSame('Spring launch', $lead->custom_fields['landing_page']);
        $this->assertSame('landing_page', $this->inTenant($admin, fn () => Touchpoint::where('lead_id', $lead->id)->value('channel')));
        $this->as($admin)->getJson('/api/v1/landing-pages')->assertJsonPath('data.0.views', 1)->assertJsonPath('data.0.submissions', 1);

        $rep = $this->member($admin);
        $this->as($rep)->getJson('/api/v1/landing-pages')->assertForbidden();
    }

    public function test_tracked_links_add_utm_tags_and_count_clicks(): void
    {
        $admin = $this->organization();
        $link = $this->as($admin)->postJson('/api/v1/tracked-links', [
            'destination' => 'https://acme.test/pricing?plan=pro#faq', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'spring launch',
        ])->assertCreated()->json('data');
        $this->assertSame('https://acme.test/pricing?plan=pro&utm_source=newsletter&utm_medium=email&utm_campaign=spring+launch#faq', $link['tagged_url']);
        $this->as($admin)->postJson('/api/v1/tracked-links', ['destination' => 'javascript:alert(1)', 'utm_source' => 'a', 'utm_medium' => 'b', 'utm_campaign' => 'c'])->assertStatus(422);

        $this->get("/l/{$link['code']}")->assertRedirect($link['tagged_url']);
        $this->get("/l/{$link['code']}");
        $this->as($admin)->getJson('/api/v1/tracked-links')->assertJsonPath('data.0.clicks', 2);
        $this->get('/l/nope123')->assertNotFound();
    }
}
