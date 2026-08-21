import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/services/chat_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Message threads list — real, shared per-peer history in Rest mode (a
/// real backend `ChatController`), still local-only in Mock mode. See the
/// scope banner in [build] for the honest, mode-accurate disclaimer: even
/// in Rest mode this is poll-based, not a live two-way socket connection.
class ChatThreadsScreen extends StatefulWidget {
  final CampusRepository repository;
  final List<String> knownPeers;
  const ChatThreadsScreen({super.key, required this.repository, required this.knownPeers});

  @override
  State<ChatThreadsScreen> createState() => _ChatThreadsScreenState();
}

class _ChatThreadsScreenState extends State<ChatThreadsScreen> {
  late Future<List<String>> _threadsFuture;

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
            'Mesajlar gerçek zamanlı değil — yeni mesaj görmek için sayfayı '
            'yeniden aç. Canlı, anlık teslim için sunucu tarafında bir soket '
            'bağlantısı gerekiyor ve bu prototipte henüz yok.',
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

class _ChatThreadScreenState extends State<ChatThreadScreen> {
  late Future<List<ChatMessage>> _future;
  final _controller = TextEditingController();

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getChatMessages(widget.peer);
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    _controller.clear();
    await widget.repository.sendChatMessage(widget.peer, text);
    setState(() => _future = widget.repository.getChatMessages(widget.peer));
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
