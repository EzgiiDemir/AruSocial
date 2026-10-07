import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:collection/collection.dart';

import 'package:arucad_campus_prototype/features/widgets/linkified_text.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/groq_ai_service.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/clubs/club_detail_screen.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/apply_bottom_sheet.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/sport_application_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

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
  final _scaffoldKey = GlobalKey<ScaffoldState>();
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
    try {
      final ctx = await GuideContext.load(widget.repository);
      List<AskArucadConversation> conversations = [];
      try {
        conversations = await widget.repository.getAskConversations();
      } catch (_) {
        conversations = await AskArucadStore.all();
      }
      if (conversations.isEmpty) {
        conversations = await AskArucadStore.all();
      }
      // The list carries no messages, so the thread we are about to show has
      // to be fetched in full first — otherwise the tab opens on an empty
      // chat and the history looks lost.
      AskArucadConversation? active;
      if (conversations.isNotEmpty) {
        active = await _withMessages(conversations.first);
        conversations[0] = active;
      }
      if (!mounted) return;
      setState(() {
        _ctx = ctx;
        _conversations = conversations;
        _active = active ?? _draftConversation();
        _loadingContext = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _ctx = GuideContext.empty;
        _active = _draftConversation();
        _loadingContext = false;
      });
    }
  }

  AskArucadConversation _draftConversation() => AskArucadConversation(
        id: 'c${DateTime.now().microsecondsSinceEpoch}',
        title: AppLocale.of(context).t('ask_new_chat'),
        messages: [],
        updatedAt: DateTime.now(),
      );

  void _newChat() {
    if (_sending) return;
    setState(() {
      _active = _draftConversation();
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
  }

  /// Fills in a conversation's messages.
  ///
  /// The list endpoint returns summaries only, so a thread picked from it has
  /// an empty body until it is fetched by id. When that fetch fails — offline,
  /// or the server lost it — the locally stored copy is used instead: it is
  /// written after every turn under the same id and keeps the whole thread, so
  /// history stays on screen rather than blanking out.
  Future<AskArucadConversation> _withMessages(
      AskArucadConversation conversation) async {
    if (conversation.messages.isNotEmpty) return conversation;

    if (conversation.id.startsWith('ask-')) {
      try {
        final full =
            await widget.repository.getAskConversation(conversation.id);
        if (full != null && full.messages.isNotEmpty) return full;
      } catch (_) {
        // Fall through to the stored copy.
      }
    }

    for (final stored in await AskArucadStore.all()) {
      if (stored.id == conversation.id && stored.messages.isNotEmpty) {
        return stored;
      }
    }

    return conversation;
  }

  void _openConversation(AskArucadConversation conversation) {
    if (_sending) return;
    setState(() {
      _active = conversation;
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
    if (conversation.messages.isEmpty) {
      _withMessages(conversation).then((full) {
        if (!mounted || _active?.id != conversation.id) return;
        if (full.messages.isEmpty) {
          // A brand-new draft simply has nothing yet; only a saved thread
          // coming back empty is a failure worth reporting.
          if (conversation.id.startsWith('ask-')) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content:
                  Text(AppLocale.of(context).t('ask_conversation_load_failed')),
            ));
          }

          return;
        }
        setState(() {
          _active = full;
          _conversations.removeWhere((c) => c.id == full.id);
          _conversations.insert(0, full);
        });
      });
    }
  }

  Future<void> _deleteConversation(String id) async {
    if (_sending) return;
    try {
      await widget.repository.deleteAskConversation(id);
    } catch (_) {
      if (widget.repository is RestCampusRepository) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Sohbet silinemedi. Tekrar deneyin.')));
        return;
      }
    }
    await AskArucadStore.delete(id);
    if (!mounted) return;
    setState(() {
      _conversations.removeWhere((c) => c.id == id);
      if (_active?.id == id) {
        _active = _conversations.isNotEmpty
            ? _conversations.first
            : _draftConversation();
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
    var conversation = _active ?? _draftConversation();
    setState(() {
      _active = conversation;
      conversation.messages.add(
          AskArucadMessage(fromUser: true, text: value, at: DateTime.now()));
      _input.clear();
      _sending = true;
      _matchedPlace = null;
      _matchedService = null;
      _matchedClub = null;
      _matchedSport = null;
    });
    _scrollToEnd();

    // Real conversation history — everything before the message just
    // added above, so the backend/Groq call actually gets multi-turn
    // context instead of re-deriving an answer from scratch each time.
    final history = [
      for (final m
          in conversation.messages.sublist(0, conversation.messages.length - 1))
        (fromUser: m.fromUser, text: m.text)
    ];

    String answer;
    var answerSources = const <AskArucadSource>[];
    var answerPlaces = const <AskPlaceComponent>[];
    AskRouteComponent? answerRoute;
    var answerEvents = const <AskEventComponent>[];
    try {
      if (widget.repository is RestCampusRepository) {
        answer = await widget.repository.askGuide(
          value,
          history: history,
          conversationId:
              conversation.id.startsWith('ask-') ? conversation.id : null,
        );
        final rest = widget.repository as RestCampusRepository;
        final sid = rest.lastAskConversationId;
        if (sid != null && sid != conversation.id) {
          conversation = conversation.withId(sid);
          _active = conversation;
        }
        // What the backend actually retrieved for this answer. Read here,
        // next to the call, because the field is per-request state.
        answerSources = rest.lastAskSources;
        answerPlaces = rest.lastAskPlaces;
        answerRoute = rest.lastAskRoute;
        answerEvents = rest.lastAskEvents;
      } else {
        try {
          answer = await _groq.askConversation(
            [
              for (final m in conversation.messages)
                (fromUser: m.fromUser, text: m.text)
            ],
            places: _ctx.places,
            events: _ctx.events,
            clubs: _ctx.clubs,
            sports: _ctx.sports,
            services: _ctx.services,
            foodVenues: _ctx.foodVenues,
          );
        } catch (_) {
          answer = await widget.repository.askGuide(value);
        }
      }
      if (answer.trim().isEmpty) {
        answer = _ctx.localAnswer(value);
      }
    } on ApiClientException catch (e) {
      answer = _ctx.localAnswer(value);
      if (answer.contains('net eşleştiremedim') &&
          e.message.trim().isNotEmpty) {
        answer = e.message;
      }
    } catch (_) {
      answer = _ctx.localAnswer(value);
    }
    answer = stripAskArucadMarkdown(answer);
    if (!mounted) return;

    // The question wins when it names a place. Otherwise take the subject of
    // the answer — the FIRST building it names, not the longest, so
    // "Titan'da, Rodin'in yanında" navigates to Titan.
    final structuredPlace = answerPlaces.isEmpty
        ? null
        : (answerRoute == null ? answerPlaces.first : answerPlaces.last);
    final structuredPlaceId = structuredPlace?.id;
    final place = structuredPlaceId == null
        ? (_ctx.matchPlace(value) ?? _ctx.firstMentionedPlace(answer))
        : (_ctx.places.where((p) => p.id == structuredPlaceId).firstOrNull ??
            _ctx.matchPlace(structuredPlace!.name));
    final service = place == null
        ? (_ctx.matchService(value) ?? _ctx.matchService(answer))
        : null;
    final club = (place == null && service == null)
        ? (_ctx.matchClub(value) ?? _ctx.matchClub(answer))
        : null;
    final sport = (place == null && service == null && club == null)
        ? (_ctx.matchSport(value) ?? _ctx.matchSport(answer))
        : null;

    conversation.messages.add(AskArucadMessage(
      fromUser: false,
      text: answer,
      at: DateTime.now(),
      sources: answerSources,
      places: answerPlaces,
      route: answerRoute,
      events: answerEvents,
    ));
    conversation.updatedAt = DateTime.now();
    if (conversation.messages.where((m) => m.fromUser).length == 1) {
      conversation.title =
          value.length > 42 ? '${value.substring(0, 42)}…' : value;
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
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ClubDetailScreen(
            club: club, events: _ctx.events, repository: widget.repository)));
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

  void _openPlaceDetails(CampusPlace place) {
    showPlaceInfoSheet(
      context,
      poi: poiFromPlace(place),
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

  Future<void> _openPlaceTour(CampusPlace place) async {
    final tour = resolvePlaceTour(place);
    await open360Tour(
      context,
      tour.url,
      tourTarget: tour.target,
      title: place.name,
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
      matchedPlace: _matchedPlace,
      matchedService: _matchedService,
      matchedClub: _matchedClub,
      matchedSport: _matchedSport,
      onNavigate:
          _matchedPlace == null ? null : () => _startNavigation(_matchedPlace!),
      onOpenPlace: _matchedPlace == null
          ? null
          : () => _openPlaceDetails(_matchedPlace!),
      // Every resolved campus place has a deterministic 360 fallback via
      // resolvePlaceTour(). Restored conversations and backend-normalised
      // commands must expose the same action as a freshly typed 360 request.
      onOpenTour:
          _matchedPlace == null ? null : () => _openPlaceTour(_matchedPlace!),
      onContactService: _matchedService == null
          ? null
          : () => _contactService(_matchedService!),
      onOpenService: _matchedService == null
          ? null
          : () => _openServiceDetails(_matchedService!),
      onOpenClub: _matchedClub == null ? null : () => _openClub(_matchedClub!),
      onContactSport:
          _matchedSport == null ? null : () => _contactSport(_matchedSport!),
    );

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: Theme.of(context).colorScheme.surface,
      drawer: wide
          ? null
          : Drawer(backgroundColor: ArucadColors.paper, child: sidebar),
      body: Column(
        children: [
          // Only on narrow layouts, where it carries the drawer button.
          // On a wide screen the sidebar is already visible, so this was
          // an empty header with no title and no leading widget —
          // reserving a full toolbar of blank space above the chat for
          // nothing at all.
          if (!wide)
            CampusPageHeader(
              title: '',
              leading: IconButton(
                icon: const Icon(Icons.menu),
                tooltip: MaterialLocalizations.of(context).openAppDrawerTooltip,
                onPressed: () => _scaffoldKey.currentState?.openDrawer(),
              ),
            ),
          Expanded(
            child: wide
                ? Row(children: [
                    SizedBox(width: 280, child: sidebar),
                    const VerticalDivider(width: 1),
                    Expanded(child: chat),
                  ])
                : chat,
          ),
        ],
      ),
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
  Widget build(BuildContext context) {
    return ColoredBox(
      color: ArucadColors.paper,
      child: SafeArea(
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
            child: Row(children: [
              const CircleAvatar(
                  radius: 16,
                  backgroundColor: Colors.white,
                  backgroundImage:
                      AssetImage('assets/images/aruverse_mark.png')),
              const SizedBox(width: 10),
              Expanded(
                child: Text(AppLocale.of(context).t('nav_ask'),
                    style: TextStyle(
                        color: ArucadColors.ink,
                        fontWeight: FontWeight.w900,
                        fontSize: 15)),
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
                  foregroundColor: ArucadColors.primary,
                  side: const BorderSide(color: ArucadColors.primary),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
                icon: const Icon(Icons.add, size: 18),
                label: Text(AppLocale.of(context).t('ask_new_chat')),
              ),
            ),
          ),
          const SizedBox(height: 6),
          Expanded(
            child: conversations.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(20),
                    child: Text(AppLocale.of(context).t('ask_empty'),
                        style: TextStyle(
                            color: ArucadColors.muted, fontSize: 12.5)),
                  )
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    itemCount: conversations.length,
                    itemBuilder: (context, i) {
                      final c = conversations[i];
                      final selected = c.id == activeId;
                      return Material(
                        color: selected
                            ? ArucadColors.primary.withValues(alpha: .08)
                            : Colors.transparent,
                        child: ListTile(
                          dense: true,
                          leading: Icon(Icons.chat_bubble_outline,
                              color: ArucadColors.primary, size: 18),
                          title: Text(c.title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  color: ArucadColors.ink, fontSize: 13)),
                          trailing: IconButton(
                            iconSize: 16,
                            icon: Icon(Icons.close, color: ArucadColors.muted),
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
}

class _ChatArea extends StatelessWidget {
  final AskArucadConversation? conversation;
  final bool loading;
  final bool sending;
  final ScrollController scrollController;
  final TextEditingController inputController;
  final ValueChanged<String> onSend;
  final CampusPlace? matchedPlace;
  final CampusService? matchedService;
  final CampusClub? matchedClub;
  final CampusSport? matchedSport;
  final VoidCallback? onNavigate;
  final VoidCallback? onOpenPlace;
  final VoidCallback? onOpenTour;
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
    this.matchedPlace,
    this.matchedService,
    this.matchedClub,
    this.matchedSport,
    this.onNavigate,
    this.onOpenPlace,
    this.onOpenTour,
    this.onContactService,
    this.onOpenService,
    this.onOpenClub,
    this.onContactSport,
  });

  @override
  Widget build(BuildContext context) {
    final messages = conversation?.messages ?? const [];
    return ColoredBox(
      color: Theme.of(context).colorScheme.surface,
      child: Column(children: [
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
                    final isLastAssistant =
                        !m.fromUser && i == messages.length - 1;
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Column(
                        crossAxisAlignment: m.fromUser
                            ? CrossAxisAlignment.end
                            : CrossAxisAlignment.start,
                        children: [
                          _MessageBubble(message: m),
                          if (!m.fromUser && m.sources.isNotEmpty)
                            _AnswerSources(sources: m.sources),
                          if (!m.fromUser &&
                              (m.places.isNotEmpty ||
                                  m.events.isNotEmpty ||
                                  m.route != null))
                            _RichAnswerComponents(message: m),
                          if (isLastAssistant)
                            _MatchedActions(
                              matchedPlace: matchedPlace,
                              matchedService: matchedService,
                              matchedClub: matchedClub,
                              matchedSport: matchedSport,
                              onNavigate: onNavigate,
                              onOpenPlace: onOpenPlace,
                              onOpenTour: onOpenTour,
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
                  decoration: InputDecoration(
                    hintText: AppLocale.of(context).t('ask_hint'),
                    isDense: true,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                onPressed: sending ? null : () => onSend(inputController.text),
                style: FilledButton.styleFrom(
                    backgroundColor: ArucadColors.primary,
                    foregroundColor: Colors.white,
                    shape: const CircleBorder(),
                    padding: const EdgeInsets.all(14)),
                child: const Icon(Icons.arrow_upward, size: 18),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}

class _EmptyState extends StatelessWidget {
  final bool loading;
  final ValueChanged<String> onSuggestion;
  const _EmptyState({required this.loading, required this.onSuggestion});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final suggestedPrompts = [
      strings.t('ask_prompt_busy'),
      strings.t('ask_prompt_events'),
      strings.t('ask_prompt_library'),
      strings.t('ask_prompt_clubs'),
    ];
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const CircleAvatar(
              radius: 28,
              backgroundColor: Colors.white,
              backgroundImage: AssetImage('assets/images/aruverse_mark.png')),
          const SizedBox(height: 14),
          Text(strings.t('nav_ask'),
              style:
                  const TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
          const SizedBox(height: 6),
          Text(strings.t('ask_intro'),
              textAlign: TextAlign.center,
              style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                  fontSize: 13)),
          const SizedBox(height: 20),
          if (loading)
            const CircularProgressIndicator()
          else ...[
            FilledButton(
              onPressed: () => onSuggestion(suggestedPrompts[1]),
              style: FilledButton.styleFrom(shape: const StadiumBorder()),
              child: Text(suggestedPrompts[1]),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                for (final prompt in suggestedPrompts
                    .where((prompt) => prompt != suggestedPrompts[1]))
                  ActionChip(
                    backgroundColor:
                        ArucadColors.primary.withValues(alpha: .12),
                    side: BorderSide.none,
                    label: Text(prompt,
                        style: const TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w700,
                            color: ArucadColors.primary)),
                    onPressed: () => onSuggestion(prompt),
                  ),
              ],
            ),
          ],
        ]),
      ),
    );
  }
}

/// The pages and internal tables an answer was built from.
///
/// Shown from the backend's own record of what it retrieved, not from
/// links parsed out of the answer: an invented URL would otherwise arrive
/// with an invented citation attached. A web source opens; a campus source
/// (the events table, the place directory) has nothing to open and simply
/// names itself, which is a more honest citation than silence.
class _AnswerSources extends StatelessWidget {
  final List<AskArucadSource> sources;

  const _AnswerSources({required this.sources});

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(top: 8, left: 4, right: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.library_books_outlined,
                size: 13, color: scheme.onSurfaceVariant),
            const SizedBox(width: 5),
            Text(
              strings.t('ask_sources_title'),
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                color: scheme.onSurfaceVariant,
              ),
            ),
          ]),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final source in sources) _SourceChip(source: source)
            ],
          ),
        ],
      ),
    );
  }
}

