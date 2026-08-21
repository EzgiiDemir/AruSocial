import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/realtime_sync.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Message threads list — Rest mode talks to `ChatController` and refreshes
/// when `message.created` lands on the durable realtime bus. Mock mode
/// still keeps history on-device only.
class ChatThreadsScreen extends StatefulWidget {
  final CampusRepository repository;
  final List<String> knownPeers;
  const ChatThreadsScreen({super.key, required this.repository, required this.knownPeers});

  @override
  State<ChatThreadsScreen> createState() => _ChatThreadsScreenState();
}

class _ChatThreadsScreenState extends State<ChatThreadsScreen> with RealtimeAware {
  late Future<List<ChatThreadSummary>> _threadsFuture;

  @override
  Set<String> get realtimeTypes => const {'message.created', 'notification.created'};

  @override
  void onRealtimeEvents(List<String> types) => _reload();

  @override
  void initState() {
    super.initState();
    _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers);
  }

  void _reload() =>
      setState(() => _threadsFuture = widget.repository.getChatThreadPeers(widget.knownPeers));

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

  String _relativeTime(DateTime? at) {
    if (at == null) return '';
    final diff = DateTime.now().difference(at);
    if (diff.inMinutes < 1) return 'şimdi';
    if (diff.inMinutes < 60) return '${diff.inMinutes}dk';
    if (diff.inHours < 24) return '${diff.inHours}sa';
    return '${diff.inDays}g';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mesajlar')),
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
            'Mesajlar veritabanında saklanır ve yeni mesajlar otomatik düşer. '
            'Geçmiş konuşmalar sol listeden tekrar açılabilir.',
            style: TextStyle(color: ArucadColors.muted, fontSize: 11.5),
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () async => _reload(),
            child: FutureBuilder<List<ChatThreadSummary>>(
              future: _threadsFuture,
              builder: (context, snap) {
                if (!snap.hasData) return const Center(child: CircularProgressIndicator());
                final threads = snap.data!;
                if (threads.isEmpty) {
                  return ListView(children: [
                    Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        const SizedBox(height: 40),
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
                  ]);
                }
                // Real conversation list (docs/EKSIKLER.md sosyal/chat §3):
                // avatar, name, last message, time, unread count — nothing
                // beyond that, so the list stays scannable.
                return ListView.separated(
                  itemCount: threads.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final t = threads[i];
                    final peer = t.peerName;
                    final unread = t.unreadCount > 0;
                    return ListTile(
                      leading: CircleAvatar(
                          backgroundColor: unread
                              ? ArucadColors.primary
                              : categoryAccent(peer).withValues(alpha: .85),
                          child: Text(peer.isEmpty ? '?' : peer.substring(0, 1),
                              style: const TextStyle(
                                  color: Colors.white, fontWeight: FontWeight.w800))),
                      title: Text(peer,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontWeight: unread ? FontWeight.w900 : FontWeight.w700)),
                      subtitle: t.lastMessage == null
                          ? null
                          : Text(t.lastMessage!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  color: unread ? ArucadColors.ink : ArucadColors.muted,
                                  fontWeight: unread ? FontWeight.w600 : FontWeight.normal)),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(_relativeTime(t.lastMessageAt),
                              style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
                          if (unread) ...[
                            const SizedBox(height: 4),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(
                                  color: ArucadColors.primary,
                                  borderRadius: BorderRadius.circular(999)),
                              child: Text('${t.unreadCount}',
                                  style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 11,
                                      fontWeight: FontWeight.w800)),
                            ),
                          ],
                        ],
                      ),
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

class _ChatThreadScreenState extends State<ChatThreadScreen> with RealtimeAware {
  late Future<List<ChatMessage>> _future;
  final _controller = TextEditingController();
  bool _sending = false;

  @override
  Set<String> get realtimeTypes => const {'message.created'};

  @override
  void onRealtimeEvents(List<String> types) {
    setState(() => _future = widget.repository.getChatMessages(widget.peer));
  }

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getChatMessages(widget.peer);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  // Real bug fix (docs/EKSIKLER.md sosyal/chat): this used to clear the
  // text field and fire the send request with no error handling at all —
  // a failed send (expired session, network blip) silently lost the typed
  // message with zero feedback, which is exactly the "gönderince ekrana
  // düşmüyor" symptom. Now the text only clears on a real confirmed
  // success, and a real failure restores it with a visible error.
  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      await widget.repository.sendChatMessage(widget.peer, text);
      if (!mounted) return;
      _controller.clear();
      setState(() {
        _sending = false;
        _future = widget.repository.getChatMessages(widget.peer);
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Mesaj gönderilemedi. Lütfen tekrar dene.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.peer)),
      body: Column(children: [
        Expanded(
          child: FutureBuilder<List<ChatMessage>>(
            future: _future,
            builder: (context, snap) {
              if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final messages = snap.data!;
              if (messages.isEmpty) {
                return const Center(
                  child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('Henüz mesaj yok — ilk mesajı sen yaz.',
                        style: TextStyle(color: ArucadColors.muted)),
                  ),
                );
              }
              return ListView.builder(
                reverse: true,
                padding: const EdgeInsets.all(16),
                itemCount: messages.length,
                itemBuilder: (context, i) {
                  final m = messages[messages.length - 1 - i];
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
                          style: TextStyle(color: m.fromMe ? Colors.white : ArucadColors.ink)),
                    ),
                  );
                },
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
                  enabled: !_sending,
                  decoration: const InputDecoration(hintText: 'Mesaj yaz...'),
                  onSubmitted: (_) => _send(),
                ),
              ),
              IconButton(
                onPressed: _sending ? null : _send,
                icon: _sending
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.send, color: ArucadColors.primary),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
