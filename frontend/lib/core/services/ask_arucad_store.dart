import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../auth/session_store.dart';

/// One page or internal table an answer was built from.
///
/// These come from the BACKEND's own record of what it retrieved
/// (`sources[]` on `POST /ai/query`), never from URLs parsed out of the
/// answer text — a model that invents a link would otherwise have
/// invented a citation to go with it.
class AskArucadSource {
  /// 'web' for a crawled ARUCAD page, 'campus' for an internal table
  /// (events, places, shuttle…) which has no link to open.
  final String type;
  final String title;

  /// Empty for a campus source.
  final String url;
  final String id;
  final int? page;
  final String? authorityLabel;
  final String? updatedAt;

  const AskArucadSource({
    required this.type,
    required this.title,
    required this.url,
    required this.id,
    this.page,
    this.authorityLabel,
    this.updatedAt,
  });

  bool get isWeb => (type == 'web' || type == 'pdf') && url.isNotEmpty;

  Map<String, dynamic> toJson() => {
        'type': type,
        'title': title,
        'url': url,
        'id': id,
        if (page != null) 'page': page,
        if (authorityLabel != null) 'authorityLabel': authorityLabel,
        if (updatedAt != null) 'updatedAt': updatedAt,
      };

  factory AskArucadSource.fromJson(Map<String, dynamic> json) =>
      AskArucadSource(
        type: json['type'] as String? ?? 'web',
        title: json['title'] as String? ?? '',
        url: json['url'] as String? ?? '',
        id: json['id'] as String? ?? '',
        page: (json['page'] as num?)?.toInt(),
        authorityLabel: json['authorityLabel'] as String?,
        updatedAt: json['updatedAt'] as String?,
      );
}

class AskPlaceComponent {
  final String id;
  final String name;
  final String category;
  final double latitude;
  final double longitude;
  final String? building;
  final String? floor;
  final String? room;
  final String? contact;
  final String? hours;
  final String? tourUrl;
  final String? tourTarget;

  const AskPlaceComponent({
    required this.id,
    required this.name,
    required this.category,
    required this.latitude,
    required this.longitude,
    this.building,
    this.floor,
    this.room,
    this.contact,
    this.hours,
    this.tourUrl,
    this.tourTarget,
  });

  factory AskPlaceComponent.fromJson(Map<String, dynamic> json) =>
      AskPlaceComponent(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        category: json['category'] as String? ?? '',
        latitude: (json['latitude'] as num?)?.toDouble() ?? 0,
        longitude: (json['longitude'] as num?)?.toDouble() ?? 0,
        building: json['building'] as String?,
        floor: json['floor'] as String?,
        room: json['room'] as String?,
        contact: json['contact'] as String?,
        hours: json['hours'] as String?,
        tourUrl: json['tourUrl'] as String?,
        tourTarget: json['tourTarget'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'category': category,
        'latitude': latitude,
        'longitude': longitude,
        if (building != null) 'building': building,
        if (floor != null) 'floor': floor,
        if (room != null) 'room': room,
        if (contact != null) 'contact': contact,
        if (hours != null) 'hours': hours,
        if (tourUrl != null) 'tourUrl': tourUrl,
        if (tourTarget != null) 'tourTarget': tourTarget,
      };
}

class AskRouteComponent {
  final String originName;
  final String destinationName;
  final double distanceMeters;
  final double durationSeconds;
  final String travelMode;
  final String provider;

  const AskRouteComponent(
      {required this.originName,
      required this.destinationName,
      required this.distanceMeters,
      required this.durationSeconds,
      required this.travelMode,
      required this.provider});

  factory AskRouteComponent.fromJson(Map<String, dynamic> json) =>
      AskRouteComponent(
        originName: (json['origin'] as Map?)?['name'] as String? ?? '',
        destinationName:
            (json['destination'] as Map?)?['name'] as String? ?? '',
        distanceMeters: (json['distanceMeters'] as num?)?.toDouble() ?? 0,
        durationSeconds: (json['durationSeconds'] as num?)?.toDouble() ?? 0,
        travelMode: json['travelMode'] as String? ?? 'walking',
        provider: json['provider'] as String? ?? '',
      );

  Map<String, dynamic> toJson() => {
        'origin': {'name': originName},
        'destination': {'name': destinationName},
        'distanceMeters': distanceMeters,
        'durationSeconds': durationSeconds,
        'travelMode': travelMode,
        'provider': provider,
      };
}

class AskEventComponent {
  final String id;
  final String title;
  final String? date;
  final String? time;
  final String? placeName;
  final String? category;

  const AskEventComponent(
      {required this.id,
      required this.title,
      this.date,
      this.time,
      this.placeName,
      this.category});

  factory AskEventComponent.fromJson(Map<String, dynamic> json) =>
      AskEventComponent(
        id: json['id'] as String? ?? '',
        title: json['title'] as String? ?? '',
        date: json['date'] as String?,
        time: json['time'] as String?,
        placeName: json['placeName'] as String?,
        category: json['category'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        if (date != null) 'date': date,
        if (time != null) 'time': time,
        if (placeName != null) 'placeName': placeName,
        if (category != null) 'category': category,
      };
}

/// One turn in an Ask ARUCAD conversation.
class AskArucadMessage {
  final bool fromUser;
  final String text;
  final DateTime at;

