<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebForm;
use App\Services\WebFormSubmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hosted / embeddable web-to-lead forms. Public, identified by slug.
 */
class PublicFormController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $form = $this->find($slug);

        return response()->json(['data' => $form->only([
            'name', 'slug', 'title', 'description', 'fields', 'submit_label', 'success_message', 'redirect_url', 'accent_color',
        ]) + ['organization' => $form->organization->name]]);
    }

    public function submit(Request $request, string $slug, WebFormSubmitter $submitter): JsonResponse
    {
        $form = $this->find($slug);

        if ($request->filled('_hp')) {
            return response()->json(['message' => $form->success_message], 202);
        }
        $lead = $submitter->submit($form, $request);

        return response()->json(['message' => $form->success_message, 'redirect_url' => $form->redirect_url, 'id' => $lead->id], 201);
    }

    private function find(string $slug): WebForm
    {
        return WebForm::withoutGlobalScopes()->with('organization:id,name')->where('slug', $slug)->where('is_active', true)->firstOrFail();
    }
}
