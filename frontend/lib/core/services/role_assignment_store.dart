import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/campus_models.dart';
import '../models/role_assignment.dart';

export '../models/role_assignment.dart' show RoleAssignment;

/// Real, admin-editable email → role table — replaces the previous
/// hardcoded "is this the one seeded admin account" check with something an
/// admin can actually manage. Still per-device only (no backend to issue
/// real Entra role claims yet — see docs/PUBLISH_READINESS.md P0 #4), so
/// an assignment made here only takes effect on THIS device/session.
class RoleAssignmentStore {
  static const _kAssignments = 'admin.roles.assignments.v1';

  static Future<List<RoleAssignment>> assignments() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kAssignments);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => RoleAssignment.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> setRole(String email, UserRole role,
      {required String assignedBy, List<String> permissions = const []}) async {
    final current = await assignments();
    final normalized = email.trim().toLowerCase();
    final next = [
      for (final a in current) if (a.email != normalized) a,
      RoleAssignment(
          email: normalized,
          role: role,
          permissions: permissions,
          assignedAt: DateTime.now(),
          assignedBy: assignedBy),
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kAssignments, jsonEncode(next.map((a) => a.toJson()).toList()));
  }

  static Future<void> removeRole(String email) async {
    final current = await assignments();
    final normalized = email.trim().toLowerCase();
    final next = current.where((a) => a.email != normalized).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kAssignments, jsonEncode(next.map((a) => a.toJson()).toList()));
  }

  static Future<UserRole?> roleFor(String? email) async {
    if (email == null || email.isEmpty) return null;
    final normalized = email.trim().toLowerCase();
    final all = await assignments();
    for (final a in all) {
      if (a.email == normalized) return a.role;
    }
    return null;
  }
}
