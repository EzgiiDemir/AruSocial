import 'campus_models.dart';

/// The real checkbox permission keys (docs/EKSIKLER.md admin §9) — mirrors
/// `App\Services\GranularPermissions::KEYS` on the backend exactly. Each
/// key is a genuine, individually-enforced additive override on top of a
/// person's role template (see `EnsurePermission`), not decoration.
const kGranularPermissionKeys = [
  'events.manage',
  'pendingActivities.manage',
  'clubs.manage',
  'sports.manage',
  'services.manage',
  'food.manage',
  'media.manage',
  'surveys.manage',
  'email.send',
  'moderation.moderate',
  'activityLog.view',
  'users.manage',
  'siteSettings.manage',
];

/// A real `users` row (docs/EKSIKLER.md admin §9) — distinct from
/// [RoleAssignment], which is the pre-provisioned email->role mapping that
/// can exist before a real account does. This is the account itself, with
/// its real role/permissions resolved alongside it and real usage counters.
class AdminUser {
  final String id;
  final String name;
  final String email;
  final UserRole role;
  final List<String> permissions;
  final bool active;
  final bool banned;
  final int strikes;
  final int xp;
  final int checkins;
  final int eventsJoined;
  final DateTime? createdAt;
  // Only populated by the single-user detail endpoint.
  final int? postsCount;
  final int? commentsCount;
  final int? followersCount;
  final int? followingCount;

  const AdminUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.permissions,
    required this.active,
    required this.banned,
    required this.strikes,
    required this.xp,
    required this.checkins,
    required this.eventsJoined,
    required this.createdAt,
    this.postsCount,
    this.commentsCount,
    this.followersCount,
    this.followingCount,
  });

  factory AdminUser.fromJson(Map<String, dynamic> json) => AdminUser(
        id: json['id'] as String,
        name: json['name'] as String,
        email: json['email'] as String,
        role: UserRole.values.byName(json['role'] as String),
        permissions: (json['permissions'] as List<dynamic>? ?? const []).cast<String>(),
        active: json['active'] as bool,
        banned: json['banned'] as bool,
        strikes: json['strikes'] as int,
        xp: json['xp'] as int,
        checkins: json['checkins'] as int,
        eventsJoined: json['eventsJoined'] as int,
        createdAt: json['createdAt'] == null ? null : DateTime.tryParse(json['createdAt'] as String),
        postsCount: json['postsCount'] as int?,
        commentsCount: json['commentsCount'] as int?,
        followersCount: json['followersCount'] as int?,
        followingCount: json['followingCount'] as int?,
      );
}
