<?php

namespace App\Support;

use App\Models\CustomField;
use App\Models\Organization;

/**
 * Custom page layouts: per organization and entity, an ordered list of named
 * sections plus a list of hidden fields. Drives both the edit forms and the
 * details panels in the SPA. Fields not placed anywhere (e.g. a newly added
 * custom field) are appended to an "Additional fields" section automatically.
 */
class PageLayouts
{
    public const ENTITIES = ['lead', 'contact', 'account', 'deal'];

    /** Standard fields that can be placed on each entity's layout. */
    public const FIELDS = [
        'lead' => ['first_name', 'last_name', 'job_title', 'email', 'phone', 'company', 'website', 'industry', 'company_size',
            'city', 'state', 'country', 'lead_status_id', 'lead_source_id', 'campaign_id', 'owner_id', 'team_id', 'priority',
            'budget', 'expected_value', 'timeline', 'next_follow_up_at', 'requirements', 'tag_ids'],
        'contact' => ['first_name', 'last_name', 'email', 'phone', 'job_title', 'account_id', 'owner_id'],
        'account' => ['name', 'website', 'domain', 'industry', 'company_size', 'phone', 'annual_revenue', 'city', 'country', 'owner_id', 'description'],
        'deal' => ['name', 'amount', 'pipeline_stage_id', 'account_id', 'contact_id', 'owner_id', 'expected_close_date', 'description'],
    ];

    /** Fields that must stay visible (records cannot be saved without them). */
    public const REQUIRED = ['lead' => ['first_name'], 'contact' => ['first_name'], 'account' => ['name'], 'deal' => ['name']];

    public const DEFAULTS = [
        'lead' => [
            ['title' => 'Contact', 'fields' => ['first_name', 'last_name', 'job_title', 'email', 'phone', 'city']],
            ['title' => 'Company', 'fields' => ['company', 'website', 'industry', 'company_size', 'state', 'country']],
            ['title' => 'Pipeline & qualification', 'fields' => ['lead_status_id', 'lead_source_id', 'campaign_id', 'owner_id', 'team_id', 'priority', 'budget', 'expected_value', 'timeline', 'next_follow_up_at', 'requirements', 'tag_ids']],
        ],
        'contact' => [['title' => 'Contact', 'fields' => ['first_name', 'last_name', 'email', 'phone', 'job_title', 'account_id', 'owner_id']]],
        'account' => [['title' => 'Company', 'fields' => ['name', 'website', 'domain', 'industry', 'company_size', 'phone', 'annual_revenue', 'city', 'country', 'owner_id', 'description']]],
        'deal' => [['title' => 'Deal', 'fields' => ['name', 'amount', 'pipeline_stage_id', 'account_id', 'contact_id', 'owner_id', 'expected_close_date', 'description']]],
    ];

    /** Every placeable key for an entity: standard fields + "custom.<key>". */
    public static function catalog(string $entity, int $organizationId): array
    {
        $custom = CustomField::withoutGlobalScopes()->where('organization_id', $organizationId)->where('entity', $entity)
            ->orderBy('display_order')->pluck('key')->map(fn ($k) => "custom.{$k}")->all();

        return [...self::FIELDS[$entity], ...$custom];
    }

    /**
     * The effective layout for an entity: stored (or default) sections with
     * unknown keys dropped and unplaced fields appended.
     *
     * @return array{sections: list<array{title: string, fields: list<string>}>, hidden: list<string>, customized: bool}
     */
    public static function resolve(Organization $organization, string $entity): array
    {
        $stored = $organization->settings['layouts'][$entity] ?? null;
        $catalog = self::catalog($entity, $organization->id);
        $sections = $stored['sections'] ?? self::DEFAULTS[$entity];
        $hidden = array_values(array_intersect($stored['hidden'] ?? [], $catalog));

        $placed = [];
        $clean = [];
        foreach ($sections as $section) {
            $fields = array_values(array_filter($section['fields'] ?? [], function ($f) use ($catalog, &$placed, $hidden) {
                if (! in_array($f, $catalog, true) || in_array($f, $placed, true) || in_array($f, $hidden, true)) {
                    return false;
                }
                $placed[] = $f;

                return true;
            }));
            $clean[] = ['title' => (string) ($section['title'] ?? 'Section'), 'fields' => $fields];
        }

        $unplaced = array_values(array_diff($catalog, $placed, $hidden));
        if ($unplaced) {
            $clean[] = ['title' => 'Additional fields', 'fields' => $unplaced];
        }

        return ['sections' => $clean, 'hidden' => $hidden, 'customized' => $stored !== null];
    }

    public static function all(Organization $organization): array
    {
        return collect(self::ENTITIES)->mapWithKeys(fn ($e) => [$e => self::resolve($organization, $e)])->all();
    }
}
