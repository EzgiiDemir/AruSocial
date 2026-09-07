import 'package:shared_preferences/shared_preferences.dart';

/// Client-only social prefs fallback (viewed stories fade, mute/archive,
/// local groups) when the matching REST endpoints are unavailable.
class SocialLocalPrefs {
  static const _viewedStories = 'social_viewed_story_ids';
  static const _mutedPeers = 'social_muted_peers';
  static const _archivedPeers = 'social_archived_peers';
  static const _restrictedPeers = 'social_restricted_peers';
  static const _groupsJson = 'social_local_groups_v1';
  static const _mutedGroups = 'social_muted_groups';
  static const _archivedGroups = 'social_archived_groups';

  static Future<Set<String>> viewedStoryIds() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_viewedStories) ?? const []).toSet();
  }

  static Future<void> markStoryViewed(String id) async {
    final p = await SharedPreferences.getInstance();
    final next = <String>{
      ...(p.getStringList(_viewedStories) ?? const <String>[]),
      id,
    };
    await p.setStringList(_viewedStories, next.toList());
  }

  static Future<Set<String>> mutedPeers() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_mutedPeers) ?? const []).toSet();
  }

  static Future<bool> toggleMute(String peer) async {
    final p = await SharedPreferences.getInstance();
    final set = <String>{...(p.getStringList(_mutedPeers) ?? const <String>[])};
    if (set.contains(peer)) {
      set.remove(peer);
    } else {
      set.add(peer);
    }
    await p.setStringList(_mutedPeers, set.toList());
    return set.contains(peer);
  }

  static Future<Set<String>> archivedPeers() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_archivedPeers) ?? const []).toSet();
  }

  static Future<bool> toggleArchive(String peer) async {
    final p = await SharedPreferences.getInstance();
    final set =
        <String>{...(p.getStringList(_archivedPeers) ?? const <String>[])};
    if (set.contains(peer)) {
      set.remove(peer);
    } else {
      set.add(peer);
    }
    await p.setStringList(_archivedPeers, set.toList());
    return set.contains(peer);
  }

  static Future<Set<String>> restrictedPeers() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_restrictedPeers) ?? const []).toSet();
  }

  static Future<bool> toggleRestrict(String peer) async {
    final p = await SharedPreferences.getInstance();
    final set =
        <String>{...(p.getStringList(_restrictedPeers) ?? const <String>[])};
    if (set.contains(peer)) {
      set.remove(peer);
    } else {
      set.add(peer);
    }
    await p.setStringList(_restrictedPeers, set.toList());
    return set.contains(peer);
  }

  /// Local groups: id → {name, members: [names]}
  static Future<List<Map<String, dynamic>>> groups() async {
    final p = await SharedPreferences.getInstance();
    final raw = p.getStringList(_groupsJson) ?? const [];
    return raw
        .map((e) {
          try {
            final parts = e.split('\u001f');
            if (parts.length < 2) return null;
            return {
              'id': parts[0],
              'name': parts[1],
              'members': parts.length > 2
                  ? parts[2].split(',').where((s) => s.isNotEmpty).toList()
                  : <String>[],
            };
          } catch (_) {
            return null;
          }
        })
        .whereType<Map<String, dynamic>>()
        .toList();
  }

  static Future<void> saveGroup({
    required String id,
    required String name,
    required List<String> members,
  }) async {
    final p = await SharedPreferences.getInstance();
    final existing = await groups();
    existing.removeWhere((g) => g['id'] == id);
    existing.add({'id': id, 'name': name, 'members': members});
    await p.setStringList(
      _groupsJson,
      existing
          .map((g) =>
              '${g['id']}\u001f${g['name']}\u001f${(g['members'] as List).join(',')}')
          .toList(),
    );
  }

  static Future<void> deleteGroup(String id) async {
    final p = await SharedPreferences.getInstance();
    final existing = await groups();
    existing.removeWhere((g) => g['id'] == id);
    await p.setStringList(
      _groupsJson,
      existing
          .map((g) =>
              '${g['id']}\u001f${g['name']}\u001f${(g['members'] as List).join(',')}')
          .toList(),
    );
    final muted = await mutedGroupIds();
    muted.remove(id);
    await p.setStringList(_mutedGroups, muted.toList());
    final archived = await archivedGroupIds();
    archived.remove(id);
    await p.setStringList(_archivedGroups, archived.toList());
  }

  static Future<Set<String>> mutedGroupIds() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_mutedGroups) ?? const []).toSet();
  }

  static Future<bool> toggleMuteGroup(String id) async {
    final p = await SharedPreferences.getInstance();
    final set =
        <String>{...(p.getStringList(_mutedGroups) ?? const <String>[])};
    if (set.contains(id)) {
      set.remove(id);
    } else {
      set.add(id);
    }
    await p.setStringList(_mutedGroups, set.toList());
    return set.contains(id);
  }

  static Future<Set<String>> archivedGroupIds() async {
    final p = await SharedPreferences.getInstance();
    return (p.getStringList(_archivedGroups) ?? const []).toSet();
  }

  static Future<bool> toggleArchiveGroup(String id) async {
    final p = await SharedPreferences.getInstance();
    final set =
        <String>{...(p.getStringList(_archivedGroups) ?? const <String>[])};
    if (set.contains(id)) {
      set.remove(id);
    } else {
      set.add(id);
    }
    await p.setStringList(_archivedGroups, set.toList());
    return set.contains(id);
  }
}
