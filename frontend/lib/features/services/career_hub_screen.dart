import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/cv_opener.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/career_opportunity_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/consultation_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class CareerHubScreen extends StatefulWidget {
  final CampusRepository repository;
  const CareerHubScreen({super.key, required this.repository});

  @override
  State<CareerHubScreen> createState() => _CareerHubScreenState();
}

class _KindFilter {
  final String? kind;
  final String label;
  const _KindFilter(this.kind, this.label);
}

class _CareerHubScreenState extends State<CareerHubScreen> {
  static const _kindFilters = [
    _KindFilter(null, 'Tümü'),
    _KindFilter('internship', 'Staj'),
    _KindFilter('job', 'İş'),
    _KindFilter('event', 'Etkinlik'),
    _KindFilter('resource', 'Kaynak'),
  ];

  CampusService? _service;
  List<CareerOpportunity> _opportunities = [];
  List<ConsultationOffering> _consultations = [];
  CareerProfile? _profile;
  bool _loading = true;
  String? _kindFilter;
  String _query = '';
  int _visible = kPageSize;
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _resetVisible() => _visible = kPageSize;

  IconData _kindIcon(String kind) => switch (kind) {
        'internship' => Icons.school_rounded,
        'job' => Icons.work_rounded,
        'event' => Icons.event_rounded,
        'resource' => Icons.menu_book_rounded,
        _ => Icons.work_outline_rounded,
      };

  String _kindLabel(String kind) {
    for (final f in _kindFilters) {
      if (f.kind == kind) return f.label;
    }
    return kind;
  }

  List<CareerOpportunity> get _filteredOps {
    final q = _query.trim().toLowerCase();
    return _opportunities.where((o) {
      if (_kindFilter != null && o.kind != _kindFilter) return false;
      if (q.isEmpty) return true;
      return o.title.toLowerCase().contains(q) ||
          o.organization.toLowerCase().contains(q) ||
          o.kind.toLowerCase().contains(q);
    }).toList();
  }

