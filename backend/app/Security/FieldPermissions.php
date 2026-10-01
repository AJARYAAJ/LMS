<?php

namespace App\Security;

use App\Models\Organization;
use App\Models\User;
use App\Support\PageLayouts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Field-level permissions: per organization, a role can have a lead or deal field
 * hidden (never sent to it) or read-only (shown, but changes are refused).
 * Admins always have full access. Settings shape:
 *   settings.field_permissions = { lead: { budget: { sales_rep: "hidden", viewer: "hidden" } }, deal: {...} }
 */
class FieldPermissions
{
    public const ENTITIES = ['lead', 'deal'];

    public const ROLES = [User::MANAGER, User::SALES_REP, User::VIEWER];

    public const LEVELS = ['edit', 'read', 'hidden'];

    /** Fields that can never be restricted (records need them). */
    public const LOCKED = ['lead' => ['first_name'], 'deal' => ['name']];

    private static ?User $viewer = null;

    /** @var array<int, array> */
    private static array $cache = [];

    public static function setViewer(?User $user): void
    {
        self::$viewer = $user;
    }

    public static function viewer(): ?User
    {
        return self::$viewer;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Keys a role may be restricted on: standard fields plus custom.<key>. */
    public static function fields(string $entity, int $organizationId): array
    {
        return array_values(array_diff(PageLayouts::catalog($entity, $organizationId), self::LOCKED[$entity] ?? []));
    }

    /** @return array{hidden: list<string>, readonly: list<string>} */
    public static function for(?User $user, string $entity): array
    {
        if (! $user || $user->role === User::ADMIN || ! in_array($entity, self::ENTITIES, true)) {
            return ['hidden' => [], 'readonly' => []];
        }
        $rules = self::$cache[$user->organization_id] ??= (Organization::find($user->organization_id)?->settings['field_permissions'] ?? []);
        $hidden = [];
        $readonly = [];
        foreach ($rules[$entity] ?? [] as $field => $roles) {
            match ($roles[$user->role] ?? 'edit') {
                'hidden' => $hidden[] = $field,
                'read' => $readonly[] = $field,
                default => null,
            };
        }

        return ['hidden' => $hidden, 'readonly' => $readonly];
    }

    /** Remove hidden fields from a serialized record for the current viewer. */
    public static function strip(string $entity, array $attributes): array
    {
        $hidden = self::for(self::$viewer, $entity)['hidden'];
        foreach ($hidden as $field) {
            if (str_starts_with($field, 'custom.')) {
                unset($attributes['custom_fields'][substr($field, 7)]);
            } else {
                unset($attributes[$field]);
                // Related objects that expose the same data (e.g. owner for owner_id).
                $relation = preg_replace('/_id$/', '', $field);
                if ($relation !== $field) {
                    unset($attributes[$relation]);
                }
            }
        }

        return $attributes;
    }

    /**
     * Writes from a role with restrictions: hidden fields are dropped (the person never
     * saw them), read-only fields may be sent unchanged (forms send the whole record)
     * but changing them is refused. Returns the data that may be saved.
     */
    public static function guard(User $user, string $entity, array $data, ?Model $record = null): array
    {
        $rules = self::for($user, $entity);
        foreach ($rules['hidden'] as $field) {
            if (str_starts_with($field, 'custom.')) {
                unset($data['custom_fields'][substr($field, 7)]);
            } else {
                unset($data[$field]);
            }
        }
        $denied = [];
        foreach ($rules['readonly'] as $field) {
            [$present, $value, $current] = str_starts_with($field, 'custom.')
                ? [array_key_exists(substr($field, 7), $data['custom_fields'] ?? []), $data['custom_fields'][substr($field, 7)] ?? null, $record?->custom_fields[substr($field, 7)] ?? null]
                : [array_key_exists($field, $data), $data[$field] ?? null, $record?->getAttribute($field)];
            if ($present && ($record ? self::normalize($value) !== self::normalize($current) : self::normalize($value) !== '')) {
                $denied[] = $field;
            }
        }
        if ($denied) {
            throw ValidationException::withMessages(['fields' => 'Your role can’t change: '.implode(', ', array_map(fn ($f) => str_replace(['custom.', '_id', '_'], ['', '', ' '], $f), $denied)).'.']);
        }

        return $data;
    }

    private static function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return is_array($value) ? json_encode($value) : trim((string) $value);
    }
}
