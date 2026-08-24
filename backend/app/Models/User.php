<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\GranularPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
        ];
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
            ->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'social_follows', 'followed_user_id', 'follower_user_id')
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
        ];
    }
}
