import 'dart:async';
import 'package:arucad_campus_prototype/features/widgets/top_notice.dart';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

/// Simple group-thread UI backed by `GET/POST /chat/groups/{id}/messages`.
class ChatGroupScreen extends StatefulWidget {
  final CampusRepository repository;
  final ChatGroup group;

  const ChatGroupScreen({
    super.key,
    required this.repository,
    required this.group,
  });

  @override
  State<ChatGroupScreen> createState() => _ChatGroupScreenState();
}

class _ChatGroupScreenState extends State<ChatGroupScreen> {
  List<ChatMessage> _messages = const [];
  bool _loading = true;
  final _controller = TextEditingController();
  late bool _muted;
  late bool _archived;

  @override
  void initState() {
    super.initState();
    _muted = widget.group.muted;
    _archived = widget.group.archived;
    unawaited(_load());
  }

  Future<void> _load() async {
    try {
      final rows =
          await widget.repository.getGroupMessages(widget.group.id);
      if (!mounted) return;
      setState(() {
        _messages = rows;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _onGroupMenu(String value) async {
    final id = widget.group.id;
    switch (value) {
      case 'mute':
        try {
          final updated =
              await widget.repository.toggleChatGroupPref(id, 'mute');
          if (!mounted) return;
          setState(() => _muted = updated.muted);
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(
                  _muted ? 'Sessize alındı' : 'Sessiz kaldırıldı')));
        } catch (_) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(AppLocale.of(context).t('cg_action_failed'))));
        }
      case 'archive':
        try {
          final updated =
              await widget.repository.toggleChatGroupPref(id, 'archive');
          if (!mounted) return;
          setState(() => _archived = updated.archived);
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(
                  _archived ? 'Arşive alındı' : 'Arşivden çıkarıldı')));
        } catch (_) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(AppLocale.of(context).t('cg_action_failed'))));
        }
      case 'leave':
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(AppLocale.of(context).t('cg_leave_q')),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: Text(AppLocale.of(context).t('act_cancel'))),
              FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: Text(AppLocale.of(context).t('cg_leave'))),
            ],
          ),
        );
        if (ok != true || !mounted) return;
        try {
          await widget.repository.leaveChatGroup(id);
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(AppLocale.of(context).t('cg_left'))));
          Navigator.of(context).pop();
        } catch (_) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(AppLocale.of(context).t('cg_leave_failed'))));
        }
      case 'report':
        try {
          await widget.repository
              .reportChatGroup(id, 'Grup şikayeti');
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(AppLocale.of(context).t('act_report_sent'))));
        } catch (_) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Şikayet gönderilemedi.')));
        }
    }
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    _controller.clear();
    final optimistic = ChatMessage(
      id: 'tmp-${DateTime.now().microsecondsSinceEpoch}',
      fromMe: true,
      text: text,
    );
    setState(() => _messages = [..._messages, optimistic]);
    try {
      final saved =
          await widget.repository.sendGroupMessage(widget.group.id, text);
      if (!mounted) return;
      setState(() {
        _messages = [
          ..._messages.where((m) => m.id != optimistic.id),
          saved,
        ];
      });
    } on ContentModerationException catch (e) {
      if (!mounted) return;
      setState(() =>
          _messages = _messages.where((m) => m.id != optimistic.id).toList());
      showTopNotice(context, message: e.reason, kind: TopNoticeKind.error);
    } catch (_) {
      if (!mounted) return;
      setState(() =>
          _messages = _messages.where((m) => m.id != optimistic.id).toList());
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('chat_send_failed'))));
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final members = widget.group.members.join(', ');
    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        leading: const CampusBackButton(),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.group.name, overflow: TextOverflow.ellipsis),
            if (members.isNotEmpty)
              Text(members,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 11,
                      color: Theme.of(context).colorScheme.onSurfaceVariant)),
          ],
        ),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
          PopupMenuButton<String>(
            onSelected: _onGroupMenu,
            itemBuilder: (_) => [
              PopupMenuItem(
                  value: 'mute',
                  child: Text(_muted ? 'Sessizi kaldır' : 'Sessize al')),
              PopupMenuItem(
                  value: 'archive',
                  child: Text(_archived ? 'Arşivden çıkar' : 'Arşive al')),
              PopupMenuItem(
                  value: 'leave', child: Text(AppLocale.of(context).t('cg_leave_group'))),
              PopupMenuItem(
                  value: 'report', child: Text(AppLocale.of(context).t('act_report'))),
            ],
          ),
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
                              if (!m.fromMe &&
                                  (m.sender ?? '').isNotEmpty)
                                Padding(
                                  padding: const EdgeInsets.only(
                                      left: 4, bottom: 2),
                                  child: Text(m.sender!,
                                      style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.w700,
                                          color: Theme.of(context)
                                              .colorScheme
                                              .onSurfaceVariant)),
                                ),
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
