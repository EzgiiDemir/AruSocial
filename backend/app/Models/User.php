<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\GranularPermissions;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    // Deliberately *not* SoftDeletes, unlike the content models the panels
    // manage. Deleting an account is supposed to take the person's posts,
    // stories, club memberships and onboarding progress with it, and that
    // erasure is done by `ON DELETE CASCADE` in the schema — a soft delete
    // never issues a DELETE, so none of those cascades would fire and a
    // "deleted" student's content would stay on the feed.
    //
    // Reversible lockout already exists and is the right tool for a
    // mis-click: ban/enforcement keeps the account and its data while
    // shutting the person out. Deletion stays the irreversible one,
    // because that is what it promises the student.
    use HasApiTokens, HasFactory, Notifiable;

    /** Everyone with at least one back-office capability uses the one admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => GranularPermissions::canAccessAdminPanel($this),
            default => false,
        };
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'level',
        'xp',
        'places',
        'events',
        'memories',
        'interests',
        'avatar_url',
        'strikes',
        'banned_at',
        'banned_until',
        'last_violation_at',
        'moderation_status',
        'moderation_reason',
        'department',
        'year',
        'university',
        'clubs',
        'achievements',
        'projects',
        'location_visibility',
        'nearby_discoverable',
        'check_in_visible',
        'personalization',
        'is_private_profile',
        'preferred_language',
        'account_status',
        'phone',
        'institution_id',
        'job_title',
        'timezone',
        'campus',
        'faculty',
        'unit',
        'building',
        'mfa_required',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'interests' => 'array',
            'banned_at' => 'datetime',
            'banned_until' => 'datetime',
            'last_violation_at' => 'datetime',
            // Free-text profile display lists (Social tab bio editor) — not
            // the same as club_members ("clubs" here is a name a student
            // typed in, no FK to the clubs table).
            'clubs' => 'array',
            'achievements' => 'array',
            'projects' => 'array',
            'nearby_discoverable' => 'boolean',
            'check_in_visible' => 'boolean',
            'personalization' => 'boolean',
            'is_private_profile' => 'boolean',
            'mfa_required' => 'boolean',
        ];
    }

    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleGrant::class);
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function feedPosts(): HasMany
    {
        return $this->hasMany(FeedPost::class, 'author_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'author_id');
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'social_follows', 'follower_user_id', 'followed_user_id')
            ->wherePivot('status', 'accepted')
            ->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'social_follows', 'followed_user_id', 'follower_user_id')
            ->wherePivot('status', 'accepted')
            ->withTimestamps();
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'social_blocks', 'blocker_user_id', 'blocked_user_id')
            ->withTimestamps();
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    // The exact shape CampusUserDto.fromJson (Flutter's
    // lib/core/network/campus_dtos.dart) parses. Kept on the model rather
    // than in a controller because two endpoints now return a user —
    // GET /me and the login response — and the client parses both with the
    // same code, so they must not drift apart.
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            // The role authorization actually enforces (role_assignments),
            // not the profile column — otherwise /me could keep saying
            // "student" for someone an admin has since promoted, or claim a
            // role the API would refuse to honour.
            'role' => GranularPermissions::roleOf($this),
            'level' => $this->level,
            'xp' => $this->xp,
            'places' => $this->places,
            'events' => $this->events,
            'memories' => $this->memories,
            'interests' => $this->interests ?? [],
            'avatarUrl' => $this->avatar_url,
            'isPrivateProfile' => (bool) $this->is_private_profile,
            'department' => $this->department,
            'year' => $this->year,
            'university' => $this->university,
            'clubs' => $this->clubs ?? [],
            'achievements' => $this->achievements ?? [],
            'projects' => $this->projects ?? [],
        ];
    }
}
