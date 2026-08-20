import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/admin_page.dart';

class AdminPageStore {
  static const _kPages = 'admin.pages.v1';

  static Future<List<AdminPage>> pages() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kPages);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => AdminPage.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<List<AdminPage>> published() async =>
      (await pages()).where((p) => p.status == AdminPageStatus.published).toList();

  static Future<void> save(AdminPage page) async {
    final current = await pages();
    final next = [
      for (final p in current) if (p.id != page.id) p,
      page,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kPages, jsonEncode(next.map((p) => p.toJson()).toList()));
  }

  static Future<void> delete(String id) async {
    final current = await pages();
    final next = current.where((p) => p.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kPages, jsonEncode(next.map((p) => p.toJson()).toList()));
  }
}
