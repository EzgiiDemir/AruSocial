import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/audit_log_entry.dart';
import 'contracts.dart';
import 'rest_campus_repository.dart';

export '../models/audit_log_entry.dart' show AuditLogEntry;

/// Real, persisted admin/auth activity log — replaces the previous
/// `MockAnalyticsTracker.track()` (which only ever did `print()`, visible
/// nowhere in the UI) with something an admin can actually read. Capped at
/// the most recent 500 entries so the SharedPreferences blob doesn't grow
/// unbounded — still per-device only, same honest scope as every other
/// store in this file.
class AuditLogStore {
  static const _kEntries = 'admin.audit.log.v1';
  static const _cap = 500;

  static Future<List<AuditLogEntry>> entries() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kEntries);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => AuditLogEntry.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> log({
    required String actorName,
    required String action,
    required String targetType,
    required String targetLabel,
  }) async {
    final current = await entries();
    final entry = AuditLogEntry(
      id: 'log-${DateTime.now().microsecondsSinceEpoch}',
      at: DateTime.now(),
      actorName: actorName,
      action: action,
      targetType: targetType,
      targetLabel: targetLabel,
    );
    final next = [entry, ...current];
    final capped = next.length > _cap ? next.sublist(0, _cap) : next;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEntries, jsonEncode(capped.map((e) => e.toJson()).toList()));
  }

  /// REST admin mutations are recorded in `admin_audit_log` on the server.
  /// SharedPreferences stays the mock/demo writer only.
  static Future<void> logIfMock(
    CampusRepository repository, {
    required String actorName,
    required String action,
    required String targetType,
    required String targetLabel,
  }) async {
    if (repository is RestCampusRepository) return;
    await log(
      actorName: actorName,
      action: action,
      targetType: targetType,
      targetLabel: targetLabel,
    );
  }
}
