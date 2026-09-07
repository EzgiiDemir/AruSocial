<?php

namespace App\Models;

use App\Services\ParticipationApplicationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// The one, generic application record behind every "Katıl/Başvur" action
// app-wide (club, sport, service, career, community, help, event, …) —
// see ParticipationApplicationService for the two-stage lifecycle this
// drives:
//
//   preview_submitted -> detail_form_pending -> detail_form_submitted
//   -> under_review -> (revision_required <-> under_review)*
//   -> approved | rejected
//
// `form_payload` keeps its original column name (pre-dates the two-stage
// split) but is now the *preview* stage's answers; `detail_payload` is
// the detail stage's. Never add a second, category-specific application
// table — the category only changes which `ApplicationQuestion` rows and
// which `ResponsibleUnit` resolve for a given `target_type`.
class ParticipationApplication extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public const STATUS_PREVIEW_SUBMITTED = 'preview_submitted';
    public const STATUS_DETAIL_FORM_PENDING = 'detail_form_pending';
    public const STATUS_DETAIL_FORM_SUBMITTED = 'detail_form_submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_REVISION_REQUIRED = 'revision_required';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses where the applicant does NOT yet have real participation and the target is still "open" for them (no duplicate block should ever block a resubmission from these). */
    public const OPEN_STATUSES = [
        self::STATUS_PREVIEW_SUBMITTED,
        self::STATUS_DETAIL_FORM_PENDING,
        self::STATUS_DETAIL_FORM_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_REVISION_REQUIRED,
    ];

    protected $fillable = [
        'id', 'user_id', 'target_type', 'target_id', 'status',
        'responsible_staff_id', 'form_payload', 'detail_payload',
        'detail_form_token', 'detail_form_submitted_at',
        'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'form_payload' => 'array',
            'detail_payload' => 'array',
            'submitted_at' => 'datetime',
            'detail_form_submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responsibleStaff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'responsible_staff_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class, 'application_id')->orderBy('created_at');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'application_id')->orderByDesc('sent_at');
    }

    public function toApiArray(bool $includeDetailFormUrl = false): array
    {
        $row = [
            'id' => $this->id,
            'userId' => (string) $this->user_id,
            'studentName' => $this->user?->name,
            'studentEmail' => $this->user?->email,
            'studentDepartment' => $this->user?->department,
            'targetType' => $this->target_type,
            'targetId' => $this->target_id,
            'targetLabel' => ParticipationApplicationService::targetLabel($this),
            'status' => $this->status,
            'responsibleStaffId' => $this->responsible_staff_id,
            'responsibleStaffName' => $this->responsibleStaff?->name,
            // Kept as `formPayload` for backward compatibility with every
            // existing frontend call site — it is the preview answers.
            // `objectOrEmpty` matters here: PHP's json_encode has no way to
            // tell an empty *associative* array from an empty *list* apart
            // — both are `[]` in PHP, so an empty payload silently went
            // out as a JSON array instead of `{}`, and the frontend's
            // `Map<String, dynamic>.from(json['detailPayload'] as Map?)`
            // threw on it. Every application with no detail answers yet
            // hit this on every `/me/applications` and `/admin/applications`
            // read.
            'formPayload' => self::objectOrEmpty($this->form_payload),
            'previewPayload' => self::objectOrEmpty($this->form_payload),
            'detailPayload' => self::objectOrEmpty($this->detail_payload),
            'detailFormSubmittedAt' => $this->detail_form_submitted_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            // Real EmailLog status for this application (see EmailService)
            // — not "an email exists somewhere," the actual latest send
            // attempt's outcome, so the admin/trainer panel can honestly
            // answer "was the email sent?" per docs/API_CONTRACT.md.
            'emailSent' => $this->emailLogs->contains(fn ($log) => $log->status === 'sent'),
            'lastEmailStatus' => $this->emailLogs->first()?->status,
        ];
        if ($includeDetailFormUrl && $this->detail_form_token) {
            $row['detailFormUrl'] = ParticipationApplicationService::detailFormUrl($this);
        }

        return $row;
    }

    /**
     * PHP's `[]` is both "empty list" and "empty map" — json_encode always
     * picks the array form for an empty one, with no way to ask for `{}`
     * instead. Every payload field on this model is conceptually a JSON
     * object (question id => answer), so an empty one must be forced to
     * `stdClass` here; a non-empty associative array already encodes as an
     * object on its own and passes through unchanged.
     */
    private static function objectOrEmpty(?array $value): object|array
    {
        return empty($value) ? new \stdClass() : $value;
    }
}
