import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Real, persisted follow/block state for the social layer — but local
/// only. There's no backend, so this only ever reflects what THIS device's
/// signed-in user has followed or blocked; nobody else can see or be
/// notified of it. A real multi-user social graph needs a server (see
/// docs/PUBLISH_READINESS.md).
class SocialGraphStore {
  static const _kFollowing = 'social.graph.following.v1';
  static const _kBlocked = 'social.graph.blocked.v1';

  static Future<Set<String>> following() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kFollowing);
    if (raw == null) return <String>{};
    return (jsonDecode(raw) as List).cast<String>().toSet();
  }

  static Future<Set<String>> blocked() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kBlocked);
    if (raw == null) return <String>{};
    return (jsonDecode(raw) as List).cast<String>().toSet();
  }

  static Future<void> _save(String key, Set<String> values) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(key, jsonEncode(values.toList()));
  }

  /// Returns the new following state (true = now following).
  static Future<bool> toggleFollow(String peer) async {
    final current = await following();
    final nowFollowing = !current.contains(peer);
    if (nowFollowing) {
      current.add(peer);
    } else {
      current.remove(peer);
    }
    await _save(_kFollowing, current);
    return nowFollowing;
  }

  /// Returns the new blocked state (true = now blocked). Blocking someone
  /// also unfollows them — you shouldn't stay "following" a blocked peer.
  static Future<bool> toggleBlock(String peer) async {
    final current = await blocked();
    final nowBlocked = !current.contains(peer);
    if (nowBlocked) {
      current.add(peer);
    } else {
      current.remove(peer);
    }
    await _save(_kBlocked, current);
    if (nowBlocked) {
      final followingSet = await following();
      if (followingSet.remove(peer)) {
        await _save(_kFollowing, followingSet);
      }
    }
    return nowBlocked;
  }
}
