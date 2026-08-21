import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/admin_content_store.dart';
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
    final results = await Future.wait([
      repository.getPlaces(),
      repository.getEvents(),
      repository.getClubs(),
      repository.getSports(),
      repository.getServices(),
      AdminContentStore.foodVenues(),
    ]);
    return GuideContext(
      places: results[0] as List<CampusPlace>,
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
    for (final place in places) {
      if (target.contains(place.name.toLowerCase())) return place;
    }
    return null;
  }

  CampusService? matchService(String text) {
    final target = text.toLowerCase();
    for (final service in services) {
      if (target.contains(service.title.toLowerCase())) return service;
    }
    return null;
  }

  CampusClub? matchClub(String text) {
    final target = text.toLowerCase();
    for (final club in clubs) {
      if (target.contains(club.name.toLowerCase())) return club;
    }
    return null;
  }

  CampusSport? matchSport(String text) {
    final target = text.toLowerCase();
    for (final sport in sports) {
      if (target.contains(sport.name.toLowerCase())) return sport;
    }
    return null;
  }
}
