import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../config/campus_life_config.dart';

/// The real, editable-at-runtime backing store for Discover's Clubs, Sports
/// and Campus Services in **Mock** mode. Rest mode talks to the Laravel
/// catalog endpoints instead. Food venues used to live only here; Rest
/// mode now reads/writes `GET/POST /food-venues` via `CampusRepository`.
/// Mock mode still persists food here so `USE_REST_API=false` stays offline.
class AdminContentStore {
  static const _kClubs = 'admin.content.clubs.v1';
  static const _kSports = 'admin.content.sports.v1';
  // Bumped to v2 when CampusService gained `topics`/`hours` — forces a
  // fresh reseed from the updated consts instead of reading back
  // devices' old, pre-topics persisted JSON.
  static const _kServices = 'admin.content.services.v2';

  static Future<List<CampusClub>> clubs() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kClubs);
    if (raw == null) {
      await prefs.setString(_kClubs, jsonEncode(campusClubs.map((c) => c.toJson()).toList()));
      return campusClubs;
    }
    return (jsonDecode(raw) as List)
        .map((e) => CampusClub.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveClub(CampusClub club) async {
    final current = await clubs();
    final next = [
      for (final c in current) if (c.id != club.id) c,
      club,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kClubs, jsonEncode(next.map((c) => c.toJson()).toList()));
  }

  static Future<void> deleteClub(String id) async {
    final current = await clubs();
    final next = current.where((c) => c.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kClubs, jsonEncode(next.map((c) => c.toJson()).toList()));
  }

  static Future<List<CampusSport>> sports() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kSports);
    if (raw == null) {
      await prefs.setString(_kSports, jsonEncode(campusSports.map((s) => s.toJson()).toList()));
      return campusSports;
    }
    return (jsonDecode(raw) as List)
        .map((e) => CampusSport.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveSport(CampusSport sport) async {
    final current = await sports();
    final next = [
      for (final s in current) if (s.id != sport.id) s,
      sport,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kSports, jsonEncode(next.map((s) => s.toJson()).toList()));
  }

  static Future<void> deleteSport(String id) async {
    final current = await sports();
    final next = current.where((s) => s.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kSports, jsonEncode(next.map((s) => s.toJson()).toList()));
  }

  static Future<List<CampusService>> services() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kServices);
    if (raw == null) {
      await prefs.setString(_kServices, jsonEncode(campusServices.map((s) => s.toJson()).toList()));
      return campusServices;
    }
    return (jsonDecode(raw) as List)
        .map((e) => CampusService.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveService(CampusService service) async {
    final current = await services();
    final next = [
      for (final s in current) if (s.id != service.id) s,
      service,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kServices, jsonEncode(next.map((s) => s.toJson()).toList()));
  }

  static Future<void> deleteService(String id) async {
    final current = await services();
    final next = current.where((s) => s.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kServices, jsonEncode(next.map((s) => s.toJson()).toList()));
  }

  static const _kFoodVenues = 'admin.content.foodVenues.v1';

  static Future<List<CampusFoodVenue>> foodVenues() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kFoodVenues);
    if (raw == null) {
      await prefs.setString(
          _kFoodVenues, jsonEncode(campusFoodVenues.map((f) => f.toJson()).toList()));
      return campusFoodVenues;
    }
    return (jsonDecode(raw) as List)
        .map((e) => CampusFoodVenue.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveFoodVenue(CampusFoodVenue venue) async {
    final current = await foodVenues();
    final next = [
      for (final f in current) if (f.id != venue.id) f,
      venue,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kFoodVenues, jsonEncode(next.map((f) => f.toJson()).toList()));
  }

  static Future<void> deleteFoodVenue(String id) async {
    final current = await foodVenues();
    final next = current.where((f) => f.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kFoodVenues, jsonEncode(next.map((f) => f.toJson()).toList()));
  }
}
