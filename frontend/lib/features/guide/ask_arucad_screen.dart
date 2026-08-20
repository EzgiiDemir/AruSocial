import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/groq_ai_service.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/clubs/club_detail_screen.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';

const _wideBreakpoint = 900.0;

/// The dedicated "Ask ARUCAD" tab — a real multi-turn chat, not the quick
/// single-question map sheet. Every conversation is genuinely persisted
/// on-device (see `AskArucadStore`) and every answer is a real Groq call
/// (with the same honest offline/rule-based fallback the map sheet uses),
/// with the full thread sent as context each time — a real ChatGPT-style
/// history, not a rebuilt-from-scratch answer per question.
class AskArucadScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  const AskArucadScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  @override
  State<AskArucadScreen> createState() => _AskArucadScreenState();
}

class _AskArucadScreenState extends State<AskArucadScreen> {
  final _groq = GroqAiService();
  final _input = TextEditingController();
  final _scroll = ScrollController();
  GuideContext _ctx = GuideContext.empty;
  List<AskArucadConversation> _conversations = [];
  AskArucadConversation? _active;
  bool _loadingContext = true;
  bool _sending = false;
  CampusPlace? _matchedPlace;
  CampusService? _matchedService;
  CampusClub? _matchedClub;
  CampusSport? _matchedSport;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      GuideContext.load(widget.repository),
      AskArucadStore.all(),
    ]);
    if (!mounted) return;
    final conversations = results[1] as List<AskArucadConversation>;
    setState(() {
      _ctx = results[0] as GuideContext;
      _conversations = conversations;
      _active = conversations.isNotEmpty ? conversations.first : _draftConversation();
      _loadingContext = false;
    });
  }

  AskArucadConversation _draftConversation() => AskArucadConversation(
        id: 'c${DateTime.now().microsecondsSinceEpoch}',
        title: 'Yeni sohbet',
        messages: [],
        updatedAt: DateTime.now(),
      );

  void _newChat() {
    setState(() {
      _active = _draftConversation();
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
  }

  void _openConversation(AskArucadConversation conversation) {
    setState(() {
      _active = conversation;
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
  }

  Future<void> _deleteConversation(String id) async {
    await AskArucadStore.delete(id);
    if (!mounted) return;
    setState(() {
      _conversations.removeWhere((c) => c.id == id);
      if (_active?.id == id) {
        _active = _conversations.isNotEmpty ? _conversations.first : _draftConversation();
      }
    });
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      _scroll.animateTo(_scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
    });
  }

  Future<void> _send(String text) async {
    final value = text.trim();
    if (value.isEmpty || _sending) return;
    final conversation = _active ?? _draftConversation();
    setState(() {
      _active = conversation;
      conversation.messages.add(AskArucadMessage(fromUser: true, text: value, at: DateTime.now()));
      _input.clear();
      _sending = true;
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
    _scrollToEnd();

    String answer;
    // Same reasoning as GuideSheet: with a real backend, route through
    // CampusRepository.askGuide() → the backend's /ai/query proxy, so the
    // Groq key stays server-side instead of shipping inside the app (see
    // docs/GERCEK_PROJEYE_GECIS.md §4/§18).
    if (widget.repository is RestCampusRepository) {
      answer = await widget.repository.askGuide(value);
    } else {
      try {
        answer = await _groq.askConversation(
          [for (final m in conversation.messages) (fromUser: m.fromUser, text: m.text)],
          places: _ctx.places,
          events: _ctx.events,
          clubs: _ctx.clubs,
          sports: _ctx.sports,
          services: _ctx.services,
          foodVenues: _ctx.foodVenues,
        );
      } catch (_) {
        // Groq unreachable — same honest fallback the quick map sheet uses,
        // just without multi-turn context (the rule-based engine only ever
        // looks at the latest question).
        answer = await widget.repository.askGuide(value);
      }
    }
    if (!mounted) return;

    final place = _ctx.matchPlace(value) ?? _ctx.matchPlace(answer);
    final service = place == null ? (_ctx.matchService(value) ?? _ctx.matchService(answer)) : null;
    final club = (place == null && service == null)
        ? (_ctx.matchClub(value) ?? _ctx.matchClub(answer))
        : null;
    final sport = (place == null && service == null && club == null)
        ? (_ctx.matchSport(value) ?? _ctx.matchSport(answer))
        : null;

    conversation.messages
        .add(AskArucadMessage(fromUser: false, text: answer, at: DateTime.now()));
    conversation.updatedAt = DateTime.now();
    if (conversation.messages.where((m) => m.fromUser).length == 1) {
      conversation.title = value.length > 42 ? '${value.substring(0, 42)}…' : value;
    }
    await AskArucadStore.save(conversation);
    if (!mounted) return;
    setState(() {
      _sending = false;
      _matchedPlace = place;
      _matchedService = service;
      _matchedClub = club;
      _matchedSport = sport;
      _conversations.removeWhere((c) => c.id == conversation.id);
      _conversations.insert(0, conversation);
    });
    _scrollToEnd();
  }

  void _openClub(CampusClub club) {
    Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => ClubDetailScreen(club: club, events: _ctx.events)));
  }

  Future<void> _contactSport(CampusSport sport) async {
    widget.analyticsTracker.track('service_contact', {'sport': sport.id});
    await launchUrl(Uri(
      scheme: 'mailto',
      path: sport.contact ?? 'destek@arucad.edu.tr',
      query: 'subject=${Uri.encodeComponent('${sport.name} - Katılmak istiyorum')}',
    ));
  }

  void _startNavigation(CampusPlace place) {
    widget.analyticsTracker.track('route_started', {'place': place.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: place.name, destination: GeoPoint(place.lat, place.lng))));
  }

  void _openServiceDetails(CampusService service) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => ServiceDetailScreen(service: service)));
  }

  Future<void> _contactService(CampusService service) async {
    widget.analyticsTracker.track('service_contact', {'service': service.id});
    await launchUrl(Uri(
        scheme: 'mailto',
        path: service.contact,
        query: 'subject=${Uri.encodeComponent(service.title)}'));
  }

  void _openPlaceDetails(CampusPlace place) {
    showPlaceInfoSheet(
      context,
      poi: Poi(name: place.name, category: place.category, lat: place.lat, lng: place.lng),
      place: place,
      events: _ctx.eventsAt(place.name),
      onNavigate: () => _startNavigation(place),
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
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= _wideBreakpoint;
    final sidebar = _Sidebar(
      conversations: _conversations,
      activeId: _active?.id,
      onNewChat: _newChat,
      onSelect: _openConversation,
      onDelete: _deleteConversation,
    );
    final chat = _ChatArea(
      conversation: _active,
      loading: _loadingContext,
      sending: _sending,
      scrollController: _scroll,
      inputController: _input,
      onSend: _send,
      showMenuButton: !wide,
      matchedPlace: _matchedPlace,
      matchedService: _matchedService,
      matchedClub: _matchedClub,
      matchedSport: _matchedSport,
      onNavigate: _matchedPlace == null ? null : () => _startNavigation(_matchedPlace!),
      onOpenPlace: _matchedPlace == null ? null : () => _openPlaceDetails(_matchedPlace!),
      onContactService: _matchedService == null ? null : () => _contactService(_matchedService!),
      onOpenService: _matchedService == null ? null : () => _openServiceDetails(_matchedService!),
      onOpenClub: _matchedClub == null ? null : () => _openClub(_matchedClub!),
      onContactSport: _matchedSport == null ? null : () => _contactSport(_matchedSport!),
    );

    return Scaffold(
      drawer: wide ? null : Drawer(child: sidebar),
      body: wide
          ? Row(children: [
              SizedBox(width: 280, child: sidebar),
              const VerticalDivider(width: 1),
              Expanded(child: chat),
            ])
          : chat,
    );
  }
}

