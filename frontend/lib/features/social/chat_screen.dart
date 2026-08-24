import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Message threads list — REST is still the history source of truth.
/// Reverb delivers new rows while this screen is in the foreground.
class ChatThreadsScreen extends StatefulWidget {
  final CampusRepository repository;
  final List<String> knownPeers;
  const ChatThreadsScreen({super.key, required this.repository, required this.knownPeers});

  @override
  State<ChatThreadsScreen> createState() => _ChatThreadsScreenState();
}

class _ChatThreadsScreenState extends State<ChatThreadsScreen> with WidgetsBindingObserver {
  late Future<List<String>> _threadsFuture;
  ChatRealtimeService? _realtime;
  StreamSubscription<ChatMessage>? _messageSub;
  StreamSubscription<void>? _resyncSub;
  CampusUser? _me;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers);
    unawaited(_bindRealtime());
  }

  void _reload() =>
      setState(() => _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers));

  Future<void> _bindRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
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
    if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
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
    final peer = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => ListView(
        padding: const EdgeInsets.symmetric(vertical: 8),
        shrinkWrap: true,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 8, 20, 8),
            child: Text('Kiminle konuşmak istersin?',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          ),
          for (final peer in widget.knownPeers)
            ListTile(
              leading: CircleAvatar(
                  backgroundColor: ArucadColors.mist,
                  child: Text(peer.isEmpty ? '?' : peer.substring(0, 1))),
              title: Text(peer, maxLines: 1, overflow: TextOverflow.ellipsis),
              onTap: () => Navigator.of(ctx).pop(peer),
            ),
        ],
      ),
    );
    if (peer == null || !mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ChatThreadScreen(repository: widget.repository, peer: peer)));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mesajlar'),
        actions: [
          IconButton(onPressed: _reload, icon: const Icon(Icons.refresh)),
        ],
      ),
      floatingActionButton: FloatingActionButton(
          heroTag: 'social-chat-new-fab',
          onPressed: _startNew,
          child: const Icon(Icons.edit_outlined)),
      body: Column(children: [
        Container(
          width: double.infinity,
          color: ArucadColors.mist,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: const Text(
            'Yeni mesajlar anlık gelir. Bağlantı koparsa geçmiş REST ile yenilenir; '
            'manuel yenileme yedek olarak duruyor.',
            style: TextStyle(color: ArucadColors.muted, fontSize: 11.5),
          ),
        ),
        Expanded(
          child: FutureBuilder<List<String>>(
            future: _threadsFuture,
            builder: (context, snap) {
              if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final threads = snap.data!;
              if (threads.isEmpty) {
                return Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.forum_outlined, size: 40, color: ArucadColors.muted),
                      const SizedBox(height: 10),
                      const Text('Henüz mesajın yok.', style: TextStyle(color: ArucadColors.muted)),
                      const SizedBox(height: 14),
                      OutlinedButton.icon(
                        onPressed: _startNew,
                        icon: const Icon(Icons.add),
                        label: const Text('Yeni mesaj'),
                      ),
                    ]),
                  ),
                );
              }
              return ListView.separated(
                itemCount: threads.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final peer = threads[i];
                  return ListTile(
                    leading: CircleAvatar(
                        backgroundColor: ArucadColors.mist,
                        child: Text(peer.isEmpty ? '?' : peer.substring(0, 1))),
                    title: Text(peer,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(
                          builder: (_) =>
                              ChatThreadScreen(repository: widget.repository, peer: peer)));
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
  const ChatThreadScreen({super.key, required this.repository, required this.peer});

  @override
  State<ChatThreadScreen> createState() => _ChatThreadScreenState();
}

class _ChatThreadScreenState extends State<ChatThreadScreen> with WidgetsBindingObserver {
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
      if (!mounted) return;
      _conversationId = rows
          .map((m) => m.conversationId)
          .firstWhere((id) => id != null && id.isNotEmpty, orElse: () => _conversationId);
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
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _ensureRealtime() async {
    if (_realtime != null) return;
    try {
      final me = _me ?? await widget.repository.getMe();
      if (!mounted) return;
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
    if (!mounted) return;
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
      if (!mounted) return;
      _conversationId ??= saved.conversationId;
      setState(() => _messages = ChatRealtimeService.upsert(
            _messages.where((m) => m.id != optimistic.id).toList(),
            saved,
          ));
      final conversationId = saved.conversationId ?? _conversationId;
      if (conversationId != null) {
        await _realtime?.watchConversation(conversationId);
      }
    } catch (_) {
      if (!mounted) return;
      setState(() => _messages = _messages.where((m) => m.id != optimistic.id).toList());
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final me = _me;
    final realtime = _realtime;
    if (me == null || realtime == null) return;
    if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
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
      appBar: AppBar(
        title: Text(widget.peer),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: Column(children: [
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _messages.isEmpty
                  ? const Center(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: Text('Henüz mesaj yok — ilk mesajı sen yaz.',
                            style: TextStyle(color: ArucadColors.muted)),
                      ),
                    )
                  : ListView.builder(
                      reverse: true,
                      padding: const EdgeInsets.all(16),
                      itemCount: _messages.length,
                      itemBuilder: (context, i) {
                        final m = _messages[_messages.length - 1 - i];
                        return Align(
                          alignment: m.fromMe ? Alignment.centerRight : Alignment.centerLeft,
                          child: Container(
                            margin: const EdgeInsets.only(bottom: 8),
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            constraints: BoxConstraints(
                                maxWidth: MediaQuery.of(context).size.width * 0.72),
                            decoration: BoxDecoration(
                              color: m.fromMe ? ArucadColors.primary : ArucadColors.mist,
                              borderRadius: BorderRadius.circular(16),
                            ),
                            child: Text(m.text,
                                style: TextStyle(
                                    color: m.fromMe ? Colors.white : ArucadColors.ink)),
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
                  decoration: const InputDecoration(hintText: 'Mesaj yaz...'),
                  onSubmitted: (_) => _send(),
                ),
              ),
              IconButton(
                onPressed: _send,
                icon: const Icon(Icons.send, color: ArucadColors.primary),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
