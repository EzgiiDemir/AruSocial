import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/admin_user.dart';
import '../models/campus_models.dart';

export '../models/admin_user.dart' show AdminUser, kGranularPermissionKeys;

/// Mock-mode counterpart to the real backend's `Admin\UserController` —
/// on-device only (no shared multi-admin backend to persist to), same
/// honest scope limitation as every other AdminContentStore-style class in
/// Mock mode. The one real account the device is signed in as isn't
/// represented here (it's tracked separately) — this store only holds
/// users an admin has explicitly pre-provisioned on this device.
class AdminUserStore {
  static const _key = 'admin.users.v1';

  static Future<List<AdminUser>> _all() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => AdminUser.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> _save(List<AdminUser> users) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
        _key,
        jsonEncode(users
            .map((u) => {
                  'id': u.id,
                  'name': u.name,
                  'email': u.email,
                  'role': u.role.name,
                  'permissions': u.permissions,
                  'active': u.active,
                  'banned': u.banned,
                  'strikes': u.strikes,
                  'xp': u.xp,
                  'checkins': u.checkins,
                  'eventsJoined': u.eventsJoined,
                  'createdAt': u.createdAt?.toIso8601String(),
                })
            .toList()));
  }

  static Future<List<AdminUser>> list() => _all();

  static Future<List<AdminUser>> banned() async =>
      (await _all()).where((u) => u.banned).toList();

  static Future<AdminUser> create(
      {required String name, required String email, required UserRole role, List<String> permissions = const []}) async {
    final current = await _all();
    final user = AdminUser(
      id: 'localuser-${DateTime.now().microsecondsSinceEpoch}',
      name: name,
      email: email.trim().toLowerCase(),
      role: role,
      permissions: kGranularPermissionKeys.where(permissions.contains).toList(),
      active: true,
      banned: false,
      strikes: 0,
      xp: 0,
      checkins: 0,
      eventsJoined: 0,
      createdAt: DateTime.now(),
    );
    await _save([...current, user]);
    return user;
  }

  static Future<AdminUser> _replace(String id, AdminUser Function(AdminUser) update) async {
    final current = await _all();
    final updated = current.map((u) => u.id == id ? update(u) : u).toList();
    await _save(updated);
    return updated.firstWhere((u) => u.id == id);
  }

  static Future<AdminUser> setActive(String id, bool active) => _replace(
      id,
      (u) => AdminUser(
          id: u.id,
          name: u.name,
          email: u.email,
          role: u.role,
          permissions: u.permissions,
          active: active,
          banned: u.banned,
          strikes: u.strikes,
          xp: u.xp,
          checkins: u.checkins,
          eventsJoined: u.eventsJoined,
          createdAt: u.createdAt));

  static Future<AdminUser> updateRole(String id, {required UserRole role, required List<String> permissions}) =>
      _replace(
          id,
          (u) => AdminUser(
              id: u.id,
              name: u.name,
              email: u.email,
              role: role,
              permissions: kGranularPermissionKeys.where(permissions.contains).toList(),
              active: u.active,
              banned: u.banned,
              strikes: u.strikes,
              xp: u.xp,
              checkins: u.checkins,
              eventsJoined: u.eventsJoined,
              createdAt: u.createdAt));

  static Future<AdminUser> unban(String id) => _replace(
      id,
      (u) => AdminUser(
          id: u.id,
          name: u.name,
          email: u.email,
          role: u.role,
          permissions: u.permissions,
          active: u.active,
          banned: false,
          strikes: 0,
          xp: u.xp,
          checkins: u.checkins,
          eventsJoined: u.eventsJoined,
          createdAt: u.createdAt));

  static Future<AdminUser?> byId(String id) async {
    final all = await _all();
    for (final u in all) {
      if (u.id == id) return u;
    }
    return null;
  }
}
