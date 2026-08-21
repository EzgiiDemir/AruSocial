import 'campus_models.dart';

/// Mirrors the backend's `role_assignments` shape (`RoleController::index()`).
/// Kept as a plain model file (no `shared_preferences`/Flutter dependency)
/// so `RestCampusRepository` and `tool/verify_rest_backend.dart` can use it
/// without pulling in platform-bound code they don't need.
class RoleAssignment {
  final String email;
  final UserRole role;
  final List<String> permissions;
  final DateTime assignedAt;
  final String assignedBy;

  const RoleAssignment({
    required this.email,
    required this.role,
    this.permissions = const [],
    required this.assignedAt,
    required this.assignedBy,
  });

  factory RoleAssignment.fromJson(Map<String, dynamic> json) => RoleAssignment(
        email: json['email'] as String,
        role: UserRole.values.byName(json['role'] as String),
        permissions: (json['permissions'] as List<dynamic>? ?? const []).cast<String>(),
        assignedAt: DateTime.parse(json['assignedAt'] as String),
        assignedBy: json['assignedBy'] as String,
      );

  Map<String, dynamic> toJson() => {
        'email': email,
        'role': role.name,
        'permissions': permissions,
        'assignedAt': assignedAt.toIso8601String(),
        'assignedBy': assignedBy,
      };
}
