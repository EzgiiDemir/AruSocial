import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Real, persisted overrides for the student's own bio fields — the "Edit
/// Profile" flow in the Social tab's Profile screen actually writes here,
/// so edits survive app restarts instead of just mutating in-memory state.
/// Layered on top of the base seeded [CampusUser] the same way avatar
/// overrides already work via `AppSettingsStore`.
class ProfileBioEdits {
  final String? department;
  final String? year;
  final String? university;
  final List<String> clubs;
  final List<String> achievements;
  final List<String> projects;

  const ProfileBioEdits({
    this.department,
    this.year,
    this.university,
    this.clubs = const [],
    this.achievements = const [],
    this.projects = const [],
  });

  Map<String, dynamic> toJson() => {
        'department': department,
        'year': year,
        'university': university,
        'clubs': clubs,
        'achievements': achievements,
        'projects': projects,
      };

  factory ProfileBioEdits.fromJson(Map<String, dynamic> json) => ProfileBioEdits(
        department: json['department'] as String?,
        year: json['year'] as String?,
        university: json['university'] as String?,
        clubs: (json['clubs'] as List<dynamic>? ?? const []).cast<String>(),
        achievements: (json['achievements'] as List<dynamic>? ?? const []).cast<String>(),
        projects: (json['projects'] as List<dynamic>? ?? const []).cast<String>(),
      );
}

class ProfileBioStore {
  static const _key = 'profile.bio_edits.v1';

  static Future<ProfileBioEdits?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null || raw.isEmpty) return null;
    return ProfileBioEdits.fromJson(jsonDecode(raw) as Map<String, dynamic>);
  }

  static Future<void> save(ProfileBioEdits edits) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, jsonEncode(edits.toJson()));
  }
}