class _Sidebar extends StatelessWidget {
  final List<AskArucadConversation> conversations;
  final String? activeId;
  final VoidCallback onNewChat;
  final ValueChanged<AskArucadConversation> onSelect;
  final ValueChanged<String> onDelete;

  const _Sidebar({
    required this.conversations,
    required this.activeId,
    required this.onNewChat,
    required this.onSelect,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) => ColoredBox(
        color: ArucadColors.navy,
        child: SafeArea(
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
              child: Row(children: [
                const CircleAvatar(
                    radius: 16, backgroundImage: AssetImage('assets/images/galatea.png')),
                const SizedBox(width: 10),
                const Expanded(
                  child: Text('Ask ARUCAD',
                      style: TextStyle(
                          color: Colors.white, fontWeight: FontWeight.w900, fontSize: 15)),
                ),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              child: SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: onNewChat,
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white24),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Yeni Sohbet'),
                ),
              ),
            ),
            const SizedBox(height: 6),
            Expanded(
              child: conversations.isEmpty
                  ? const Padding(
                      padding: EdgeInsets.all(20),
                      child: Text('Henüz sohbet yok — bir soru sorarak başla.',
                          style: TextStyle(color: Colors.white38, fontSize: 12.5)),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      itemCount: conversations.length,
                      itemBuilder: (context, i) {
                        final c = conversations[i];
                        final selected = c.id == activeId;
                        return Material(
                          color: selected ? Colors.white.withValues(alpha: .08) : Colors.transparent,
                          child: ListTile(
                            dense: true,
                            leading: const Icon(Icons.chat_bubble_outline,
                                color: Colors.white70, size: 18),
                            title: Text(c.title,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: Colors.white, fontSize: 13)),
                            trailing: IconButton(
                              iconSize: 16,
                              icon: const Icon(Icons.close, color: Colors.white38),
                              onPressed: () => onDelete(c.id),
                            ),
                            onTap: () {
                              onSelect(c);
                              if (Scaffold.of(context).isDrawerOpen) {
                                Navigator.of(context).pop();
                              }
                            },
                          ),
                        );
                      },
                    ),
            ),
          ]),
        ),
      );
}

