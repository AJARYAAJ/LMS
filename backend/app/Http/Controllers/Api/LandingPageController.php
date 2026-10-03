<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\WebForm;
use App\Services\WebFormSubmitter;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Hosted landing pages built from blocks, with a lead form and view / conversion counts. */
class LandingPageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => LandingPage::with('campaign:id,name', 'form:id,name')->latest('id')->get()
            ->map(fn (LandingPage $p) => [...$p->toArray(), 'url' => $p->url()])]);
    }

    public function show(int $id): JsonResponse
    {
        $p = LandingPage::findOrFail($id);

        return response()->json(['data' => [...$p->toArray(), 'url' => $p->url()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $page = LandingPage::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return response()->json(['data' => [...$page->fresh()->toArray(), 'url' => $page->url()]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = LandingPage::findOrFail($id);
        $page->update($this->validated($request, $page));

        return response()->json(['data' => [...$page->fresh()->toArray(), 'url' => $page->url()]]);
    }

    public function destroy(int $id): JsonResponse
    {
        LandingPage::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function suggestSlug(Request $request): JsonResponse
    {
        $base = Str::slug((string) $request->query('name')) ?: 'page';
        $slug = $base;
        for ($i = 2; LandingPage::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return response()->json(['data' => ['slug' => $slug]]);
    }

    // ------------------------------------------------------------ public

    public function publicShow(string $slug): JsonResponse
    {
        $page = $this->published($slug);
        $page->increment('views');
        $form = $page->form?->is_active ? $page->form : null;

        return response()->json(['data' => [
            'name' => $page->name, 'blocks' => $page->blocks, 'accent_color' => $page->accent_color,
            'seo_title' => $page->seo_title ?: $page->name, 'seo_description' => $page->seo_description,
            'organization' => $page->organization->name,
            'form' => $form?->only(['fields', 'submit_label', 'success_message', 'redirect_url']),
        ]]);
    }

    public function submit(Request $request, string $slug, WebFormSubmitter $submitter): JsonResponse
    {
        $page = $this->published($slug);
        $form = $page->form;
        abort_unless($form?->is_active, 404, 'This page has no form.');
        if ($request->filled('_hp')) {
            return response()->json(['message' => $form->success_message], 202);
        }
        $lead = $submitter->submit($form, $request, [
            'campaign_id' => $page->campaign_id ?? $form->campaign_id,
            'custom_fields' => ['landing_page' => $page->name],
            'channel' => 'landing_page',
        ]);
        $page->increment('submissions');

        return response()->json(['message' => $form->success_message, 'redirect_url' => $form->redirect_url, 'id' => $lead->id], 201);
    }

    private function published(string $slug): LandingPage
    {
        return LandingPage::withoutGlobalScopes()->with('organization:id,name', 'form')->where('slug', $slug)->where('is_published', true)->firstOrFail();
    }

    private function validated(Request $request, ?LandingPage $page = null): array
    {
        $req = $page ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'slug' => [$req, 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('landing_pages', 'slug')->ignore($page?->id)],
            'campaign_id' => ['nullable', 'integer', Rules::exists('campaigns')],
            'web_form_id' => ['nullable', 'integer', Rules::exists('web_forms')],
            'is_published' => ['sometimes', 'boolean'],
            'accent_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'seo_title' => ['nullable', 'string', 'max:190'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'blocks' => [$req, 'array', 'max:20'],
            'blocks.*.type' => ['required', Rule::in(array_keys(LandingPage::BLOCKS))],
            'blocks.*.heading' => ['nullable', 'string', 'max:190'],
            'blocks.*.subheading' => ['nullable', 'string', 'max:500'],
            'blocks.*.body' => ['nullable', 'string', 'max:5000'],
            'blocks.*.button_label' => ['nullable', 'string', 'max:60'],
            'blocks.*.image_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'blocks.*.quote' => ['nullable', 'string', 'max:1000'],
            'blocks.*.author' => ['nullable', 'string', 'max:120'],
            'blocks.*.role' => ['nullable', 'string', 'max:120'],
            'blocks.*.items' => ['nullable', 'array', 'max:6'],
            'blocks.*.items.*.title' => ['required', 'string', 'max:120'],
            'blocks.*.items.*.body' => ['nullable', 'string', 'max:500'],
        ]);
        if (isset($data['blocks'])) {
            // Keep only each block's own fields.
            $data['blocks'] = array_map(fn ($b) => ['type' => $b['type'], ...array_intersect_key($b, array_flip(LandingPage::BLOCKS[$b['type']]))], $data['blocks']);
        }
        if (($data['is_published'] ?? false) && ! ($data['web_form_id'] ?? $page?->web_form_id) && collect($data['blocks'] ?? $page?->blocks)->contains('type', 'form')) {
            abort(422, 'Pick the web form this page collects leads with before publishing.');
        }
        if (! empty($data['web_form_id'])) {
            abort_unless(WebForm::whereKey($data['web_form_id'])->exists(), 422);
        }

        return $data;
    }
}
