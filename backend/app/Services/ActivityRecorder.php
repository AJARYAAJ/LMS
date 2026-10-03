<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;

class ActivityRecorder
{
    /**
     * Append an entry to a record's timeline.
     */
    public function record(Model $subject, string $type, string $title, array $attributes = []): Activity
    {
        return Activity::create(array_merge([
            'organization_id' => $subject->organization_id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => auth()->id(),
            'type' => $type,
            'title' => $title,
            'occurred_at' => now(),
        ], $attributes));
    }
}