class _ChatArea extends StatelessWidget {
  final AskArucadConversation? conversation;
  final bool loading;
  final bool sending;
  final ScrollController scrollController;
  final TextEditingController inputController;
  final ValueChanged<String> onSend;
  final bool showMenuButton;
  final CampusPlace? matchedPlace;
  final CampusService? matchedService;
  final CampusClub? matchedClub;
  final CampusSport? matchedSport;
  final VoidCallback? onNavigate;
  final VoidCallback? onOpenPlace;
  final VoidCallback? onContactService;
  final VoidCallback? onOpenService;
  final VoidCallback? onOpenClub;
  final VoidCallback? onContactSport;

  const _ChatArea({
    required this.conversation,
    required this.loading,
    required this.sending,
    required this.scrollController,
    required this.inputController,
    required this.onSend,
    required this.showMenuButton,
    this.matchedPlace,
    this.matchedService,
    this.matchedClub,
    this.matchedSport,
    this.onNavigate,
    this.onOpenPlace,
    this.onContactService,
    this.onOpenService,
    this.onOpenClub,
    this.onContactSport,
  });

  @override
  Widget build(BuildContext context) {
    final messages = conversation?.messages ?? const [];
    return Column(children: [
      SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 16, 10),
          child: Row(children: [
            if (showMenuButton)
              Builder(
                builder: (context) => IconButton(
                  icon: const Icon(Icons.menu),
                  onPressed: () => Scaffold.of(context).openDrawer(),
                ),
              ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(conversation?.title ?? 'Ask ARUCAD',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
            ),
          ]),
        ),
      ),
      const Divider(height: 1),
      Expanded(
        child: messages.isEmpty
            ? _EmptyState(loading: loading, onSuggestion: onSend)
            : ListView.builder(
                controller: scrollController,
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                itemCount: messages.length + (sending ? 1 : 0),
                itemBuilder: (context, i) {
                  if (i >= messages.length) {
                    return const _TypingBubble();
                  }
                  final m = messages[i];
                  final isLastAssistant = !m.fromUser && i == messages.length - 1;
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Column(
                      crossAxisAlignment:
                          m.fromUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
                      children: [
                        _MessageBubble(message: m),
                        if (isLastAssistant)
                          _MatchedActions(
                            matchedPlace: matchedPlace,
                            matchedService: matchedService,
                            matchedClub: matchedClub,
                            matchedSport: matchedSport,
                            onNavigate: onNavigate,
                            onOpenPlace: onOpenPlace,
                            onContactService: onContactService,
                            onOpenService: onOpenService,
                            onOpenClub: onOpenClub,
                            onContactSport: onContactSport,
                          ),
                      ],
                    ),
                  );
                },
              ),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Row(children: [
            Expanded(
              child: TextField(
                controller: inputController,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.send,
                onSubmitted: onSend,
                decoration: const InputDecoration(
                  hintText: 'Kampüs, ders, servis ya da etkinlik hakkında sor...',
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              onPressed: sending ? null : () => onSend(inputController.text),
              style: FilledButton.styleFrom(
                  shape: const CircleBorder(), padding: const EdgeInsets.all(14)),
              child: const Icon(Icons.arrow_upward, size: 18),
            ),
          ]),
        ),
      ),
    ]);
  }
}

const _suggestedPrompts = [
  'Şu an en yoğun yer neresi?',
  'Bugün hangi etkinlikler var?',
  'Kütüphane nerede, saat kaçta kapanıyor?',
  'Hangi kulüplere katılabilirim?',
];

