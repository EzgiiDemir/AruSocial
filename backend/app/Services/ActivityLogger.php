<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

class ActivityLogger
{
    // $kind must be one of ActivityKind's real values (checkIn, eventJoin,
    // review, comment, like, report) — see campus_models.dart. There is
    // deliberately no "post created" kind; the reference MockCampusRepository
    // doesn't log one for createPost() either.
    public static function log(int $userId, string $kind, string $title, string $subtitle, string $meta = 'az önce'): void
    {
        ActivityLog::create([
            'id' => 'activity-'.Str::uuid(),
            'user_id' => $userId,
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
