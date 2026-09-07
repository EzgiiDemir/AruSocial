import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

class CareerOpportunityDetailScreen extends StatefulWidget {
  final CampusRepository repository;
  final String opportunityId;
  final CareerOpportunity? initial;

  const CareerOpportunityDetailScreen({
    super.key,
    required this.repository,
    required this.opportunityId,
    this.initial,
  });

  @override
  State<CareerOpportunityDetailScreen> createState() =>
      _CareerOpportunityDetailScreenState();
}

class _CareerOpportunityDetailScreenState
    extends State<CareerOpportunityDetailScreen> {
  CareerOpportunity? _item;
  CareerProfile? _profile;
  bool _loading = true;
  bool _applying = false;
  String? _error;
  String? _success;

  @override
  void initState() {
    super.initState();
    _item = widget.initial;
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final item = await widget.repository.getCareerOpportunity(widget.opportunityId);
      final profile = await widget.repository.getCareerProfile();
      if (!mounted) return;
      setState(() {
        _item = item;
        _profile = profile;
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

  Future<void> _pickCv() async {
    final file = await FilePicker.pickFile(
      type: FileType.custom,
      allowedExtensions: const ['pdf', 'doc', 'docx'],
    );
    if (file == null) return;
    final bytes = await file.readAsBytes();
    setState(() => _applying = true);
    try {
      final updated = await widget.repository
          .uploadCareerCv(bytes, fileName: file.name);
      if (!mounted) return;
      setState(() {
        _profile = updated;
        _applying = false;
      });
    } on ApiClientException catch (e) {
      if (!mounted) return;
      setState(() => _applying = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _apply() async {
    if (_profile?.hasCv != true) {
      await _pickCv();
      if (_profile?.hasCv != true) return;
    }
    setState(() {
      _applying = true;
      _success = null;
    });
    try {
      await widget.repository.applyToCareerOpportunity(widget.opportunityId);
      if (!mounted) return;
      setState(() {
        _applying = false;
        _success = 'Başvurun alındı.';
      });
    } on ApiClientException catch (e) {
      if (!mounted) return;
      setState(() => _applying = false);
      final msg = switch (e.code) {
        'APPLICATION_EXISTS' => 'Bu ilana zaten başvurdunuz.',
        'CV_REQUIRED' => 'Önce CV yükleyin.',
        _ => e.message,
      };
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = _item;
    return Scaffold(
      appBar: AppBar(
        title: Text(o?.title ?? 'İlan'),
        leading: const CampusBackButton(),
      ),
      body: _loading && o == null
          ? const Center(child: CircularProgressIndicator())
          : _error != null && o == null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 40),
                  children: [
                    Text(o!.title,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 22)),
                    const SizedBox(height: 6),
                    Text(
                      [o.organization, o.department, o.workType, o.location]
                          .where((s) => s != null && s.isNotEmpty)
                          .join(' · '),
                      style: const TextStyle(color: ArucadColors.muted),
                    ),
                    const SizedBox(height: 16),
                    _block('İş tanımı', o.description),
                    _block('Pozisyon amacı', o.purpose),
                    _block('Aranan yetkinlikler', o.skills),
                    _block('Aranan deneyim', o.experience),
                    _block('Eğitim şartları', o.education),
                    _block('Ek bilgiler', o.extraInfo),
                    if (o.deadline != null)
                      _block(
                        'Son başvuru',
                        '${o.deadline!.day}.${o.deadline!.month}.${o.deadline!.year}',
                      ),
                    if (o.postedAt != null)
                      _block(
                        'Yayın tarihi',
                        '${o.postedAt!.day}.${o.postedAt!.month}.${o.postedAt!.year}',
                      ),
                    const SizedBox(height: 12),
                    Text(
                      _profile?.hasCv == true
                          ? 'Kayıtlı CV: ${_profile!.cvFileName ?? 'yüklendi'}'
                          : 'Başvurmak için bir CV yükleyin.',
                      style: const TextStyle(fontSize: 13),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _applying ? null : _pickCv,
                      child: Text(_profile?.hasCv == true ? 'CV değiştir' : 'CV Yükle'),
                    ),
                    const SizedBox(height: 10),
                    FilledButton(
                      style: FilledButton.styleFrom(
                          backgroundColor: ArucadColors.primary,
                          foregroundColor: Colors.white),
                      onPressed: _applying ? null : _apply,
                      child: Text(_applying
                          ? 'Gönderiliyor…'
                          : 'CV’ni Gönder ve Başvur'),
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
