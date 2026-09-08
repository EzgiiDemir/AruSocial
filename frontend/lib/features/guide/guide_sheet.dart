import 'package:flutter/material.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/groq_ai_service.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/clubs/club_detail_screen.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/apply_bottom_sheet.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/sport_application_screen.dart';

/// "Ask ARUCAD" — the campus assistant. Deliberately text-first: no
/// mic/voice input and no text-to-speech playback. A voice interface adds
/// real complexity (locale-specific STT/TTS quality, background noise,
/// accessibility trade-offs) without adding real capability here, so the
/// assistant's job is to answer, then hand the student off to the right
/// place — Find, Open, Navigate — rather than talk at them.
class GuideSheet extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final String language;

  /// Hands off to the full multi-turn "Ask ARUCAD" tab (real persisted
  /// conversation history) — this sheet stays a fast single-question
  /// surface on purpose, but a student who wants to keep talking shouldn't
  /// have to retype their question there.
  final VoidCallback? onOpenFullChat;

  const GuideSheet({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.language = 'TR',
    this.onOpenFullChat,
  });

  @override
  State<GuideSheet> createState() => _GuideSheetState();
}

class _GuideSheetState extends State<GuideSheet> {
  final controller = TextEditingController();
  final _groq = GroqAiService();
  GuideContext _ctx = GuideContext.empty;
  String answer = '';
  CampusPlace? matchedPlace;
  CampusService? matchedService;
  CampusClub? matchedClub;
  CampusSport? matchedSport;
  bool loading = false;

  @override
  void initState() {
    super.initState();
    GuideContext.load(widget.repository).then((ctx) {
      if (!mounted) return;
      setState(() => _ctx = ctx);
    }, onError: (_) {});
  }

  Poi _poiFromPlace(CampusPlace place) => poiFromPlace(place);

  Future<String> _getAnswer(String value) async {
    // REST: CampusRepository.askGuide → /ai/query (server-side key, catalog fallback).
    // Mock: optional dart-define Groq, else catalog / repository answers.
    try {
      if (widget.repository is RestCampusRepository) {
        final answer = await widget.repository.askGuide(value);
        if (answer.trim().isEmpty) return _ctx.localAnswer(value);
        return answer;
      }
      try {
        return await _groq.ask(
          value,
          places: _ctx.places,
          events: _ctx.events,
          clubs: _ctx.clubs,
          sports: _ctx.sports,
          services: _ctx.services,
          foodVenues: _ctx.foodVenues,
        );
      } catch (_) {
        try {
          final answer = await widget.repository.askGuide(value);
          if (answer.trim().isNotEmpty) return answer;
        } catch (_) {}
        return _ctx.localAnswer(value);
      }
    } on ApiClientException catch (_) {
      return _ctx.localAnswer(value);
    } catch (_) {
      return _ctx.localAnswer(value);
    }
  }

  Future<void> ask(String value) async {
    if (value.trim().isEmpty) return;
    setState(() {
      loading = true;
      controller.text = value;
      matchedPlace = null;
      matchedService = null;
      matchedClub = null;
      matchedSport = null;
    });
    final result = await _getAnswer(value);
    if (!mounted) return;
    final place = _ctx.matchPlace(value) ?? _ctx.matchPlace(result);
    final service = place == null ? (_ctx.matchService(value) ?? _ctx.matchService(result)) : null;
    final club = (place == null && service == null)
        ? (_ctx.matchClub(value) ?? _ctx.matchClub(result))
        : null;
    final sport = (place == null && service == null && club == null)
        ? (_ctx.matchSport(value) ?? _ctx.matchSport(result))
        : null;
    setState(() {
      answer = result;
      loading = false;
      matchedPlace = place;
      matchedService = service;
      matchedClub = club;
      matchedSport = sport;
    });
  }