  Future<void> _openKindFilter() async {
    final selected = await showModalBottomSheet<String?>(
      context: context,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) {
        return SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 4, 20, 8),
                child: Text('Kategori',
                    style:
                        TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              ),
              for (final f in _kindFilters)
                ListTile(
                  title: Text(f.label,
                      style: TextStyle(
                          fontWeight: _kindFilter == f.kind
                              ? FontWeight.w800
                              : FontWeight.w500)),
                  trailing: _kindFilter == f.kind
                      ? const Icon(Icons.check, color: ArucadColors.primary)
                      : null,
                  onTap: () => Navigator.pop(ctx, f.kind ?? ''),
                ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
    if (!mounted || selected == null) return;
    setState(() {
      _kindFilter = selected.isEmpty ? null : selected;
      _resetVisible();
    });
  }

  Future<void> _load() async {
    List<CampusService> services = const [];
    List<CareerOpportunity> opportunities = const [];
    List<ConsultationOffering> consultations = const [];
    CareerProfile? profile;
    try {
      services = await widget.repository.getServices();
    } catch (_) {}
    try {
      opportunities =
          (await widget.repository.getCareerOpportunitiesPage(perPage: 50)).items;
    } catch (_) {}
    try {
      consultations =
          (await widget.repository.getConsultationsPage(perPage: 50)).items;
    } catch (_) {}
    try {
      profile = await widget.repository.getCareerProfile();
    } catch (_) {}
    if (!mounted) return;
    setState(() {
      _service = resolveCampusService(services, 'career');
      _opportunities = opportunities;
      _consultations = consultations;
      _profile = profile;
      _loading = false;
      _visible = kPageSize;
    });
  }

  Future<void> _editProfile() async {
    final occupationC = TextEditingController(text: _profile?.occupation);
    final expertiseC = TextEditingController(text: _profile?.expertise);
    var lookingForInternships = _profile?.lookingForInternships ?? false;
    var lookingForJobs = _profile?.lookingForJobs ?? false;
    var profile = _profile;
    var saving = false;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Kariyer Profilim'),
          content: SizedBox(
            width: 420,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                TextField(
                    controller: occupationC,
                    decoration: const InputDecoration(labelText: 'Meslek')),
                const SizedBox(height: 10),
                TextField(
                    controller: expertiseC,
                    decoration:
                        const InputDecoration(labelText: 'Uzmanlık alanı')),
                const SizedBox(height: 10),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    profile?.hasCv == true
                        ? 'CV: ${profile!.cvFileName ?? 'yüklendi'}'
                        : 'Henüz CV yok',
                    style: const TextStyle(fontSize: 13),
                  ),
                ),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: saving
                          ? null
                          : () async {
                              final messenger = ScaffoldMessenger.of(context);
                              final file = await FilePicker.pickFile(
                                type: FileType.custom,
                                allowedExtensions: const ['pdf', 'doc', 'docx'],
                              );
                              if (file == null) return;
                              final bytes = await file.readAsBytes();
                              setDialogState(() => saving = true);
                              try {
                                profile = await widget.repository.uploadCareerCv(
                                    bytes,
                                    fileName: file.name);
                              } on ApiClientException catch (e) {
                                messenger.showSnackBar(
                                    SnackBar(content: Text(e.message)));
                              } finally {
                                setDialogState(() => saving = false);
                              }
                            },
                      child: Text(profile?.hasCv == true ? 'CV değiştir' : 'CV Yükle'),
                    ),
                  ),
                  if (profile?.hasCv == true) ...[
                    const SizedBox(width: 8),
                    IconButton(
                      tooltip: 'CV görüntüle',
                      onPressed: saving
                          ? null
                          : () async {
                              final messenger = ScaffoldMessenger.of(context);
                              try {
                                final bytes =
                                    await widget.repository.downloadOwnCareerCv();
                                if (!context.mounted) return;
                                await openDocumentBytes(
                                  bytes: bytes,
                                  fileName: profile?.cvFileName ?? 'cv.pdf',
                                );
                              } catch (e) {
                                messenger.showSnackBar(
                                    SnackBar(content: Text('CV açılamadı: $e')));
                              }
                            },
                      icon: const Icon(Icons.visibility_outlined),
                    ),
                    IconButton(
                      tooltip: 'CV sil',
                      onPressed: saving
                          ? null
                          : () async {
                              await widget.repository.deleteCareerCv();
                              setDialogState(() {
                                profile = CareerProfile(
                                  occupation: occupationC.text,
                                  expertise: expertiseC.text,
                                  lookingForInternships: lookingForInternships,
                                  lookingForJobs: lookingForJobs,
                                );
                              });
                            },
                      icon: const Icon(Icons.delete_outline),
                    ),
                  ],
                ]),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Staj arıyorum'),
                  value: lookingForInternships,
                  onChanged: (v) =>
                      setDialogState(() => lookingForInternships = v),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('İş arıyorum'),
                  value: lookingForJobs,
                  onChanged: (v) => setDialogState(() => lookingForJobs = v),
                ),
              ]),
            ),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Vazgeç')),
            FilledButton(
                style: FilledButton.styleFrom(
                    backgroundColor: ArucadColors.primary,
                    foregroundColor: Colors.white),
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    if (saved != true) return;
    final updated = await widget.repository.updateCareerProfile(
      occupation: occupationC.text.trim(),
      expertise: expertiseC.text.trim(),
      lookingForInternships: lookingForInternships,
      lookingForJobs: lookingForJobs,
    );
    if (!mounted) return;
    setState(() => _profile = updated);
  }

  @override
  Widget build(BuildContext context) {
    final service = _service;
    final filtered = _filteredOps;
    final shown = _visible.clamp(0, filtered.length);
    final page = filtered.take(shown).toList();

    return Scaffold(
      appBar: AppBar(
        title: Text('Kariyer Ofisi',
            style: Theme.of(context).textTheme.titleLarge),
        leading: const CampusBackButton(),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
                children: [
                  Text('ARUCAD Kariyer ve Mezun Ofisi',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w900,
                          )),
                  const SizedBox(height: 6),
                  Text(
                      service?.description ??
                          'İş/staj fırsatları, kariyer etkinlikleri ve bireysel danışmanlık.',
                      style: const TextStyle(
                          color: ArucadColors.muted, fontSize: 13)),
                  const SizedBox(height: 16),
                  Card(
                    color: ArucadColors.paper,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(18)),
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 6),
                      leading: Container(
                        width: 46,
                        height: 46,
                        decoration: BoxDecoration(
                          color: ArucadColors.blue.withValues(alpha: .12),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: const Icon(Icons.badge_outlined,
                            color: ArucadColors.blue),
                      ),
                      title: Text(
                        _profile?.occupation?.isNotEmpty == true
                            ? _profile!.occupation!
                            : 'Kariyer profilini tamamla',
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text([
                        if (_profile?.expertise?.isNotEmpty == true)
                          _profile!.expertise!,
                        if (_profile?.lookingForInternships == true)
                          'Staj arıyor',
                        if (_profile?.lookingForJobs == true) 'İş arıyor',
                        if (_profile?.hasCv == true) 'CV yüklendi',
                      ].join(' · ').ifEmpty('Meslek, uzmanlık ve CV ekle'),
                          style: const TextStyle(color: ArucadColors.muted)),
                      trailing: Icon(Icons.edit_outlined,
                          color: ArucadColors.primary),
                      onTap: _editProfile,
                    ),
                  ),
                  const SizedBox(height: 20),
                  const Text('Fırsatlar',
                      style:
                          TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _searchController,
                          textInputAction: TextInputAction.search,
                          onChanged: (v) => setState(() {
                            _query = v;
                            _resetVisible();
                          }),
                          decoration: InputDecoration(
                            hintText: 'Fırsat ara',
                            prefixIcon: const Icon(Icons.search_rounded),
                            filled: true,
                            fillColor: ArucadColors.mist,
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 14, vertical: 12),
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: BorderSide.none,
                            ),
                            enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: BorderSide.none,
                            ),
                            focusedBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: const BorderSide(
                                  color: ArucadColors.primary, width: 1.2),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 4),
                      IconButton(
                        tooltip: 'Filtrele',
                        onPressed: _openKindFilter,
                        icon: Badge(
                          isLabelVisible: _kindFilter != null,
                          smallSize: 8,
                          backgroundColor: ArucadColors.primary,
                          child: Icon(
                            Icons.filter_list_rounded,
                            color: _kindFilter != null
                                ? ArucadColors.primary
                                : ArucadColors.ink,
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (_kindFilter != null) ...[
                    const SizedBox(height: 8),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: InputChip(
                        label: Text(_kindLabel(_kindFilter!)),
                        deleteIcon: const Icon(Icons.close, size: 16),
                        onDeleted: () => setState(() {
                          _kindFilter = null;
                          _resetVisible();
                        }),
                        onPressed: _openKindFilter,
                      ),
                    ),
                  ],
                  const SizedBox(height: 12),
                  if (filtered.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 16),
                      child: Text('Bu kategoride şu an ilan yok.',
                          style: TextStyle(color: ArucadColors.muted)),
                    )
                  else ...[
                    for (var i = 0; i < page.length; i++)
                      Padding(
                        padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                        child: _OpportunityCard(
                          opportunity: page[i],
                          accent: brandAccentAt(i),
                          icon: _kindIcon(page[i].kind),
                          kindLabel: _kindLabel(page[i].kind),
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => CareerOpportunityDetailScreen(
                                repository: widget.repository,
                                opportunityId: page[i].id,
                                initial: page[i],
                              ),
                            ),
                          ),
                        ),
                      ),
                    LoadMoreButton(
                      shown: shown,
                      total: filtered.length,
                      itemLabel: 'fırsat',
                      onTap: () => setState(() => _visible += kPageSize),
                    ),
                  ],
                  const SizedBox(height: 24),
                  const Text('Bireysel Danışmanlık',
                      style:
                          TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 10),
                  if (_consultations.isEmpty)
                    const Text('Şu an açık danışmanlık yok.',
                        style: TextStyle(color: ArucadColors.muted))
                  else
                    for (var i = 0; i < _consultations.length; i++)
                      Padding(
                        padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                        child: _ConsultationCard(
                          consultation: _consultations[i],
                          accent: brandAccentAt(i + 1),
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => ConsultationDetailScreen(
                                repository: widget.repository,
                                consultationId: _consultations[i].id,
                                initial: _consultations[i],
                              ),
                            ),
                          ),
                        ),
                      ),
                  const SizedBox(height: 20),
                  if (service != null)
                    FilledButton.icon(
                      style: FilledButton.styleFrom(
                          backgroundColor: ArucadColors.primary,
                          foregroundColor: Colors.white),
                      onPressed: () =>
                          Navigator.of(context).push(MaterialPageRoute(
                              builder: (_) => ServiceDetailScreen(
                                  service: service,
                                  repository: widget.repository,
                                  applicationTargetType: 'help'))),
                      icon: const Icon(Icons.info_outline),
                      label: const Text('Hizmet Detayları'),
                    ),
                ],
              ),
            ),
    );
  }
}

