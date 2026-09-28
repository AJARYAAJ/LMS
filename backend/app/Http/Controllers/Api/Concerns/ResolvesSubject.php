<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Resolves /{subjectType}/{subjectId}/... routes (leads, deals, contacts,
 * accounts) to a tenant-scoped, visibility-checked record.
 */
trait ResolvesSubject
{
    protected function subject(Request $request, string $type, int $id): Model
    {
        return match ($type) {
            'leads' => Lead::visibleTo($request->user())->findOrFail($id),
            'deals' => Deal::findOrFail($id),
            'contacts' => Contact::findOrFail($id),
            'accounts' => Account::findOrFail($id),
            default => abort(404),
        };
    }
}