  void _openClub(CampusClub club) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) =>
            ClubDetailScreen(club: club, repository: widget.repository, events: _ctx.events)));
  }

  Future<void> _contactSport(CampusSport sport) async {
    widget.analyticsTracker.track('service_contact', {'sport': sport.id});
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => SportApplicationScreen(
            sport: sport, repository: widget.repository)));
  }

  void _startNavigation(CampusPlace place,
      [TravelMode mode = TravelMode.walking]) {
    widget.analyticsTracker
        .track('route_started', {'place': place.name, 'mode': mode.name});
    Navigator.of(context).pop();
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: place.name,
            destination: GeoPoint(place.lat, place.lng),
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker,
            initialMode: mode)));
  }

  void _openServiceDetails(CampusService service) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ServiceDetailScreen(
            service: service, repository: widget.repository)));
  }

  Future<void> _contactService(CampusService service) async {
    widget.analyticsTracker.track('service_contact', {'service': service.id});
    await showApplyBottomSheet(
      context,
      repository: widget.repository,
      targetType: 'service',
      targetId: service.id,
      targetLabel: service.title,
    );
  }

  void _openDetails(CampusPlace place) {
    showPlaceInfoSheet(
      context,
      poi: _poiFromPlace(place),
      place: place,
      events: _ctx.eventsAt(place.name),
      repository: widget.repository,
      onNavigate: (mode) => _startNavigation(place, mode),
      onDetails: () {
        Navigator.of(context).pop();
        Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => PlaceDetailScreen(
                place: place,
                repository: widget.repository,
                mapProvider: widget.mapProvider,
                analyticsTracker: widget.analyticsTracker)));
      },
    );
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(
          20, 18, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      child: SafeArea(
        top: false,
        child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                const CircleAvatar(
                    radius: 20,
                    backgroundImage:
                        AssetImage('assets/images/galatea.png')),
                const SizedBox(width: 12),
                Expanded(
                    child: Text('Ask ARUCAD',
                        style: Theme.of(context)
                            .textTheme
                            .titleLarge
                            ?.copyWith(fontWeight: FontWeight.w900))),
                if (widget.onOpenFullChat != null)
                  IconButton(
                    tooltip: 'Tam sohbete git',
                    onPressed: widget.onOpenFullChat,
                    icon: const Icon(Icons.open_in_full, size: 19),
                  ),
                IconButton(
                    onPressed: () => Navigator.pop(context),
                    icon: const Icon(Icons.close)),
              ]),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(
                    child: TextField(
                        controller: controller,
                        autofocus: true,
                        onSubmitted: (v) => ask(v),
                        decoration: const InputDecoration(
                            hintText: 'Kampüs, servis veya etkinlik hakkında sor...'))),
                const SizedBox(width: 8),
                FilledButton(
                    onPressed: () => ask(controller.text),
                    child: const Icon(Icons.send)),
              ]),
              if (!loading && answer.isEmpty) ...[
                const SizedBox(height: 12),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final prompt in _quickPrompts)
                    ActionChip(
                      label: Text(prompt, style: const TextStyle(fontSize: 12)),
                      onPressed: () => ask(prompt),
                    ),
                ]),
              ],
              if (loading)
                const Padding(
                    padding: EdgeInsets.only(top: 14),
                    child: LinearProgressIndicator()),
              if (!loading && answer.isNotEmpty) ...[
                const SizedBox(height: 14),
                Card(
                    color: ArucadColors.paper,
                    child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(answer),
                              if (matchedPlace != null) ...[
                                const SizedBox(height: 14),
                                Row(children: [
                                  Expanded(
                                    child: FilledButton.icon(
                                      onPressed: () =>
                                          _startNavigation(matchedPlace!),
                                      icon: const Icon(Icons.directions_walk),
                                      label: const Text('Yol Tarifi'),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: OutlinedButton.icon(
                                      onPressed: () =>
                                          _openDetails(matchedPlace!),
                                      icon: const Icon(Icons.info_outline),
                                      label: const Text('Aç'),
                                    ),
                                  ),
                                ]),
                              ],
                              if (matchedService != null) ...[
                                const SizedBox(height: 14),
                                if ([matchedService!.building, matchedService!.floor, matchedService!.room]
                                    .whereType<String>()
                                    .isNotEmpty)
                                  Padding(
                                    padding: const EdgeInsets.only(bottom: 10),
                                    child: Text(
                                      'Konum: ${[matchedService!.building, matchedService!.floor, matchedService!.room].whereType<String>().join(', ')}',
                                      style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
                                    ),
                                  ),
                                Row(children: [
                                  Expanded(
                                    child: FilledButton.icon(
                                      onPressed: () => _contactService(matchedService!),
                                      icon: const Icon(Icons.send_outlined),
                                      label: const Text('Ön Başvuru'),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: OutlinedButton.icon(
                                      onPressed: () => _openServiceDetails(matchedService!),
                                      icon: const Icon(Icons.info_outline),
                                      label: const Text('Detaylar'),
                                    ),
                                  ),
                                ]),
                              ],
                              if (matchedClub != null) ...[
                                const SizedBox(height: 14),
                                SizedBox(
                                  width: double.infinity,
                                  child: OutlinedButton.icon(
                                    onPressed: () => _openClub(matchedClub!),
                                    icon: const Icon(Icons.groups_outlined),
                                    label: const Text('Kulübü Aç'),
                                  ),
                                ),
                              ],
                              if (matchedSport != null) ...[
                                const SizedBox(height: 14),
                                SizedBox(
                                  width: double.infinity,
                                  child: FilledButton.icon(
                                    onPressed: () => _contactSport(matchedSport!),
                                    icon: const Icon(Icons.mail_outline),
                                    label: const Text('Ön Başvuru / Katıl'),
                                  ),
                                ),
                              ],
                            ]))),
                if (widget.onOpenFullChat != null) ...[
                  const SizedBox(height: 10),
                  Center(
                    child: TextButton.icon(
                      onPressed: widget.onOpenFullChat,
                      icon: const Icon(Icons.forum_outlined, size: 16),
                      label: const Text('Sohbete devam et'),
                    ),
                  ),
                ],
              ],
            ]),
      ),
    );
  }
}

const _quickPrompts = [
  'Şu an en yoğun yer neresi?',
  'Bugün hangi etkinlikler var?',
  'Kütüphane saatleri nedir?',
];
