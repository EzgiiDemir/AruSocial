import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Kampüs Hizmetleri — Discover entry for student-facing offices.
class NeedHelpScreen extends StatefulWidget {
  final CampusRepository repository;
  const NeedHelpScreen({super.key, required this.repository});

  @override
  State<NeedHelpScreen> createState() => _NeedHelpScreenState();
}

class _NeedHelpScreenState extends State<NeedHelpScreen> {
  List<CampusService> _services = const [];
  bool _loading = true;
  String _query = '';
  String? _category;
  int _visible = kPageSize;
  final _searchController = TextEditingController();
  final _searchFocus = FocusNode();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _resetVisible() => _visible = kPageSize;

  Future<void> _load() async {
    try {
      final services = await widget.repository.getServices();
      if (!mounted) return;
      setState(() {
        _services = enrichCampusServices(services);
        _loading = false;
        _visible = kPageSize;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _services = campusServices;
        _loading = false;
        _visible = kPageSize;
      });
    }
  }

  List<String> get _categories {
    final found = <String>{};
    for (final s in _services) {
      final c = s.category.trim();
      if (c.isNotEmpty) found.add(c);
    }
    final list = found.toList()..sort();
    return list;
  }

  List<CampusService> get _filtered {
    final q = _query.trim().toLowerCase();
    return _services.where((s) {
      if (_category != null && s.category != _category) return false;
      if (q.isEmpty) return true;
      return s.title.toLowerCase().contains(q) ||
          s.category.toLowerCase().contains(q) ||
          s.description.toLowerCase().contains(q);
    }).toList();
  }

  Future<void> _openFilters() async {
    final strings = AppLocale.of(context);
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
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                child: Text(strings.t('help_services_filter'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 16)),
              ),
              ListTile(
                title: Text(strings.t('category_all'),
                    style: TextStyle(
                        fontWeight: _category == null
                            ? FontWeight.w800
                            : FontWeight.w500)),
                trailing: _category == null
                    ? const Icon(Icons.check, color: ArucadColors.primary)
                    : null,
                onTap: () => Navigator.pop(ctx, ''),
              ),
              for (final cat in _categories)
                ListTile(
                  title: Text(cat,
                      style: TextStyle(
                          fontWeight: _category == cat
                              ? FontWeight.w800
                              : FontWeight.w500)),
                  trailing: _category == cat
                      ? const Icon(Icons.check, color: ArucadColors.primary)
                      : null,
                  onTap: () => Navigator.pop(ctx, cat),
                ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
    if (!mounted || selected == null) return;
    setState(() {
      _category = selected.isEmpty ? null : selected;
      _resetVisible();
    });
  }

  void _open(CampusService service) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ServiceDetailScreen(
              service: service,
              repository: widget.repository,
              applicationTargetType: 'help',
            )));
  }

  IconData _serviceIcon(CampusService service) {
    final n = '${service.id} ${service.title} ${service.category}'.toLowerCase();
    if (n.contains('pdr') || n.contains('wellbeing') || n.contains('psik')) {
      return Icons.favorite_outline_rounded;
    }
    if (n.contains('öğrenci') || n.contains('ogrenci') || n.contains('student')) {
      return Icons.school_rounded;
    }
    if (n.contains('it') || n.contains('teknik') || n.contains('tech')) {
      return Icons.computer_rounded;
    }
    if (n.contains('yurt') || n.contains('konak')) {
      return Icons.apartment_rounded;
    }
    if (n.contains('kariyer') || n.contains('career')) {
      return Icons.work_outline_rounded;
    }
    if (n.contains('uluslararası') || n.contains('international')) {
      return Icons.public_rounded;
    }
    if (n.contains('eriş') || n.contains('access')) {
      return Icons.accessible_rounded;
    }
    return Icons.support_agent_rounded;
  }

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    final filtered = _filtered;
    final shown = _visible.clamp(0, filtered.length);
    final page = filtered.take(shown).toList();

    return Scaffold(
      appBar: AppBar(
          title: Text(s.t('help_campus_services')),
          leading: const CampusBackButton()),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: CustomScrollView(
                slivers: [
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 12, 12, 8),
                      child: Row(
                        children: [
                          Expanded(
                            child: TextField(
                              controller: _searchController,
                              focusNode: _searchFocus,
                              textInputAction: TextInputAction.search,
                              onChanged: (v) => setState(() {
                                _query = v;
                                _resetVisible();
                              }),
                              decoration: InputDecoration(
                                hintText: s.t('help_services_search'),
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
                            tooltip: s.t('help_services_filter'),
                            onPressed: _openFilters,
                            icon: Badge(
                              isLabelVisible: _category != null,
                              smallSize: 8,
                              backgroundColor: ArucadColors.primary,
                              child: Icon(
                                Icons.filter_list_rounded,
                                color: _category != null
                                    ? ArucadColors.primary
                                    : ArucadColors.ink,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  if (_category != null)
                    SliverToBoxAdapter(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: InputChip(
                            label: Text(_category!),
                            deleteIcon: const Icon(Icons.close, size: 16),
                            onDeleted: () => setState(() {
                              _category = null;
                              _resetVisible();
                            }),
                            onPressed: _openFilters,
                          ),
                        ),
                      ),
                    ),
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                      child: Card(
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
                              color: ArucadColors.primary.withValues(alpha: .12),
                              borderRadius: BorderRadius.circular(14),
                            ),
                            child: const Icon(Icons.event_available_outlined,
                                color: ArucadColors.primary),
                          ),
                          title: Text(s.t('help_book_appointment'),
                              style:
                                  const TextStyle(fontWeight: FontWeight.w800)),
                          subtitle: Text(s.t('help_book_appointment_sub')),
                          trailing: Icon(Icons.chevron_right_rounded,
                              color: ArucadColors.primary.withValues(alpha: .7)),
                          onTap: () => Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => AppointmentBookingScreen(
                                    repository: widget.repository),
                              )),
                        ),
                      ),
                    ),
                  ),
                  if (filtered.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(
                        child: Text(s.t('help_services_empty'),
                            style: const TextStyle(color: ArucadColors.muted)),
                      ),
                    )
                  else
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
                      sliver: SliverList.builder(
                        itemCount: page.length + 1,
                        itemBuilder: (context, i) {
                          if (i == page.length) {
                            return LoadMoreButton(
                              shown: shown,
                              total: filtered.length,
                              itemLabel: 'hizmet',
                              onTap: () =>
                                  setState(() => _visible += kPageSize),
                            );
                          }
                          final service = page[i];
                          final accent = brandAccentAt(i);
                          return Padding(
                            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                            child: _CampusServiceTile(
                              service: service,
                              accent: accent,
                              icon: _serviceIcon(service),
                              onTap: () => _open(service),
                            ),
                          );
                        },
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

class _CampusServiceTile extends StatelessWidget {
  final CampusService service;
  final Color accent;
  final IconData icon;
  final VoidCallback onTap;

  const _CampusServiceTile({
    required this.service,
    required this.accent,
    required this.icon,
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
                child: Icon(icon, color: accent, size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(service.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 4),
                    Text(service.category,
                        style: TextStyle(
                            color: accent,
                            fontSize: 12,
                            fontWeight: FontWeight.w800)),
                    const SizedBox(height: 3),
                    Text(service.description,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 12.5)),
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
