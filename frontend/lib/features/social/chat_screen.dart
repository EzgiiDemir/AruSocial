import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/models/report_reason.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/social/chat_group_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/report_sheet.dart';

/// Message threads list — REST is the history source of truth.
/// Reverb delivers new rows while this screen is in the foreground.
class ChatThreadsScreen extends StatefulWidget {
  final CampusRepository repository;
  final List<String> knownPeers;

  /// When true, title is rendered by [SocialShell] beside the ☰ button.
  final bool titleInShell;

  const ChatThreadsScreen({
    super.key,
    required this.repository,
    required this.knownPeers,
    this.titleInShell = false,
  });

  @override
  State<ChatThreadsScreen> createState() => _ChatThreadsScreenState();
}

enum _ChatListFilter { all, unread, groups }

class _ChatThreadsScreenState extends State<ChatThreadsScreen>
    with WidgetsBindingObserver {
  late Future<List<ChatThreadPeer>> _threadsFuture;
  ChatRealtimeService? _realtime;
  StreamSubscription<ChatMessage>? _messageSub;
  StreamSubscription<void>? _resyncSub;
  CampusUser? _me;
  Set<String> _muted = {};
  Set<String> _archived = {};
  List<ChatGroup> _groups = const [];
  bool _showArchived = false;
  _ChatListFilter _filter = _ChatListFilter.all;
  final _searchController = TextEditingController();
  String _query = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers);
    unawaited(_bindRealtime());
    unawaited(_loadPrefsAndGroups());
  }

  Future<void> _loadPrefsAndGroups() async {
    Set<String> muted = {};
    Set<String> archived = {};
    List<ChatGroup> groups = const [];
    try {
      final prefs = await widget.repository.getChatPrefs();
      muted = prefs.byPeer.entries
          .where((e) => e.value.muted)
          .map((e) => e.key)
          .toSet();
      archived = prefs.byPeer.entries
          .where((e) => e.value.archived)
          .map((e) => e.key)
          .toSet();
      groups = await widget.repository.getChatGroups();
    } catch (_) {}
    if (!mounted || !context.mounted) return;
    setState(() {
      _muted = muted;
      _archived = archived;
      _groups = groups;
    });
  }

  void _reload() {
    setState(() {
      _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers);
    });
  }

  Future<void> _bindRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted || !context.mounted) return;
      _me = me;
      final realtime = ChatRealtimeService.forRepository(widget.repository);
      _realtime = realtime;
      _messageSub = realtime.messages.listen((_) {
        if (mounted) _reload();
      });
      _resyncSub = realtime.resynced.listen((_) {
        if (mounted) _reload();
      });
      await realtime.start(userId: me.id, userName: me.name);
    } catch (_) {
      // Realtime is optional; REST list still loads.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final me = _me;
    final realtime = _realtime;
    if (me == null || realtime == null) return;
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden) {
      unawaited(realtime.pause());
    } else if (state == AppLifecycleState.resumed) {
      unawaited(realtime.resume(userId: me.id, userName: me.name));
      _reload();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_messageSub?.cancel() ?? Future.value());
    unawaited(_resyncSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    _searchController.dispose();
    super.dispose();
  }

  /// Thread list timestamp: a clock time today, "Yesterday", then elapsed
  /// days. Was hardcoded Turkish ("Dün", "3 gün önce") regardless of the
  /// chosen language.
  String _threadTime(DateTime? at) {
    if (at == null) return '';

    final local = at.toLocal();
    final days = DateTime.now().difference(local).inDays;

    if (days == 0) {
      final h = local.hour.toString().padLeft(2, '0');
      final m = local.minute.toString().padLeft(2, '0');
      return '$h:$m';
    }
    if (days == 1) return AppLocale.of(context).t('time_yesterday');

    return formatRelativeTime(local,
        language: AppLocale.languageOf(context));
  }

  Future<void> _threadAction(ChatThreadPeer peer, String field) async {
    try {
      await widget.repository.toggleChatPref(peer.name, field);
      await _loadPrefsAndGroups();
      _reload();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Mesaj ayarı kaydedilemedi.')),
      );
    }
  }

  Future<void> _startNew() async {
    var query = '';
    final peer = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          final filtered = widget.knownPeers
              .where((p) => p.toLowerCase().contains(query.toLowerCase()))
              .toList();
          final maxH = MediaQuery.sizeOf(ctx).height * 0.7;
          return SafeArea(
            child: Padding(
              padding:
                  EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(ctx).bottom),
              child: SizedBox(
                height: maxH,
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                      child: Text(AppLocale.of(ctx).t('chat_pick_person'),
                          style: TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 16)),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: TextField(
                        autofocus: true,
                        decoration: InputDecoration(
                          hintText: AppLocale.of(context).t('ch_search_name'),
                          filled: true,
                          fillColor: Colors.white,
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (v) => setSheet(() => query = v.trim()),
                      ),
                    ),
                    Expanded(
                      child: filtered.isEmpty
                          ? Center(
                              child: Text(
                                  AppLocale.of(context).t('ch_no_match'),
                                  style: TextStyle(color: ArucadColors.muted)),
                            )
                          : ListView.builder(
                              itemCount: filtered.length,
                              itemBuilder: (_, i) {
                                final p = filtered[i];
                                return ListTile(
                                  leading: CampusAvatar(name: p),
                                  title: Text(p,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis),
                                  onTap: () => Navigator.of(ctx).pop(p),
                                );
                              },
                            ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
    if (peer == null || !mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) =>
            ChatThreadScreen(repository: widget.repository, peer: peer)));
    _reload();
  }

  Future<void> _createGroup() async {
    final nameC = TextEditingController();
    final searchC = TextEditingController();
    final selected = <String>{};
    var memberQuery = '';
    final created = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialog) {
          final filtered = widget.knownPeers
              .where((p) => p.toLowerCase().contains(memberQuery.toLowerCase()))
              .toList();
          return AlertDialog(
            title: Text('Grup kur'),
            content: SizedBox(
              width: 360,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                      controller: nameC,
                      decoration: InputDecoration(
                          labelText: AppLocale.of(context).t('ch_group_name'))),
                  SizedBox(height: 12),
                  TextField(
                    controller: searchC,
                    decoration: InputDecoration(
                      hintText: AppLocale.of(context).t('ch_search_name'),
                      filled: true,
                      fillColor: Colors.white,
                      prefixIcon: Icon(Icons.search, size: 20),
                      isDense: true,
                    ),
                    onChanged: (v) => setDialog(() => memberQuery = v.trim()),
                  ),
                  SizedBox(height: 8),
                  Flexible(
                    child: filtered.isEmpty
                        ? Padding(
                            padding: EdgeInsets.symmetric(vertical: 16),
                            child: Text(AppLocale.of(context).t('ch_no_match'),
                                style: TextStyle(color: ArucadColors.muted)),
                          )
                        : ListView(
                            shrinkWrap: true,
                            children: [
                              for (final peer in filtered)
                                CheckboxListTile(
                                  dense: true,
                                  secondary:
                                      CampusAvatar(name: peer, radius: 16),
                                  value: selected.contains(peer),
                                  title: Text(peer),
                                  onChanged: (v) => setDialog(() {
                                    if (v == true) {
                                      selected.add(peer);
                                    } else {
                                      selected.remove(peer);
                                    }
                                  }),
                                ),
                            ],
                          ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: Text(AppLocale.of(context).t('act_cancel'))),
              FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: Text(AppLocale.of(context).t('act_create'))),
            ],
          );
        },
      ),
    );
    final name = nameC.text.trim();
    nameC.dispose();
    searchC.dispose();
    if (created != true || !mounted) return;
    if (name.isEmpty || selected.isEmpty) return;
    try {
      final group =
          await widget.repository.createChatGroup(name, selected.toList());
      await _loadPrefsAndGroups();
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('"${group.name}" grubu oluşturuldu')));
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) =>
              ChatGroupScreen(repository: widget.repository, group: group)));
    } catch (_) {
      // Real failure, not a silently-created local-only group that would
      // look identical to a real one but never sync to the other members.
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('ch_group_failed'))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    return Scaffold(
      backgroundColor: ArucadColors.canvas,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        toolbarHeight: widget.titleInShell ? 48 : kToolbarHeight,
        title: widget.titleInShell ? null : Text(s.t('chat_title')),
        actions: [
          IconButton(
            tooltip: 'Grup oluştur',
            onPressed: _createGroup,
            icon: const Icon(Icons.group_add_outlined),
          ),
          IconButton(
            tooltip: _showArchived ? 'Gelen kutusu' : 'Arşiv',
            onPressed: () => setState(() => _showArchived = !_showArchived),
            icon: Icon(
                _showArchived ? Icons.inbox_outlined : Icons.archive_outlined),
          ),
          IconButton(onPressed: _reload, icon: const Icon(Icons.refresh)),
        ],
      ),
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          FloatingActionButton.small(
            heroTag: 'social-chat-group-fab',
            onPressed: _createGroup,
            backgroundColor: ArucadColors.primary,
            foregroundColor: Colors.white,
            child: const Icon(Icons.person_add_alt_1_outlined),
          ),
          const SizedBox(height: 10),
          FloatingActionButton(
              heroTag: 'social-chat-new-fab',
              onPressed: _startNew,
              backgroundColor: ArucadColors.primary,
              foregroundColor: Colors.white,
              child: const Icon(Icons.edit_outlined)),
        ],
      ),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
          child: TextField(
            controller: _searchController,
            onChanged: (value) => setState(() => _query = value.trim()),
            decoration: InputDecoration(
              hintText: 'Mesajlarda ara...',
              prefixIcon:
                  const Icon(Icons.search_rounded, color: ArucadColors.muted),
              suffixIcon: _query.isEmpty
                  ? null
                  : IconButton(
                      onPressed: () {
                        _searchController.clear();
                        setState(() => _query = '');
                      },
                      icon: const Icon(Icons.close_rounded),
                    ),
              filled: true,
              fillColor: ArucadColors.paper,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(999),
                borderSide: const BorderSide(color: ArucadColors.border),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(999),
                borderSide: const BorderSide(color: ArucadColors.border),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
          child: Row(children: [
            _ChatFilterChip(
                label: 'Tümü',
                selected: _filter == _ChatListFilter.all,
                onTap: () => setState(() => _filter = _ChatListFilter.all)),
            const SizedBox(width: 8),
            _ChatFilterChip(
                label: 'Okunmamış',
                selected: _filter == _ChatListFilter.unread,
                onTap: () => setState(() => _filter = _ChatListFilter.unread)),
            const SizedBox(width: 8),
            _ChatFilterChip(
                label: 'Gruplar',
                selected: _filter == _ChatListFilter.groups,
                onTap: () => setState(() => _filter = _ChatListFilter.groups)),
          ]),
        ),
        Expanded(
          child: FutureBuilder<List<ChatThreadPeer>>(
            future: _threadsFuture,
            builder: (context, snap) {
              if (!snap.hasData) {
                return Center(child: CircularProgressIndicator());
              }
              final query = _query.toLowerCase();
              final threads = snap.data!.where((t) {
                final archived = t.archived || _archived.contains(t.name);
                if (!(_showArchived ? archived : !archived)) return false;
                if (_filter == _ChatListFilter.groups) return false;
                if (_filter == _ChatListFilter.unread && t.unreadCount == 0) {
                  return false;
                }
                return query.isEmpty ||
                    t.name.toLowerCase().contains(query) ||
                    (t.lastMessage ?? '').toLowerCase().contains(query);
              }).toList();
              final groups = _groups.where((group) {
                if (_showArchived != group.archived) return false;
                if (_filter == _ChatListFilter.unread) return false;
                return query.isEmpty ||
                    group.name.toLowerCase().contains(query);
              }).toList();
              if (threads.isEmpty && groups.isEmpty) {
                return Center(
                  child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.forum_outlined,
                          size: 40, color: ArucadColors.muted),
                      const SizedBox(height: 10),
                      Text(_showArchived ? 'Arşiv boş' : s.t('chat_empty'),
                          style: const TextStyle(color: ArucadColors.muted)),
                      if (!_showArchived) ...[
                        const SizedBox(height: 14),
                        OutlinedButton.icon(
                          onPressed: _startNew,
                          icon: const Icon(Icons.add),
                          label: Text(s.t('chat_new')),
                        ),
                      ],
                    ]),
                  ),
                );
              }
              return ListView.builder(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
                itemCount: groups.length + threads.length,
                itemBuilder: (context, i) {
                  if (i < groups.length) {
                    final group = groups[i];
                    return _ConversationCard(
                      name: group.name,
                      preview: '${group.members.length} üye',
                      group: true,
                      onTap: () async {
                        await Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => ChatGroupScreen(
                                repository: widget.repository, group: group)));
                        await _loadPrefsAndGroups();
                      },
                    );
                  }
                  final peer = threads[i - groups.length];
                  final muted = peer.muted || _muted.contains(peer.name);
                  return _ConversationCard(
                    name: peer.name,
                    avatarUrl: peer.avatarUrl,
                    preview: muted
                        ? '${peer.lastMessage ?? ''} · Sessizde'
                        : (peer.lastMessage ?? 'Sohbeti aç'),
                    time: _threadTime(peer.lastMessageAt),
                    unreadCount: peer.unreadCount,
                    onMenu: (value) => _threadAction(peer, value),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(
                          builder: (_) => ChatThreadScreen(
                              repository: widget.repository,
                              peer: peer.name,
                              peerAvatarUrl: peer.avatarUrl)));
                      await _loadPrefsAndGroups();
                      _reload();
                    },
                  );
                },
              );
            },
          ),
        ),
      ]),
    );
  }
}

