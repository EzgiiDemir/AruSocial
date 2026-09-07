import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';

/// Every real domain Ask ARUCAD should be able to answer about — loaded once
/// and shared by both the quick map sheet ([GuideSheet]) and the full chat
/// tab ([AskArucadScreen]) so they always see the same live, admin-editable
/// data instead of two copies of this loading logic drifting apart.
class GuideContext {
  final List<CampusPlace> places;
  final List<CampusEvent> events;
  final List<CampusClub> clubs;
  final List<CampusSport> sports;
  final List<CampusService> services;
  final List<CampusFoodVenue> foodVenues;

  const GuideContext({
    required this.places,
    required this.events,
    required this.clubs,
    required this.sports,
    required this.services,
    required this.foodVenues,
  });

  static const empty = GuideContext(
    places: [],
    events: [],
    clubs: [],
    sports: [],
    services: [],
    foodVenues: [],
  );

  static Future<GuideContext> load(CampusRepository repository) async {
    Future<T> one<T>(Future<T> future, T fallback) async {
      try {
        return await future;
      } catch (_) {
        return fallback;
      }
    }

    final results = await Future.wait([
      one(repository.getPlaces(), const <CampusPlace>[]),
      one(repository.getEvents(), const <CampusEvent>[]),
      one(repository.getClubs(), const <CampusClub>[]),
      one(repository.getSports(), const <CampusSport>[]),
      one(repository.getServices(), const <CampusService>[]),
      one(repository.getFoodVenues(), const <CampusFoodVenue>[]),
    ]);
    return GuideContext(
      places: campusMapPlaces(results[0] as List<CampusPlace>),
      events: results[1] as List<CampusEvent>,
      clubs: results[2] as List<CampusClub>,
      sports: results[3] as List<CampusSport>,
      services: results[4] as List<CampusService>,
      foodVenues: results[5] as List<CampusFoodVenue>,
    );
  }

  List<CampusEvent> eventsAt(String placeName) =>
      events.where((e) => e.placeName.toLowerCase() == placeName.toLowerCase()).toList();

  CampusPlace? matchPlace(String text) {
    final target = text.toLowerCase();
    final aliasKeys = _placeAliases.keys.toList()
      ..sort((a, b) => b.length.compareTo(a.length));
    for (final alias in aliasKeys) {
      if (_mentions(target, alias, allowSuffix: true)) {
        final want = _placeAliases[alias]!.toLowerCase();
        for (final place in places) {
          if (place.name.toLowerCase() == want) return place;
        }
      }
    }
    CampusPlace? best;
    var bestLen = 0;
    for (final place in places) {
      if (_mentions(target, place.name, allowSuffix: place.name.length >= 6) &&
          place.name.length > bestLen) {
        best = place;
        bestLen = place.name.length;
      }
    }
    return best;
  }

  CampusService? matchService(String text) {
    final target = text.toLowerCase();
    for (final service in services) {
      if (_mentions(target, service.title, allowSuffix: true)) return service;
    }
    return null;
  }

  CampusClub? matchClub(String text) {
    final target = text.toLowerCase();
    for (final club in clubs) {
      if (_mentions(target, club.name, allowSuffix: true)) return club;
    }
    return null;
  }

  CampusSport? matchSport(String text) {
    final target = text.toLowerCase();
    for (final sport in sports) {
      if (_mentions(target, sport.name, allowSuffix: true)) return sport;
    }
    return null;
  }