class _SourceChip extends StatelessWidget {
  final AskArucadSource source;

  const _SourceChip({required this.source});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final openable = source.isWeb;

    final chip = Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      constraints: const BoxConstraints(maxWidth: 240),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: .7),
        borderRadius: BorderRadius.circular(ArucadRadius.pill),
        border: Border.all(color: scheme.outlineVariant.withValues(alpha: .6)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(
          openable ? Icons.link : Icons.storage_outlined,
          size: 12,
          color: openable ? ArucadColors.primary : scheme.onSurfaceVariant,
        ),
        const SizedBox(width: 5),
        Flexible(
          child: Text(
            source.page == null
                ? source.title
                : '${source.title} · p. ${source.page}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: scheme.onSurface,
            ),
          ),
        ),
      ]),
    );

    if (!openable) return chip;

    return InkWell(
      borderRadius: BorderRadius.circular(ArucadRadius.pill),
      onTap: () => _open(context, source.url),
      child: chip,
    );
  }

  Future<void> _open(BuildContext context, String url) async {
    final strings = AppLocale.of(context);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final uri = Uri.parse(url);
      final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!opened) throw StateError('could not launch');
    } catch (_) {
      messenger.showSnackBar(
          SnackBar(content: Text(strings.t('ask_source_open_failed'))));
    }
  }
}

