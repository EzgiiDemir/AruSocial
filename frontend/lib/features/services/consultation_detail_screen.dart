import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

class ConsultationDetailScreen extends StatefulWidget {
  final CampusRepository repository;
  final String consultationId;
  final ConsultationOffering? initial;

  const ConsultationDetailScreen({
    super.key,
    required this.repository,
    required this.consultationId,
    this.initial,
  });

  @override
  State<ConsultationDetailScreen> createState() =>
      _ConsultationDetailScreenState();
}

class _ConsultationDetailScreenState extends State<ConsultationDetailScreen> {
  ConsultationOffering? _item;
  bool _loading = true;
  bool _applying = false;
  String? _error;
  String? _success;
  final _notesC = TextEditingController();

  @override
  void initState() {
    super.initState();
    _item = widget.initial;
    _load();
  }

  @override
  void dispose() {
    _notesC.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final item = await widget.repository.getConsultation(widget.consultationId);
      if (!mounted) return;
      setState(() {
        _item = item;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _apply() async {
    setState(() {
      _applying = true;
      _success = null;
    });
    try {
      await widget.repository.applyToConsultation(
        widget.consultationId,
        notes: _notesC.text.trim().isEmpty ? null : _notesC.text.trim(),
      );
      if (!mounted) return;
      setState(() {
        _applying = false;
        _success = 'Başvurun alındı.';
      });
    } on ApiClientException catch (e) {
      if (!mounted) return;
      setState(() => _applying = false);
      final msg = e.code == 'APPLICATION_EXISTS'
          ? 'Bu danışmanlığa zaten başvurdunuz.'
          : e.message;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _item;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.title ?? 'Danışmanlık'),
        leading: const CampusBackButton(),
      ),
      body: _loading && c == null
          ? const Center(child: CircularProgressIndicator())
          : _error != null && c == null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 40),
                  children: [
                    Text(c!.title,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 22)),
                    const SizedBox(height: 16),
                    _block('Amaç', c.purpose),
                    _block('Kimler için uygun', c.audience),
                    _block('İçerik', c.content),
                    _block('Neler kazandırır', c.outcomes),
                    _block('Süre', c.duration),
                    _block('Uygulanma şekli', c.format),
                    _block('Gerekli koşullar', c.requirements),
                    _block('Danışman', c.counselorName),
                    TextField(
                      controller: _notesC,
                      maxLines: 3,
                      decoration: const InputDecoration(
                        labelText: 'Not (isteğe bağlı)',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: _applying ? null : _apply,
                      child: Text(_applying ? 'Gönderiliyor…' : 'Başvur'),
                    ),
                    if (_success != null) ...[
                      const SizedBox(height: 10),
                      Text(_success!,
                          style: const TextStyle(color: ArucadColors.success)),
                    ],
                  ],
                ),
    );
  }

  Widget _block(String title, String? body) {
    if (body == null || body.trim().isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(body, style: const TextStyle(height: 1.4)),
        ],
      ),
    );
  }
}