  /// What the answer was grounded in. Always empty for a user turn, and
  /// for an answer that used no retrieved source (a direct lookup from our
  /// own tables) — which is shown as no citations rather than a plausible
  /// one.
  final List<AskArucadSource> sources;
  final List<AskPlaceComponent> places;
  final AskRouteComponent? route;
  final List<AskEventComponent> events;

  const AskArucadMessage({
    required this.fromUser,
    required this.text,
    required this.at,
    this.sources = const [],
    this.places = const [],
    this.route,
    this.events = const [],
  });

  Map<String, dynamic> toJson() => {
        'fromUser': fromUser,
        'text': text,
        'at': at.toIso8601String(),
        if (sources.isNotEmpty)
          'sources': sources.map((s) => s.toJson()).toList(),
        if (places.isNotEmpty) 'places': places.map((p) => p.toJson()).toList(),
        if (route != null) 'route': route!.toJson(),
        if (events.isNotEmpty) 'events': events.map((e) => e.toJson()).toList(),
      };

  factory AskArucadMessage.fromJson(Map<String, dynamic> json) =>
      AskArucadMessage(
        fromUser: json['fromUser'] as bool? ?? json['role'] == 'user',
        text: (json['text'] ?? json['content'] ?? '') as String,
        at: DateTime.tryParse(json['at'] as String? ?? '') ?? DateTime.now(),
        sources: (json['sources'] as List<dynamic>? ?? const [])
            .map((s) => AskArucadSource.fromJson(s as Map<String, dynamic>))
            .toList(),
        places: (json['places'] as List<dynamic>? ?? const [])
            .map((p) => AskPlaceComponent.fromJson(p as Map<String, dynamic>))
            .toList(),
        route: json['route'] is Map<String, dynamic>
            ? AskRouteComponent.fromJson(json['route'] as Map<String, dynamic>)
            : null,
        events: (json['events'] as List<dynamic>? ?? const [])
            .map((e) => AskEventComponent.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

/// A single saved thread — title is derived from the first question asked.
class AskArucadConversation {
  final String id;
  String title;
  final List<AskArucadMessage> messages;
  DateTime updatedAt;

  AskArucadConversation({
    required this.id,
    required this.title,
    required this.messages,
    required this.updatedAt,
  });

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'messages': messages.map((m) => m.toJson()).toList(),
        'updatedAt': updatedAt.toIso8601String(),
      };

  factory AskArucadConversation.fromJson(Map<String, dynamic> json) =>
      AskArucadConversation(
        id: json['id'] as String,
        title: json['title'] as String,
        messages: (json['messages'] as List<dynamic>? ?? const [])
            .map((m) => AskArucadMessage.fromJson(m as Map<String, dynamic>))
            .toList(),
        updatedAt: DateTime.tryParse(json['updatedAt'] as String? ?? '') ??
            DateTime.now(),
      );

  AskArucadConversation withId(String newId) => AskArucadConversation(
        id: newId,
        title: title,
        messages: messages,
        updatedAt: updatedAt,
      );
}

/// Real, on-device conversation history for the Ask ARUCAD chat tab —
/// same SharedPreferences-backed pattern as every other local store in this
/// app. Genuinely persisted (survives app restarts), genuinely per-device
/// (no cross-device sync, since there's no backend — consistent with every
/// other "local-only, honestly scoped" store in this project).
class AskArucadStore {
  static const _keyPrefix = 'ask_arucad.conversations.v1';

  static Future<String> _key({String? ownerEmail}) async {
    final email =
        (ownerEmail ?? await SessionStore.email())?.trim().toLowerCase();
    if (email == null || email.isEmpty) return '$_keyPrefix.signed-out';
    return '$_keyPrefix.$email';
  }

  static Future<List<AskArucadConversation>> all({String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(await _key(ownerEmail: ownerEmail));
    if (raw == null || raw.isEmpty) return [];
    final list = jsonDecode(raw) as List<dynamic>;
    final conversations = list
        .map((e) => AskArucadConversation.fromJson(e as Map<String, dynamic>))
        .toList();
    conversations.sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
    return conversations;
  }

  static Future<void> save(AskArucadConversation conversation,
      {String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final conversations = await all(ownerEmail: ownerEmail);
    conversations.removeWhere((c) => c.id == conversation.id);
    conversations.add(conversation);
    conversations.sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
    await prefs.setString(await _key(ownerEmail: ownerEmail),
        jsonEncode(conversations.map((c) => c.toJson()).toList()));
  }

  static Future<void> delete(String id, {String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final conversations = await all(ownerEmail: ownerEmail);
    conversations.removeWhere((c) => c.id == id);
    await prefs.setString(await _key(ownerEmail: ownerEmail),
        jsonEncode(conversations.map((c) => c.toJson()).toList()));
  }
}
