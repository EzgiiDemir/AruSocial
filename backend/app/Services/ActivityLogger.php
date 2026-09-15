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
    // `$meta` is a free-text note, and it defaults to empty on purpose. It
    // used to default to the literal 'az önce' ("just now") and the app
    // rendered it as the row's timestamp — so every entry in a student's
    // history claimed to have happened moments ago, in Turkish, however old
    // it was. The time now comes from `created_at`, rendered live in the
    // reader's own language and time zone.
    public static function log(int $userId, string $kind, string $title, string $subtitle, string $meta = ''): void
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