class _OpportunityCard extends StatelessWidget {
  final CareerOpportunity opportunity;
  final Color accent;
  final IconData icon;
  final String kindLabel;
  final VoidCallback onTap;

  const _OpportunityCard({
    required this.opportunity,
    required this.accent,
    required this.icon,
    required this.kindLabel,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final o = opportunity;
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: accent, size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(o.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 4),
                    Text(kindLabel,
                        style: TextStyle(
                            color: accent,
                            fontSize: 12,
                            fontWeight: FontWeight.w800)),
                    const SizedBox(height: 3),
                    Text(
                      o.deadline != null
                          ? '${o.organization} · son ${o.deadline!.day}.${o.deadline!.month}.${o.deadline!.year}'
                          : o.organization,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          color: ArucadColors.muted, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded,
                  color: accent.withValues(alpha: .7)),
            ],
          ),
        ),
      ),
    );
  }
}

class _ConsultationCard extends StatelessWidget {
  final ConsultationOffering consultation;
  final Color accent;
  final VoidCallback onTap;

  const _ConsultationCard({
    required this.consultation,
    required this.accent,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(Icons.support_agent_rounded,
                    color: accent, size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(consultation.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 15)),
              ),
              FilledButton(
                onPressed: onTap,
                style: FilledButton.styleFrom(
                  backgroundColor: accent,
                  foregroundColor: onAccent(accent),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
                child: const Text('Başvur',
                    style: TextStyle(fontWeight: FontWeight.w800)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

extension _IfEmpty on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}