class _ChatFilterChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _ChatFilterChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => Expanded(
        child: Material(
          color: selected ? ArucadColors.primary : ArucadColors.mist,
          borderRadius: BorderRadius.circular(999),
          child: InkWell(
            onTap: onTap,
            hoverColor: Colors.transparent,
            splashFactory: NoSplash.splashFactory,
            overlayColor: WidgetStateProperty.all(Colors.transparent),
            borderRadius: BorderRadius.circular(999),
            child: SizedBox(
              height: 40,
              child: Center(
                child: Text(label,
                    style: TextStyle(
                      color: selected ? Colors.white : ArucadColors.muted,
                      fontWeight: FontWeight.w700,
                      fontSize: 12.5,
                    )),
              ),
            ),
          ),
        ),
      );
}

class _ConversationCard extends StatelessWidget {
  final String name;
  final String? avatarUrl;
  final String preview;
  final String time;
  final int unreadCount;
  final bool group;
  final VoidCallback onTap;
  final ValueChanged<String>? onMenu;

  const _ConversationCard({
    required this.name,
    required this.preview,
    required this.onTap,
    this.avatarUrl,
    this.time = '',
    this.unreadCount = 0,
    this.group = false,
    this.onMenu,
  });

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 9),
        elevation: 0,
        color: ArucadColors.paper,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: ArucadColors.border),
        ),
        child: InkWell(
          onTap: onTap,
          hoverColor: Colors.transparent,
          splashFactory: NoSplash.splashFactory,
          overlayColor: WidgetStateProperty.all(Colors.transparent),
          borderRadius: BorderRadius.circular(18),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 13, 8, 13),
            child: Row(children: [
              group
                  ? const CircleAvatar(
                      radius: 24,
                      backgroundColor: Color(0x1A000F9F),
                      child: Icon(Icons.groups_rounded,
                          color: ArucadColors.primary))
                  : CampusAvatar(name: name, avatarUrl: avatarUrl, radius: 24),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: ArucadColors.ink,
                            fontWeight: FontWeight.w800,
                            fontSize: 14.5)),
                    const SizedBox(height: 3),
                    Text(preview,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 12.5)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  if (time.isNotEmpty)
                    Text(time,
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 11.5)),
                  if (unreadCount > 0) ...[
                    const SizedBox(height: 5),
                    Container(
                      constraints:
                          const BoxConstraints(minWidth: 14, minHeight: 14),
                      alignment: Alignment.center,
                      padding: const EdgeInsets.symmetric(horizontal: 3),
                      decoration: const BoxDecoration(
                        color: ArucadColors.primary,
                        shape: BoxShape.circle,
                      ),
                      child: Text('$unreadCount',
                          style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w800,
                              fontSize: 8)),
                    ),
                  ],
                ],
              ),
              if (onMenu != null)
                PopupMenuButton<String>(
                  tooltip: 'Sohbet seçenekleri',
                  style: ButtonStyle(
                    overlayColor: WidgetStateProperty.all(Colors.transparent),
                    splashFactory: NoSplash.splashFactory,
                  ),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16)),
                  onSelected: onMenu,
                  itemBuilder: (_) => const [
                    PopupMenuItem(value: 'mute', child: Text('Sessize al')),
                    PopupMenuItem(value: 'archive', child: Text('Arşivle')),
                  ],
                ),
            ]),
          ),
        ),
      );
}

