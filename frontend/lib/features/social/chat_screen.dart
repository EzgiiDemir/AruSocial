import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/services/social_local_prefs.dart';
import 'package:arucad_campus_prototype/features/social/chat_group_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

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
    } catch (_) {
      muted = await SocialLocalPrefs.mutedPeers();
      archived = await SocialLocalPrefs.archivedPeers();
      final localGroups = await SocialLocalPrefs.groups();
      groups = localGroups
          .map((g) => ChatGroup(
                id: '${g['id']}',
                name: '${g['name']}',
                members: (g['members'] as List?)?.cast<String>() ?? const [],
              ))
          .toList();
    }
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
    super.dispose();
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
              padding: EdgeInsets.only(
                  bottom: MediaQuery.viewInsetsOf(ctx).bottom),
              child: SizedBox(
                height: maxH,
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                      child: Text(AppLocale.of(ctx).t('chat_pick_person'),
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 16)),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: TextField(
                        autofocus: true,
                        decoration: const InputDecoration(
                          hintText: 'İsim ara...',
                          filled: true,
                          fillColor: Colors.white,
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (v) => setSheet(() => query = v.trim()),
                      ),
                    ),
                    Expanded(
                      child: filtered.isEmpty
                          ? const Center(
                              child: Text('Eşleşen kimse yok',
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
              .where((p) =>
                  p.toLowerCase().contains(memberQuery.toLowerCase()))
              .toList();
          return AlertDialog(
            title: const Text('Grup kur'),
            content: SizedBox(
              width: 360,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                      controller: nameC,
                      decoration:
                          const InputDecoration(labelText: 'Grup adı')),
                  const SizedBox(height: 12),
                  TextField(
                    controller: searchC,
                    decoration: const InputDecoration(
                      hintText: 'İsim ara...',
                      filled: true,
                      fillColor: Colors.white,
                      prefixIcon: Icon(Icons.search, size: 20),
                      isDense: true,
                    ),
                    onChanged: (v) =>
                        setDialog(() => memberQuery = v.trim()),
                  ),
                  const SizedBox(height: 8),
                  Flexible(
                    child: filtered.isEmpty
                        ? const Padding(
                            padding: EdgeInsets.symmetric(vertical: 16),
                            child: Text('Eşleşen kimse yok',
                                style: TextStyle(color: ArucadColors.muted)),
                          )
                        : ListView(
                            shrinkWrap: true,
                            children: [
                              for (final peer in filtered)
                                CheckboxListTile(
                                  dense: true,
                                  secondary: CampusAvatar(
                                      name: peer, radius: 16),
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
                  child: const Text('Vazgeç')),
              FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Oluştur')),
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
      final group = await widget.repository
          .createChatGroup(name, selected.toList());
      await _loadPrefsAndGroups();
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('"${group.name}" grubu oluşturuldu')));
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => ChatGroupScreen(
              repository: widget.repository, group: group)));
    } catch (_) {
      // Real failure, not a silently-created local-only group that would
      // look identical to a real one but never sync to the other members.
      if (!mounted || !context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Grup oluşturulamadı. Sunucuya ulaşılamadı.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        toolbarHeight: widget.titleInShell ? 48 : kToolbarHeight,
        title: widget.titleInShell ? null : Text(s.t('chat_title')),
        actions: [
          IconButton(
            tooltip: _showArchived ? 'Gelen kutusu' : 'Arşiv',
            onPressed: () => setState(() => _showArchived = !_showArchived),
            icon: Icon(_showArchived
                ? Icons.inbox_outlined
                : Icons.archive_outlined),
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
            child: const Icon(Icons.group_add_outlined),
          ),
          const SizedBox(height: 10),
          FloatingActionButton(
              heroTag: 'social-chat-new-fab',
              onPressed: _startNew,
              child: const Icon(Icons.edit_outlined)),
        ],
      ),
      body: Column(children: [
        if (_groups.isNotEmpty && !_showArchived)
          SizedBox(
            height: 56,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: [
                for (final g in _groups)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ActionChip(
                      avatar: const Icon(Icons.groups_outlined, size: 18),
                      label: Text(g.name),
                      onPressed: () async {
                        await Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => ChatGroupScreen(
                                repository: widget.repository, group: g)));
                        await _loadPrefsAndGroups();
                      },
                    ),
                  ),
              ],
            ),
          ),
        Expanded(
          child: FutureBuilder<List<ChatThreadPeer>>(
            future: _threadsFuture,
            builder: (context, snap) {
              if (!snap.hasData) {
                return const Center(child: CircularProgressIndicator());
              }
              final threads = snap.data!.where((t) {
                final archived = t.archived || _archived.contains(t.name);
                return _showArchived ? archived : !archived;
              }).toList();
              if (threads.isEmpty) {
                return Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Icon(Icons.forum_outlined,
                          size: 40, color: scheme.onSurfaceVariant),
                      const SizedBox(height: 10),
                      Text(
                          _showArchived
                              ? 'Arşiv boş'
                              : s.t('chat_empty'),
                          style: TextStyle(color: scheme.onSurfaceVariant)),
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
              return ListView.separated(
                itemCount: threads.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final peer = threads[i];
                  final muted = peer.muted || _muted.contains(peer.name);
                  return ListTile(
                    leading:
                        CampusAvatar(name: peer.name, avatarUrl: peer.avatarUrl),
                    title: Text(peer.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: muted
                        ? const Text('Sessize alındı',
                            style: TextStyle(fontSize: 12))
                        : null,
                    trailing: const Icon(Icons.chevron_right),
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
            const SizedBox(width: 10),
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
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content:
                            Text('Sunucuya ulaşılamadı. Tekrar dene.')));
                  }
                  return null;
                }
              }

              switch (v) {
                case 'mute':
                  final on = await toggleField('mute');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(on
                            ? 'Sessize alındı'
                            : 'Sessiz kaldırıldı')));
                  }
                case 'archive':
                  final on = await toggleField('archive');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(
                            on ? 'Arşive alındı' : 'Arşivden çıkarıldı')));
                    if (on) Navigator.of(context).pop();
                  }
                case 'restrict':
                  final on = await toggleField('restrict');
                  if (on != null && mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(on
                            ? 'Kısıtlandı'
                            : 'Kısıtlama kaldırıldı')));
                  }
                case 'block':
                  await widget.repository.toggleBlock(widget.peer);
                  if (mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text('Engel durumu güncellendi')));
                    Navigator.of(context).pop();
                  }
                case 'report':
                  try {
                    await widget.repository
                        .reportUser(widget.peer, 'Kullanıcı şikayeti');
                  } catch (_) {
                    if (!context.mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text('Şikayet gönderilemedi. Tekrar deneyin.')));
                    return;
                  }
                  if (mounted && context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text(
                            'Şikayet alındı. Moderasyon ekibi inceleyecek.')));
                  }
              }
            },
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'mute', child: Text('Sessize al / Aç')),
              PopupMenuItem(value: 'archive', child: Text('Arşive al / Çıkar')),
              PopupMenuItem(
                  value: 'restrict', child: Text('Kısıtla / Kaldır')),
              PopupMenuItem(value: 'block', child: Text('Engelle')),
              PopupMenuItem(value: 'report', child: Text('Şikayet et')),
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
                        child: Text(AppLocale.of(context).t('chat_thread_empty'),
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
                  style: TextStyle(
                      color: Theme.of(context).colorScheme.onSurface),
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