  /// Grounded campus answer from the same catalog the UI already loaded —
  /// used when Groq is unset or the API call fails, so Ask ARUCAD still replies.
  String localAnswer(String prompt) {
    final q = prompt.trim();
    if (q.isEmpty) {
      return 'Bir yer, etkinlik, servis veya birim adı yaz, kampüs kayıtlarından bakayım.';
    }
    final lower = q.toLowerCase();
    final place = matchPlace(q);
    if (place != null) {
      final bits = [place.category, place.street, place.distance]
          .where((s) => s.trim().isNotEmpty)
          .join(' · ');
      final desc = place.description.trim();
      return '${place.name} kampüste.'
          '${desc.isNotEmpty ? ' $desc' : ''}'
          '${bits.isNotEmpty ? ' $bits' : ''}';
    }
    final service = matchService(q);
    if (service != null) {
      final where = [service.building, service.floor, service.room]
          .whereType<String>()
          .where((s) => s.trim().isNotEmpty)
          .join(', ');
      return '${service.title}: ${service.description}'
          '${where.isNotEmpty ? ' Konum: $where.' : ''}'
          '${service.contact.trim().isNotEmpty ? ' İletişim: ${service.contact}' : ''}';
    }
    final club = matchClub(q);
    if (club != null) {
      return '${club.name} (${club.category}): ${club.description}';
    }
    final sport = matchSport(q);
    if (sport != null) {
      return '${sport.name}: ${sport.facility} kullanılıyor. Keşfet içinden ön başvuru yapabilirsin.';
    }
    for (final event in events) {
      final hay = '${event.title} ${event.placeName}'.toLowerCase();
      if ((event.title.length >= 4 && lower.contains(event.title.toLowerCase())) ||
          (event.placeName.length >= 4 &&
              lower.contains(event.placeName.toLowerCase()) &&
              lower.contains('etkinlik'))) {
        return '${event.title} · ${event.placeName}, saat ${event.time}'
            '${event.attendees > 0 ? ' · ${event.attendees} katılımcı' : ''}.';
      }
      if (hay.contains(lower) && lower.length >= 4) {
        return '${event.title} · ${event.placeName}, saat ${event.time}.';
      }
    }
    if (lower.contains('etkinlik') && events.isNotEmpty) {
      final listed = events.take(3).map((e) => '${e.title} · ${e.placeName}').join('; ');
      return 'Yakın etkinlikler: $listed.';
    }
    if (lower.contains('yemek') ||
        lower.contains('menü') ||
        lower.contains('menu') ||
        lower.contains('garden')) {
      if (foodVenues.isNotEmpty) {
        final venue = foodVenues.first;
        final menu = venue.menuForDay(DateTime.now());
        final items = (menu?.items.isNotEmpty ?? false)
            ? menu!.items.join(', ')
            : 'bugünün menüsü henüz girilmedi';
        return '${venue.name}: $items';
      }
    }
    if (lower.contains('servis') ||
        lower.contains('otobüs') ||
        lower.contains('shuttle')) {
      return 'Kampüs servis saatleri Keşfet ve haritadaki Servis bölümünde. Lefkoşa, Alsancak, Çatalköy ve atölye hatları var.';
    }
    if (lower.contains('merhaba') ||
        lower.contains('selam') ||
        lower.contains('hello')) {
      return 'Merhaba, ben Ask ARUCAD. Yer, etkinlik, kulüp, spor veya birim sorabilirsin.';
    }
    final names = places.take(5).map((p) => p.name).where((n) => n.trim().isNotEmpty).join(', ');
    return 'Bunu kayıtta net eşleştiremedim.'
        '${names.isNotEmpty ? ' Denemek için bir yer adı yaz, örneğin: $names.' : ' Yer veya etkinlik adı ile tekrar sor.'}';
  }
}

/// Spoken / typed names that should land on a catalog building even when
/// the student does not use the Rodin-inspired English label.
const _placeAliases = <String, String>{
  'kütüphane': 'Meditation',
  'kutuphane': 'Meditation',
  'library': 'Meditation',
  'yurt': 'ARUCAD Dormitory',
  'dormitory': 'ARUCAD Dormitory',
  'rektörlük': 'Rodin',
  'rektorluk': 'Rodin',
  'rektör': 'Rodin',
  'öğrenci işleri': 'Titan',
  'ogrenci isleri': 'Titan',
  'bandabuliya': 'Nicosia Bandabuliya Campus',
  'lefkoşa': 'Nicosia Bandabuliya Campus',
  'lefkosa': 'Nicosia Bandabuliya Campus',
  'art space': 'ARUCAD Art Space',
  'iris': 'Iris (Atelier Building)',
  'age of bronze': 'Age of Bronze',
  'marangoz': 'Carpentry Studio',
  'carpentry': 'Carpentry Studio',
  'the garden': 'The Garden',
  'bahçe': 'The Garden',
  'bahce': 'The Garden',
};

bool _mentions(String haystack, String needle, {bool allowSuffix = false}) {
  final n = needle.trim().toLowerCase();
  if (n.length < 3) return false;
  final escaped = RegExp.escape(n);
  final after = allowSuffix
      ? '(?:[^\\p{L}\\p{N}]|\$|\\p{L}+)'
      : '(?:[^\\p{L}\\p{N}]|\$)';
  return RegExp(
    '(?:^|[^\\p{L}\\p{N}])$escaped$after',
    unicode: true,
  ).hasMatch(haystack.toLowerCase());
}
