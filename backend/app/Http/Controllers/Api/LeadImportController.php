<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV lead import with header auto-mapping and duplicate detection.
 */
class LeadImportController extends Controller
{
    private const MAX_ROWS = 5000;

    /** Accepted header aliases → lead attribute. */
    private const HEADER_MAP = [
        'first_name' => ['first_name', 'firstname', 'first name', 'given name'],
        'last_name' => ['last_name', 'lastname', 'last name', 'surname', 'family name'],
        'name' => ['name', 'full name', 'full_name', 'contact name'],
        'email' => ['email', 'email address', 'e-mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'telephone'],
        'company' => ['company', 'company name', 'organization', 'organisation', 'account'],
        'job_title' => ['job_title', 'title', 'job title', 'position', 'designation'],
        'website' => ['website', 'url', 'web'],
        'industry' => ['industry'],
        'city' => ['city'],
        'state' => ['state', 'region', 'province'],
        'country' => ['country'],
        'status' => ['status', 'lead status'],
        'source' => ['source', 'lead source'],
        'owner' => ['owner', 'owner email', 'assigned to'],
        'priority' => ['priority'],
        'budget' => ['budget'],
        'expected_value' => ['expected_value', 'expected value', 'deal value', 'value'],
        'requirements' => ['requirements', 'notes', 'description'],
        'tags' => ['tags', 'labels'],
    ];

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'source', 'status', 'owner', 'priority', 'expected_value', 'tags']);
            fputcsv($out, ['Jane', 'Cooper', 'jane@acme.com', '+1 555 0100', 'Acme Inc', 'Head of Ops', 'website', 'new', '', 'high', '12000', 'Enterprise|Hot']);
            fclose($out);
        }, 'lead-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(Request $request, LeadService $leads, DuplicateDetector $duplicates): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'skip_duplicates' => ['sometimes', 'boolean'],
            'default_source_id' => ['nullable', 'integer'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = array_map(fn ($h) => Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $mapping = $this->mapHeaders($headers);

        if (! isset($mapping['first_name']) && ! isset($mapping['name'])) {
            fclose($handle);

            return response()->json(['message' => 'The file needs a "first_name" or "name" column.', 'headers' => $headers], 422);
        }

        $lookups = [
            'status' => LeadStatus::get()->flatMap(fn ($s) => [Str::lower($s->key) => $s->id, Str::lower($s->name) => $s->id]),
            'source' => LeadSource::get()->flatMap(fn ($s) => [Str::lower($s->key) => $s->id, Str::lower($s->name) => $s->id]),
            'owner' => User::get()->flatMap(fn ($u) => [Str::lower($u->email) => $u->id, Str::lower($u->name) => $u->id]),
        ];

        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $line = 1;
        $skipDuplicates = $request->boolean('skip_duplicates', true);
        $actor = $request->user();

        while (($row = fgetcsv($handle)) !== false && $line <= self::MAX_ROWS) {
            $line++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $raw = [];
            foreach ($mapping as $field => $index) {
                $raw[$field] = isset($row[$index]) ? trim($row[$index]) : null;
            }

            $data = $this->toLead($raw, $lookups, $request->input('default_source_id'));
            if ($actor->role === User::SALES_REP) {
                $data['owner_id'] = $actor->id;
            }

            $validator = Validator::make($data, [
                'first_name' => ['required', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:190'],
                'priority' => ['nullable', 'in:low,medium,high,urgent'],
                'budget' => ['nullable', 'numeric'],
                'expected_value' => ['nullable', 'numeric'],
            ]);

            if ($validator->fails()) {
                $result['failed']++;
                $result['errors'][] = ['line' => $line, 'message' => $validator->errors()->first()];

                continue;
            }

            if ($skipDuplicates && $duplicates->find($data['email'] ?? null, $data['phone'] ?? null)->isNotEmpty()) {
                $result['skipped']++;

                continue;
            }

            $leads->create(array_filter($data, fn ($v) => $v !== null && $v !== ''), $actor, 'import');
            $result['created']++;
        }

        fclose($handle);
        $result['errors'] = array_slice($result['errors'], 0, 50);

        return response()->json(['data' => $result]);
    }

    private function mapHeaders(array $headers): array
    {
        $mapping = [];
        foreach (self::HEADER_MAP as $field => $aliases) {
            foreach ($headers as $index => $header) {
                if (in_array($header, $aliases, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function toLead(array $raw, array $lookups, mixed $defaultSource): array
    {
        if (empty($raw['first_name']) && ! empty($raw['name'])) {
            $parts = preg_split('/\s+/', $raw['name'], 2);
            $raw['first_name'] = $parts[0];
            $raw['last_name'] ??= $parts[1] ?? null;
        }

        $tagIds = collect(preg_split('/[|;,]/', (string) ($raw['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()
            ->map(fn ($t) => Tag::firstOrCreate(['name' => $t])->id)
            ->all();

        return [
            'first_name' => $raw['first_name'] ?? null,
            'last_name' => $raw['last_name'] ?? null,
            'email' => $raw['email'] ?? null,
            'phone' => $raw['phone'] ?? null,
            'company' => $raw['company'] ?? null,
            'job_title' => $raw['job_title'] ?? null,
            'website' => $raw['website'] ?? null,
            'industry' => $raw['industry'] ?? null,
            'city' => $raw['city'] ?? null,
            'state' => $raw['state'] ?? null,
            'country' => $raw['country'] ?? null,
            'lead_status_id' => $lookups['status'][Str::lower($raw['status'] ?? '')] ?? null,
            'lead_source_id' => $lookups['source'][Str::lower($raw['source'] ?? '')] ?? (LeadSource::whereKey($defaultSource)->value('id')),
            'owner_id' => $lookups['owner'][Str::lower($raw['owner'] ?? '')] ?? null,
            'priority' => ! empty($raw['priority']) ? Str::lower($raw['priority']) : null,
            'budget' => $raw['budget'] ?? null,
            'expected_value' => $raw['expected_value'] ?? null,
            'requirements' => $raw['requirements'] ?? null,
            'tag_ids' => $tagIds ?: null,
        ];
    }
}