class _RichAnswerComponents extends StatelessWidget {
  final AskArucadMessage message;
  const _RichAnswerComponents({required this.message});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (message.route case final route?)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 8),
            decoration: BoxDecoration(
              color: scheme.primaryContainer.withValues(alpha: .45),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: scheme.primary.withValues(alpha: .22)),
            ),
            child: Row(children: [
              Icon(Icons.route_outlined, color: scheme.primary),
              const SizedBox(width: 10),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text('${route.originName} → ${route.destinationName}',
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 3),
                    Text(
                        '${(route.distanceMeters / 1000).toStringAsFixed(2)} km · '
                        '${(route.durationSeconds / 60).ceil()} min · ${route.travelMode}',
                        style: TextStyle(
                            fontSize: 12, color: scheme.onSurfaceVariant)),
                  ])),
            ]),
          ),
        for (final place in message.places.take(3))
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 8),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest.withValues(alpha: .55),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: scheme.outlineVariant),
            ),
            child: Row(children: [
              Icon(Icons.place_outlined, color: ArucadColors.primary),
              const SizedBox(width: 10),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text(place.name,
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    Text(
                        [
                          place.category,
                          if (place.building != null) place.building!,
                          if (place.floor != null) place.floor!,
                          if (place.room != null) place.room!,
                        ].join(' · '),
                        style: TextStyle(
                            fontSize: 12, color: scheme.onSurfaceVariant)),
                    if (place.hours != null)
                      Text(place.hours!, style: const TextStyle(fontSize: 12)),
                  ])),
            ]),
          ),
        for (final event in message.events.take(4))
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 8),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest.withValues(alpha: .55),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: scheme.outlineVariant),
            ),
            child: Row(children: [
              Icon(Icons.event_outlined, color: ArucadColors.primary),
              const SizedBox(width: 10),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text(event.title,
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    Text(
                        [
                          if (event.date != null) event.date!,
                          if (event.time != null) event.time!,
                          if (event.placeName != null) event.placeName!,
                        ].join(' · '),
                        style: TextStyle(
                            fontSize: 12, color: scheme.onSurfaceVariant)),
                  ])),
            ]),
          ),
      ]),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  final AskArucadMessage message;
  const _MessageBubble({required this.message});

  @override
  Widget build(BuildContext context) {
    if (message.fromUser) {
      return ConstrainedBox(
        constraints:
            BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
              color: ArucadColors.primary,
              borderRadius: BorderRadius.circular(18)),
          child: Text(message.text,
              style: const TextStyle(color: Colors.white, fontSize: 14.5)),
        ),
      );
    }
    return Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CircleAvatar(
              radius: 14,
              backgroundColor: Colors.white,
              backgroundImage: AssetImage('assets/images/aruverse_mark.png')),
          const SizedBox(width: 8),
          Flexible(
            child: ConstrainedBox(
              constraints: BoxConstraints(
                  maxWidth: MediaQuery.of(context).size.width * .78),
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                    color:
                        Theme.of(context).colorScheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(18)),
                // Source URLs are the whole point of a grounded answer, so
                // they have to be openable rather than read-only text.
                child: LinkifiedText(message.text,
                    style: TextStyle(
                        fontSize: 14.5,
                        color: Theme.of(context).colorScheme.onSurface)),
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
          const CircleAvatar(
              radius: 14,
              backgroundColor: Colors.white,
              backgroundImage: AssetImage('assets/images/aruverse_mark.png')),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(18)),
            child: const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2)),
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
  final VoidCallback? onOpenTour;
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
    this.onOpenTour,
    this.onContactService,
    this.onOpenService,
    this.onOpenClub,
    this.onContactSport,
  });

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    if (matchedPlace == null &&
        matchedService == null &&
        matchedClub == null &&
        matchedSport == null) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.only(top: 8, left: 36),
      child: Wrap(spacing: 8, runSpacing: 8, children: [
        if (matchedPlace != null) ...[
          FilledButton.icon(
            onPressed: onNavigate,
            icon: const Icon(Icons.directions_walk, size: 16),
            label: Text(s.t('ask_directions')),
          ),
          OutlinedButton.icon(
            onPressed: onOpenPlace,
            icon: const Icon(Icons.info_outline, size: 16),
            label: Text(s.t('ask_open')),
          ),
          if (onOpenTour != null)
            FilledButton.icon(
              onPressed: onOpenTour,
              icon: const Icon(Icons.threesixty, size: 16),
              label: Text(s.t('ask_open_360')),
            ),
        ],
        if (matchedService != null) ...[
          FilledButton.icon(
            onPressed: onContactService,
            icon: const Icon(Icons.send_outlined, size: 16),
            label: Text(s.t('ask_preapply')),
          ),
          OutlinedButton.icon(
            onPressed: onOpenService,
            icon: const Icon(Icons.info_outline, size: 16),
            label: Text(s.t('ask_details')),
          ),
        ],
        if (matchedClub != null)
          OutlinedButton.icon(
            onPressed: onOpenClub,
            icon: const Icon(Icons.groups_outlined, size: 16),
            label: Text(s.t('ask_open_club')),
          ),
        if (matchedSport != null)
          FilledButton.icon(
            onPressed: onContactSport,
            icon: const Icon(Icons.mail_outline, size: 16),
            label: Text(s.t('ask_preapply_join')),
          ),
      ]),
    );
  }
}