class ChatThreadScreen extends StatefulWidget {
  final CampusRepository repository;
  final String peer;
  final String? peerAvatarUrl;
  const ChatThreadScreen({
    super.key,
    required this.repository,
    required this.peer,
    this.peerAvatarUrl,
  });

  @override
  State<ChatThreadScreen> createState() => _ChatThreadScreenState();
}

class _ChatThreadScreenState extends State<ChatThreadScreen>
    with WidgetsBindingObserver {
  List<ChatMessage> _messages = const [];
  bool _loading = true;
  final _controller = TextEditingController();
  ChatRealtimeService? _realtime;
  StreamSubscription<ChatMessage>? _messageSub;
  StreamSubscription<void>? _resyncSub;
  CampusUser? _me;
  String? _conversationId;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_load());
  }

  Future<void> _load() async {
    try {
      final rows = await widget.repository.getChatMessages(widget.peer);
      if (!mounted || !context.mounted) return;
      _conversationId = rows.map((m) => m.conversationId).firstWhere(
          (id) => id != null && id.isNotEmpty,
          orElse: () => _conversationId);
      setState(() {
        _messages = rows;
        _loading = false;
      });
      await _ensureRealtime();
      final conversationId = _conversationId;
      if (conversationId != null) {
        await _realtime?.watchConversation(conversationId);
      }
    } catch (_) {
      if (!mounted || !context.mounted) return;
      setState(() => _loading = false);
    }
  }

  /// Report the person on the other side of this conversation.
  ///
  /// Uses the shared sheet so the reason reaches the queue as a category
  /// rather than free text, and so the report needs a deliberate second
  /// tap. Reporting someone is not an action to fire off a popup menu.
  Future<void> _reportPeer(BuildContext context) async {
    ReportReason? chosen;

    final sent = await showReportSheet(
      context,
      targetLabel: widget.peer,
      onSubmit: (submission) async {
        chosen = submission.reason;
        await widget.repository.reportUser(
          widget.peer,
          submission.description,
          reasonCode: submission.reason.code,
        );
      },
    );

    if (!sent || !context.mounted || chosen == null) return;
    showReportSentMessage(context, chosen!);
  }

  Future<void> _ensureRealtime() async {
    if (_realtime != null) return;
    try {
      final me = _me ?? await widget.repository.getMe();
      if (!mounted || !context.mounted) return;
      _me = me;
      final realtime = ChatRealtimeService.forRepository(widget.repository);
      _realtime = realtime;
      _messageSub = realtime.messages.listen(_onIncoming);
      _resyncSub = realtime.resynced.listen((_) {
        unawaited(_load());
      });
      await realtime.start(
        userId: me.id,
        userName: me.name,
        conversationId: _conversationId,
      );
    } catch (_) {
      // Socket optional — composer/send still go through REST.
    }
  }

  void _onIncoming(ChatMessage incoming) {
    if (!_isForThisThread(incoming)) return;
    _conversationId ??= incoming.conversationId;
    if (!mounted || !context.mounted) return;
    setState(() => _messages = ChatRealtimeService.upsert(_messages, incoming));
  }

  bool _isForThisThread(ChatMessage incoming) {
    if (_conversationId != null &&
        incoming.conversationId != null &&
        incoming.conversationId == _conversationId) {
      return true;
    }
    if (incoming.sender == widget.peer) return true;
    return incoming.fromMe;
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    _controller.clear();
    final optimistic = ChatMessage(
      id: 'tmp-${DateTime.now().microsecondsSinceEpoch}',
      fromMe: true,
      text: text,
      sender: _me?.name,
    );
    setState(() => _messages = [..._messages, optimistic]);
    try {
      final saved = await widget.repository.sendChatMessage(widget.peer, text);
      if (!mounted || !context.mounted) return;
      _conversationId ??= saved.conversationId;
      setState(() => _messages = ChatRealtimeService.upsert(
            _messages.where((m) => m.id != optimistic.id).toList(),
            saved,
          ));
      final conversationId = saved.conversationId ?? _conversationId;
      if (conversationId != null) {
        await _realtime?.watchConversation(conversationId);
      }
    } on ContentModerationException catch (e) {
      if (!mounted || !context.mounted) return;
      setState(() =>
          _messages = _messages.where((m) => m.id != optimistic.id).toList());
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.reason)));
    } catch (_) {
      if (!mounted || !context.mounted) return;
      setState(() =>
          _messages = _messages.where((m) => m.id != optimistic.id).toList());
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('chat_send_failed'))));
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final me = _me;
    final realtime = _realtime;
    if (me == null || realtime == null) return;
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden) {
      unawaited(realtime.pause());
    } else if (state == AppLifecycleState.resumed) {
      unawaited(realtime.resume(
        userId: me.id,
        userName: me.name,
        conversationId: _conversationId,
      ));
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller.dispose();
    unawaited(_messageSub?.cancel() ?? Future.value());
    unawaited(_resyncSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        leading: const CampusBackButton(),
        titleSpacing: 0,
        title: InkWell(
          onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => SocialProfileScreen(
                  repository: widget.repository, viewedUserName: widget.peer))),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            CampusAvatar(
                name: widget.peer, avatarUrl: widget.peerAvatarUrl, radius: 16),
            SizedBox(width: 10),
            Flexible(child: Text(widget.peer, overflow: TextOverflow.ellipsis)),
          ]),
        ),
        actions: [
          PopupMenuButton<String>(
            onSelected: (v) async {
              // Real state or a real error — never a silent local-only
              // fallback dressed up as the same success message.
              Future<bool?> toggleField(String field) async {
                try {
                  final state = await widget.repository
                      .toggleChatPref(widget.peer, field);
                  return switch (field) {
                    'mute' => state.muted,
                    'archive' => state.archived,
                    'restrict' => state.restricted,
                    _ => false,
                  };
                } catch (_) {
                  if (mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(AppLocale.of(context)
                            .t('act_server_unreachable'))));
                  }
                  return null;
                }
              }

              switch (v) {
                case 'mute':
                  final on = await toggleField('mute');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content:
                            Text(on ? 'Sessize alındı' : 'Sessiz kaldırıldı')));
                  }
                case 'archive':
                  final on = await toggleField('archive');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content:
                            Text(on ? 'Arşive alındı' : 'Arşivden çıkarıldı')));
                    if (on) Navigator.of(context).pop();
                  }
                case 'restrict':
                  final on = await toggleField('restrict');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content:
                            Text(on ? 'Kısıtlandı' : 'Kısıtlama kaldırıldı')));
                  }
                case 'block':
                  await widget.repository.toggleBlock(widget.peer);
                  if (mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content:
                            Text(AppLocale.of(context).t('sp_block_toggled'))));
                    Navigator.of(context).pop();
                  }
                case 'report':
                  // Through the shared sheet, like every other surface.
                  //
                  // This used to fire the moment the menu item was
                  // touched: no confirmation, and a hardcoded Turkish
                  // reason. One mis-tap filed a report, and the queue
                  // received it with no category — so a moderator could
                  // not tell harassment from spam, and the same complaint
                  // filed in two languages arrived as two unrelated things.
                  await _reportPeer(context);
              }
            },
            itemBuilder: (context) => [
              PopupMenuItem(
                  value: 'mute',
                  child: Text(AppLocale.of(context).t('ch_mute_toggle'))),
              PopupMenuItem(
                  value: 'archive',
                  child: Text(AppLocale.of(context).t('ch_archive_toggle'))),
              PopupMenuItem(
                  value: 'restrict',
                  child: Text(AppLocale.of(context).t('ch_restrict_toggle'))),
              PopupMenuItem(
                  value: 'block',
                  child: Text(AppLocale.of(context).t('sp_block_toggle'))),
              PopupMenuItem(
                  value: 'report',
                  child: Text(AppLocale.of(context).t('act_report'))),
            ],
          ),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: Column(children: [
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _messages.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text(
                            AppLocale.of(context).t('chat_thread_empty'),
                            style: TextStyle(
                                color: Theme.of(context)
                                    .colorScheme
                                    .onSurfaceVariant)),
                      ),
                    )
                  : ListView.builder(
                      reverse: true,
                      padding: const EdgeInsets.all(16),
                      itemCount: _messages.length,
                      itemBuilder: (context, i) {
                        final m = _messages[_messages.length - 1 - i];
                        final hh = m.sentAt.hour.toString().padLeft(2, '0');
                        final mm = m.sentAt.minute.toString().padLeft(2, '0');
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Column(
                            crossAxisAlignment: m.fromMe
                                ? CrossAxisAlignment.end
                                : CrossAxisAlignment.start,
                            children: [
                              Align(
                                alignment: m.fromMe
                                    ? Alignment.centerRight
                                    : Alignment.centerLeft,
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 14, vertical: 10),
                                  constraints: BoxConstraints(
                                      maxWidth:
                                          MediaQuery.of(context).size.width *
                                              0.72),
                                  decoration: BoxDecoration(
                                    color: m.fromMe
                                        ? ArucadColors.primary
                                        : Theme.of(context)
                                            .colorScheme
                                            .surfaceContainerHighest,
                                    borderRadius: BorderRadius.only(
                                      topLeft: const Radius.circular(16),
                                      topRight: const Radius.circular(16),
                                      bottomLeft:
                                          Radius.circular(m.fromMe ? 16 : 4),
                                      bottomRight:
                                          Radius.circular(m.fromMe ? 4 : 16),
                                    ),
                                  ),
                                  child: Text(m.text,
                                      style: TextStyle(
                                          color: m.fromMe
                                              ? Colors.white
                                              : Theme.of(context)
                                                  .colorScheme
                                                  .onSurface)),
                                ),
                              ),
                              Padding(
                                padding: const EdgeInsets.only(
                                    top: 2, left: 4, right: 4),
                                child: Text('$hh:$mm',
                                    style: TextStyle(
                                        fontSize: 10.5,
                                        color: Theme.of(context)
                                            .colorScheme
                                            .onSurfaceVariant)),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            child: Row(children: [
              Expanded(
                child: TextField(
                  controller: _controller,
                  minLines: 1,
                  maxLines: 4,
                  style:
                      TextStyle(color: Theme.of(context).colorScheme.onSurface),
                  cursorColor: ArucadColors.primary,
                  textInputAction: TextInputAction.send,
                  decoration: InputDecoration(
                    hintText: AppLocale.of(context).t('chat_hint'),
                    hintStyle: TextStyle(
                        color: Theme.of(context).colorScheme.onSurfaceVariant),
                    filled: true,
                    fillColor:
                        Theme.of(context).colorScheme.surfaceContainerHighest,
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 16, vertical: 10),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(24),
                        borderSide: BorderSide.none),
                  ),
                  onSubmitted: (_) => _send(),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                onPressed: _send,
                style: FilledButton.styleFrom(
                    backgroundColor: ArucadColors.primary,
                    foregroundColor: Colors.white,
                    shape: const CircleBorder(),
                    padding: const EdgeInsets.all(12)),
                child: const Icon(Icons.send, size: 18),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