class _EmptyState extends StatelessWidget {
  final bool loading;
  final ValueChanged<String> onSuggestion;
  const _EmptyState({required this.loading, required this.onSuggestion});

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const CircleAvatar(
                radius: 28, backgroundImage: AssetImage('assets/images/galatea.png')),
            const SizedBox(height: 14),
            const Text('Ask ARUCAD',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
            const SizedBox(height: 6),
            const Text('Kampüsteki her şeyi sorabilirsin — yerler, etkinlikler, dersler, kulüpler, servisler.',
                textAlign: TextAlign.center,
                style: TextStyle(color: ArucadColors.muted, fontSize: 13)),
            const SizedBox(height: 20),
            if (loading)
              const CircularProgressIndicator()
            else
              Wrap(
                spacing: 8,
                runSpacing: 8,
                alignment: WrapAlignment.center,
                children: [
                  for (final prompt in _suggestedPrompts)
                    ActionChip(
                      label: Text(prompt, style: const TextStyle(fontSize: 12.5)),
                      onPressed: () => onSuggestion(prompt),
                    ),
                ],
              ),
          ]),
        ),
      );
}

class _MessageBubble extends StatelessWidget {
  final AskArucadMessage message;
  const _MessageBubble({required this.message});

  @override
  Widget build(BuildContext context) {
    if (message.fromUser) {
      return ConstrainedBox(
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
              color: ArucadColors.primary, borderRadius: BorderRadius.circular(18)),
          child: Text(message.text, style: const TextStyle(color: Colors.white, fontSize: 14.5)),
        ),
      );
    }
    return Row(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
      const CircleAvatar(radius: 14, backgroundImage: AssetImage('assets/images/galatea.png')),
      const SizedBox(width: 8),
      Flexible(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
                color: ArucadColors.mist, borderRadius: BorderRadius.circular(18)),
            child: Text(message.text,
                style: const TextStyle(fontSize: 14.5, color: ArucadColors.ink)),
          ),
        ),
      ),
    ]);
  }
}

class _TypingBubble extends StatelessWidget {
  const _TypingBubble();

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const CircleAvatar(radius: 14, backgroundImage: AssetImage('assets/images/galatea.png')),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            decoration: BoxDecoration(
                color: ArucadColors.mist, borderRadius: BorderRadius.circular(18)),
            child: const SizedBox(
                width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
          ),
        ]),
      );
}

class _MatchedActions extends StatelessWidget {
  final CampusPlace? matchedPlace;
  final CampusService? matchedService;
  final CampusClub? matchedClub;
  final CampusSport? matchedSport;
  final VoidCallback? onNavigate;
  final VoidCallback? onOpenPlace;
  final VoidCallback? onContactService;
  final VoidCallback? onOpenService;
  final VoidCallback? onOpenClub;
  final VoidCallback? onContactSport;

  const _MatchedActions({
    this.matchedPlace,
    this.matchedService,
    this.matchedClub,
    this.matchedSport,
    this.onNavigate,
    this.onOpenPlace,
    this.onContactService,
    this.onOpenService,
    this.onOpenClub,
    this.onContactSport,
  });

  @override
  Widget build(BuildContext context) {
    if (matchedPlace == null && matchedService == null && matchedClub == null && matchedSport == null) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.only(top: 8, left: 36),
      child: Wrap(spacing: 8, runSpacing: 8, children: [
        if (matchedPlace != null) ...[
          FilledButton.icon(
            onPressed: onNavigate,
            icon: const Icon(Icons.directions_walk, size: 16),
            label: const Text('Yol Tarifi'),
          ),
          OutlinedButton.icon(
            onPressed: onOpenPlace,
            icon: const Icon(Icons.info_outline, size: 16),
            label: const Text('Aç'),
          ),
        ],
        if (matchedService != null) ...[
          FilledButton.icon(
            onPressed: onContactService,
            icon: const Icon(Icons.mail_outline, size: 16),
            label: const Text('İletişime Geç'),
          ),
          OutlinedButton.icon(
            onPressed: onOpenService,
            icon: const Icon(Icons.info_outline, size: 16),
            label: const Text('Detaylar'),
          ),
        ],
        if (matchedClub != null)
          OutlinedButton.icon(
            onPressed: onOpenClub,
            icon: const Icon(Icons.groups_outlined, size: 16),
            label: const Text('Kulübü Aç'),
          ),
        if (matchedSport != null)
          FilledButton.icon(
            onPressed: onContactSport,
            icon: const Icon(Icons.mail_outline, size: 16),
            label: const Text('Katıl / İletişime Geç'),
          ),
      ]),
    );
  }
}
