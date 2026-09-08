import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_taxonomy.dart';
import 'package:arucad_campus_prototype/core/config/content_categories.dart';
import 'package:arucad_campus_prototype/core/l10n/admin_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/event_participant.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/admin_settings_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/auth/access_denied_screen.dart';
import 'package:arucad_campus_prototype/features/trainer/trainer_campus_ops_tab.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart'
    show categoryAccent, BrandMark, EmailStatusRow;
import 'package:arucad_campus_prototype/features/widgets/language_toggle.dart';

/// One entry in the Trainer Panel's sidebar — same WordPress-style grouped
/// nav the Admin Panel uses, scaled down to what a department head's
/// account can actually do: everything here maps to a real `/trainer/*`
/// route enforced server-side by the `department-head` middleware, never a
/// screen added ahead of the backend behind it.
enum _TrainerSection { dashboard, events, applications, campusOps, roster, department }

/// A department head/teacher/staff member's own portal — a real, separate
/// entry point (see main.dart's `startInTrainerMode`, reached at the web
/// `/trainer` URL), not a hidden corner of the Admin Panel. Every write
/// goes through `/trainer/*` REST routes, which the backend's
/// `department-head` middleware confines to the caller's own
/// `StaffProfile` — nothing here can even attempt to touch another
/// department's data.
class TrainerPanelScreen extends StatefulWidget {
  final CampusRepository repository;
  final CampusUser user;
  final UserRole role;
  final VoidCallback onLogout;

  const TrainerPanelScreen({
    super.key,
    required this.repository,
    required this.user,
    required this.role,
    required this.onLogout,
  });

  @override
  State<TrainerPanelScreen> createState() => _TrainerPanelScreenState();
}

class _TrainerPanelScreenState extends State<TrainerPanelScreen> {
  _TrainerSection _section = _TrainerSection.dashboard;
  final _scaffoldKey = GlobalKey<ScaffoldState>();
  static const _wideBreakpoint = 900.0;
  AdminLanguage _language = AdminLanguage.tr;

  @override
  void initState() {
    super.initState();
    AdminSettingsStore.language().then((code) {
      if (mounted) setState(() => _language = adminLanguageFromCode(code));
    });
  }

  void _changeLanguage(AdminLanguage language) {
    setState(() => _language = language);
    AdminSettingsStore.setLanguage(adminLanguageCode(language));
  }

  String _titleFor(_TrainerSection s, AdminStrings strings) => switch (s) {
        _TrainerSection.dashboard => strings.t('trainer_nav_dashboard'),
        _TrainerSection.events => strings.t('trainer_nav_events'),
        _TrainerSection.applications => strings.t('trainer_nav_applications'),
        _TrainerSection.campusOps => strings.t('trainer_nav_campus_ops'),
        _TrainerSection.roster => strings.t('trainer_nav_roster'),
        _TrainerSection.department => strings.t('trainer_nav_department'),
      };

  Widget _bodyFor(_TrainerSection s) => switch (s) {
        _TrainerSection.dashboard => _TrainerDashboardTab(
            repository: widget.repository,
            onNavigate: (s) => setState(() => _section = s),
          ),
        _TrainerSection.events => _EventsTab(repository: widget.repository, user: widget.user),
        _TrainerSection.applications => _ApplicationsTab(repository: widget.repository),
        _TrainerSection.campusOps => TrainerCampusOpsTab(repository: widget.repository),
        _TrainerSection.roster => _RosterTab(repository: widget.repository),
        _TrainerSection.department => _DepartmentTab(repository: widget.repository, user: widget.user),
      };

  @override
  Widget build(BuildContext context) {
    if (!widget.role.canOpenTrainerPanel) {
      return AccessDeniedScreen(
        title: 'Eğitmen paneli',
        message: 'Bu hesap eğitmen paneline erişemez.',
        onLogout: widget.onLogout,
      );
    }
    final wide = MediaQuery.of(context).size.width >= _wideBreakpoint;
    final strings = AdminStrings(_language);
    final sidebar = _TrainerSidebar(
      selected: _section,
      onSelect: (s) {
        setState(() => _section = s);
        if (!wide) Navigator.of(context).maybePop();
      },
    );

    return AdminLocale(
      language: _language,
      child: Scaffold(
      key: _scaffoldKey,
      drawer: wide ? null : Drawer(child: sidebar),
      body: Column(children: [
        _TrainerTopBar(
          user: widget.user,
          title: _titleFor(_section, strings),
          language: _language,
          onLanguageChanged: _changeLanguage,
          onLogout: widget.onLogout,
          onOpenDrawer: wide ? null : () => _scaffoldKey.currentState?.openDrawer(),
        ),
        Expanded(
          child: Row(children: [
            if (wide)
              SizedBox(width: 260, child: Material(color: ArucadColors.paper, child: sidebar)),
            if (wide) const VerticalDivider(width: 1),
            Expanded(child: _bodyFor(_section)),
          ]),
        ),
      ]),
      ),
    );
  }
}

class _TrainerTopBar extends StatelessWidget {
  final CampusUser user;
  final String title;
  final AdminLanguage language;
  final ValueChanged<AdminLanguage> onLanguageChanged;
  final VoidCallback onLogout;
  final VoidCallback? onOpenDrawer;

  const _TrainerTopBar({
    required this.user,
    required this.title,
    required this.language,
    required this.onLanguageChanged,
    required this.onLogout,
    this.onOpenDrawer,
  });

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final showRoleChip = width >= 480;
    final strings = AdminLocale.of(context);
    return Container(
      color: ArucadColors.navy,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: SafeArea(
        bottom: false,
        child: Row(children: [
          if (onOpenDrawer != null) ...[
            IconButton(onPressed: onOpenDrawer, icon: const Icon(Icons.menu, color: Colors.white)),
            const SizedBox(width: 4),
          ] else ...[
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration:
                  BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
              child: const BrandMark(height: 22),
            ),
            const SizedBox(width: 14),
            Container(width: 1, height: 22, color: Colors.white24),
            const SizedBox(width: 14),
          ],
          Expanded(
            child: Text(title,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    color: Colors.white, fontWeight: FontWeight.w900, fontSize: 17)),
          ),
          if (showRoleChip) ...[
            const SizedBox(width: 10),
            Flexible(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(999)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.shield_outlined, size: 15, color: Colors.white),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text('${user.name} · ${strings.t('trainer_role_chip')}',
                        overflow: TextOverflow.ellipsis,
                        maxLines: 1,
                        style: const TextStyle(
                            color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12)),
                  ),
                ]),
              ),
            ),
          ],
          const SizedBox(width: 8),
          LanguageToggle(
            code: adminLanguageCode(language),
            onDark: true,
            onChanged: (code) => onLanguageChanged(adminLanguageFromCode(code)),
          ),
          const SizedBox(width: 6),
          IconButton(
            onPressed: onLogout,
            icon: const Icon(Icons.logout, color: Colors.white70),
            tooltip: strings.t('admin_logout'),
          ),
        ]),
      ),
    );
  }
}

class _TrainerSidebar extends StatelessWidget {
  final _TrainerSection selected;
  final ValueChanged<_TrainerSection> onSelect;
  const _TrainerSidebar({required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [
        _NavTile(
            icon: Icons.dashboard_outlined,
            label: strings.t('trainer_nav_dashboard'),
            section: _TrainerSection.dashboard,
            selected: selected,
            onSelect: onSelect),
        _SidebarGroupLabel(strings.t('trainer_section_dept')),
        _NavTile(
            icon: Icons.event_outlined,
            label: strings.t('trainer_nav_events'),
            section: _TrainerSection.events,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.assignment_turned_in_outlined,
            label: strings.t('trainer_nav_applications'),
            section: _TrainerSection.applications,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.work_outline,
            label: strings.t('trainer_nav_campus_ops'),
            section: _TrainerSection.campusOps,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.groups_outlined,
            label: strings.t('trainer_nav_roster'),
            section: _TrainerSection.roster,
            selected: selected,
            onSelect: onSelect),
        _SidebarGroupLabel(strings.t('trainer_section_account')),
        _NavTile(
            icon: Icons.badge_outlined,
            label: strings.t('trainer_nav_department'),
            section: _TrainerSection.department,
            selected: selected,
            onSelect: onSelect),
      ],
    );
  }
}

class _SidebarGroupLabel extends StatelessWidget {
  final String label;
  const _SidebarGroupLabel(this.label);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 18, 12, 6),
        child: Text(label,
            style: const TextStyle(
                color: ArucadColors.muted,
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: .6)),
      );
}

class _NavTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final _TrainerSection section;
  final _TrainerSection selected;
  final ValueChanged<_TrainerSection> onSelect;

  const _NavTile({
    required this.icon,
    required this.label,
    required this.section,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final isSelected = section == selected;
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(10)),
      child: ListTile(
        dense: true,
        tileColor: Colors.transparent,
        selectedTileColor: ArucadColors.primary.withValues(alpha: .12),
        selected: isSelected,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        leading:
            Icon(icon, size: 20, color: isSelected ? ArucadColors.primary : ArucadColors.muted),
        title: Text(label,
            style: TextStyle(
                fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                fontSize: 13.5,
                color: isSelected ? ArucadColors.primary : null)),
        onTap: () => onSelect(section),
      ),
    );
  }
}

/// Real KPI overview assembled client-side from the three Trainer Panel
/// data sources (events/applications/roster) — no separate aggregate
/// endpoint needed since each of these is already a small, department-
/// scoped list.
class _TrainerDashboardTab extends StatefulWidget {
  final CampusRepository repository;
  final ValueChanged<_TrainerSection> onNavigate;
  const _TrainerDashboardTab({required this.repository, required this.onNavigate});

  @override
  State<_TrainerDashboardTab> createState() => _TrainerDashboardTabState();
}

class _TrainerDashboardTabState extends State<_TrainerDashboardTab> {
  late Future<
      ({
        List<CampusEvent> events,
        List<ParticipationApplication> applications,
        List<StaffProfile> roster
      })> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<
      ({
        List<CampusEvent> events,
        List<ParticipationApplication> applications,
        List<StaffProfile> roster
      })> _load() async {
    final results = await Future.wait([
      widget.repository.getTrainerEvents(),
      widget.repository.getTrainerApplications(),
      widget.repository.getTrainerRoster(),
    ]);
    return (
      events: results[0] as List<CampusEvent>,
      applications: results[1] as List<ParticipationApplication>,
      roster: results[2] as List<StaffProfile>,
    );
  }

  void _reload() => setState(() => _future = _load());

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= 700;
    return FutureBuilder(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) {
          return Center(child: Text('${AdminLocale.of(context).t('trainer_load_failed')}: ${snap.error}'));
        }
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final data = snap.data!;
        final now = DateTime.now();
        final upcoming = data.events
            .where((e) => e.eventDate != null && !e.eventDate!.isBefore(DateTime(now.year, now.month, now.day)))
            .toList()
          ..sort((a, b) => a.eventDate!.compareTo(b.eventDate!));

        final strings = AdminLocale.of(context);
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(strings.t('trainer_dash_summary'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: wide ? 4 : 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.35,
                children: [
                  _StatCard(
                      label: strings.t('trainer_dash_my_events'),
                      value: '${data.events.length}',
                      icon: Icons.event_outlined,
                      onTap: () => widget.onNavigate(_TrainerSection.events)),
                  _StatCard(
                      label: strings.t('trainer_dash_pending_apps'),
                      value: '${data.applications.length}',
                      icon: Icons.assignment_turned_in_outlined,
                      highlight: data.applications.isNotEmpty,
                      onTap: () => widget.onNavigate(_TrainerSection.applications)),
                  _StatCard(
                      label: strings.t('trainer_dash_team'),
                      value: '${data.roster.length}',
                      icon: Icons.groups_outlined,
                      onTap: () => widget.onNavigate(_TrainerSection.roster)),
                  _StatCard(
                      label: strings.t('trainer_dash_upcoming'),
                      value: '${upcoming.length}',
                      icon: Icons.upcoming_outlined,
                      onTap: () => widget.onNavigate(_TrainerSection.events)),
                ],
              ),
              const SizedBox(height: 26),
              Text(strings.t('trainer_dash_quick'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              Wrap(spacing: 10, runSpacing: 10, children: [
                _QuickAction(
                    icon: Icons.add_circle_outline,
                    label: strings.t('trainer_dash_add_event'),
                    onTap: () => widget.onNavigate(_TrainerSection.events)),
                _QuickAction(
                    icon: Icons.assignment_outlined,
                    label: '${strings.t('trainer_dash_review_apps')} (${data.applications.length})',
                    onTap: () => widget.onNavigate(_TrainerSection.applications)),
                _QuickAction(
                    icon: Icons.groups_outlined,
                    label: strings.t('trainer_dash_view_team'),
                    onTap: () => widget.onNavigate(_TrainerSection.roster)),
              ]),
              if (upcoming.isNotEmpty) ...[
                const SizedBox(height: 26),
                Text(strings.t('trainer_dash_upcoming_list'),
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                const SizedBox(height: 10),
                for (final e in upcoming.take(5))
                  Card(
                    child: ListTile(
                      leading: Icon(Icons.event_outlined, color: categoryAccent(e.category)),
                      title: Text(e.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                          '${e.category} · ${e.placeName}${e.eventDate != null ? ' · ${e.eventDate!.day}.${e.eventDate!.month}.${e.eventDate!.year}' : ''}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => widget.onNavigate(_TrainerSection.events),
                    ),
                  ),
              ],
            ],
          ),
        );
      },
    );
  }
}

class _QuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _QuickAction({required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: onTap,
        icon: Icon(icon, size: 18),
        label: Text(label),
      );
}

class _StatCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final bool highlight;
  final VoidCallback onTap;

  const _StatCard({
    required this.label,
    required this.value,
    required this.icon,
    this.highlight = false,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    // Filament-style dashboard: one calm accent, not a hash-cycled rainbow
    // per card. `highlight` stays the only colored state, for a genuine
    // pending/unresolved/failed signal.
    return Card(
      color: highlight
          ? ArucadColors.warning.withValues(alpha: .1)
          : ArucadColors.primary.withValues(alpha: .06),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.max,
            children: [
              Icon(icon,
                  color: highlight ? ArucadColors.warning : ArucadColors.primary,
                  size: 18),
              const SizedBox(height: 6),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(value,
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20, height: 1.1)),
              ),
              Text(label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11, height: 1.15)),
            ],
          ),
        ),
      ),
    );
  }
}

class _EventsTab extends StatefulWidget {
  final CampusRepository repository;
  final CampusUser user;
  const _EventsTab({required this.repository, required this.user});

  @override
  State<_EventsTab> createState() => _EventsTabState();
}

class _EventsTabState extends State<_EventsTab> {
  late Future<List<CampusEvent>> _future;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() => setState(() { _future = widget.repository.getTrainerEvents(); });

  Future<void> _edit([CampusEvent? existing]) async {
    final places = await widget.repository.getPlaces();
    if (!mounted) return;
    final titleC = TextEditingController(text: existing?.title);
    final timeC = TextEditingController(text: existing?.time ?? '14:00');
    final descC = TextEditingController(text: existing?.description);
    String? placeId = existing?.placeId ?? (places.isEmpty ? null : places.first.id);
    DateTime? eventDate = existing?.eventDate;
    var category = ContentCategories.activity.contains(existing?.category)
        ? existing?.category
        : ContentCategories.activity.first;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni etkinlik' : 'Etkinliği düzenle'),
          content: SizedBox(
            width: 420,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                TextField(controller: titleC, decoration: const InputDecoration(labelText: 'Başlık')),
                const SizedBox(height: 10),
                DropdownButtonFormField<String?>(
                  initialValue: places.any((p) => p.id == placeId) ? placeId : null,
                  decoration: const InputDecoration(labelText: 'Mekân'),
                  items: [
                    for (final p in places) DropdownMenuItem(value: p.id, child: Text(p.name)),
                  ],
                  onChanged: (v) => setDialogState(() => placeId = v),
                ),
                const SizedBox(height: 10),
                InkWell(
                  onTap: () async {
                    final now = DateTime.now();
                    final picked = await showDatePicker(
                      context: ctx,
                      initialDate: eventDate ?? now,
                      firstDate: now.subtract(const Duration(days: 30)),
                      lastDate: now.add(const Duration(days: 730)),
                    );
                    if (picked != null) setDialogState(() => eventDate = picked);
                  },
                  child: InputDecorator(
                    decoration: const InputDecoration(labelText: 'Tarih'),
                    child: Text(eventDate == null
                        ? 'Tarih seç'
                        : '${eventDate!.day.toString().padLeft(2, '0')}.${eventDate!.month.toString().padLeft(2, '0')}.${eventDate!.year}'),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(controller: timeC, decoration: const InputDecoration(labelText: 'Saat (ör. 14:00)')),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  initialValue: category,
                  decoration: const InputDecoration(labelText: 'Kategori'),
                  items: [
                    for (final c in ContentCategories.activity)
                      DropdownMenuItem(value: c, child: Text(c)),
                  ],
                  onChanged: (v) => setDialogState(() => category = v),
                ),
                const SizedBox(height: 10),
                TextField(
                    controller: descC,
                    decoration: const InputDecoration(labelText: 'Açıklama'),
                    maxLines: 3),
              ]),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Yayınla')),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty || placeId == null) return;

    final place = places.firstWhere((p) => p.id == placeId);
    try {
      await widget.repository.upsertTrainerEvent(
        CampusEvent(
          id: existing?.id ?? '',
          title: titleC.text.trim(),
          time: timeC.text.trim(),
          eventDate: eventDate,
          placeName: place.name,
          placeId: placeId,
          category: category ?? ContentCategories.activity.first,
          attendees: 0,
          xp: 20,
          description: descC.text.trim(),
        ),
        isNew: existing == null,
      );
      if (mounted) _reload();
    } on PlaceConflictException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
    }
  }

  Future<void> _delete(CampusEvent event) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Etkinlik silinsin mi?'),
        content: Text(event.title),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sil')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.repository.deleteTrainerEvent(event.id);
    if (mounted) _reload();
  }

  /// Real attendance roster ("yoklama") for this event — who actually
  /// joined, approvable one by one once they've completed the katılım
  /// formu. Same pattern as Admin's own attendance dialog.
  Future<void> _manageAttendance(CampusEvent event) async {
    var future = widget.repository.getTrainerEventParticipants(event.id);
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Katılımcılar / Yoklama'),
          content: SizedBox(
            width: 380,
            child: FutureBuilder<List<EventParticipant>>(
              future: future,
              builder: (context, snap) {
                if (!snap.hasData) {
                  return const SizedBox(height: 80, child: Center(child: CircularProgressIndicator()));
                }
                final participants = snap.data!;
                if (participants.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text('Henüz katılan yok.', style: TextStyle(color: ArucadColors.muted)),
                  );
                }
                return SizedBox(
                  height: 320,
                  child: ListView.separated(
                    shrinkWrap: true,
                    itemCount: participants.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, i) {
                      final p = participants[i];
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(p.studentName ?? p.userId ?? '—',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(p.participationTypeLabel ?? 'Belirtilmedi'),
                        trailing: p.isApproved
                            ? const Icon(Icons.check_circle, color: ArucadColors.success)
                            : !p.isFormSubmitted
                                ? const Text('Form bekleniyor',
                                    style: TextStyle(color: ArucadColors.muted, fontSize: 12.5))
                                : TextButton(
                                    onPressed: () async {
                                      await widget.repository
                                          .approveTrainerEventParticipant(event.id, p.id);
                                      setDialogState(() => future =
                                          widget.repository.getTrainerEventParticipants(event.id));
                                    },
                                    child: const Text('Onayla'),
                                  ),
                      );
                    },
                  ),
                );
              },
            ),
          ),
          actions: [
            FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Kapat')),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add),
        label: const Text('Etkinlik Ekle'),
      ),
      body: FutureBuilder<List<CampusEvent>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) {
            return Center(child: Text('${AdminLocale.of(context).t('trainer_load_failed')}: ${snap.error}'));
          }
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final items = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 90),
            children: [
              Text('Hoş geldin, ${widget.user.name}',
                  style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(
                widget.user.department?.isNotEmpty == true
                    ? '${widget.user.department} bölümü etkinlikleri — yayınladığın anda öğrencilere görünür.'
                    : 'Bölümünün etkinlikleri — yayınladığın anda öğrencilere görünür.',
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
              ),
              const SizedBox(height: 16),
              if (items.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Text('Henüz bir etkinlik yayınlamadın. Sağ alttan ekleyebilirsin.',
                      style: TextStyle(color: ArucadColors.muted)),
                ),
              for (final e in items)
                Card(
                  child: ListTile(
                    title: Text(e.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${e.category} · ${e.placeName}'
                        '${e.eventDate != null ? ' · ${e.eventDate!.day}.${e.eventDate!.month}.${e.eventDate!.year}' : ''}'
                        '${e.time.isNotEmpty ? ' ${e.time}' : ''}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(
                          icon: const Icon(Icons.people_alt_outlined),
                          tooltip: 'Katılımcılar',
                          onPressed: () => _manageAttendance(e)),
                      IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(e)),
                      IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => _delete(e)),
                    ]),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

const _applicationStatusFilters = [
  (null, 'Bekleyen'),
  ('approved', 'Onaylanan'),
  ('rejected', 'Reddedilen'),
  ('revision_required', 'Revizyon İstenen'),
];

/// Same approve/reject/revise decision space Admin's Applications tab has
/// (see `applications_staff_tab.dart`), scoped server-side to this
/// trainer's own department, with a real status filter so a department
/// head can also review past decisions, not just the pending queue.
class _ApplicationsTab extends StatefulWidget {
  final CampusRepository repository;
  const _ApplicationsTab({required this.repository});

  @override
  State<_ApplicationsTab> createState() => _ApplicationsTabState();
}

class _ApplicationsTabState extends State<_ApplicationsTab> {
  late Future<List<ParticipationApplication>> _future;
  String? _status;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _reload();
    // Same polling-based "realtime" Admin's Applications tab already uses —
    // a student submitting a detail form while a trainer has this screen
    // open should show up without the trainer having to navigate away and
    // back to force a reload.
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) _reload();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _reload() =>
      setState(() { _future = widget.repository.getTrainerApplications(status: _status); });

  String _statusLabel(String status) => switch (status) {
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'cancelled' => 'İptal edildi',
        'under_review' || 'detail_form_submitted' => 'İncelemede',
        'detail_form_pending' => 'Detay bekleniyor',
        'revision_required' => 'Revizyon istendi',
        _ => 'Beklemede',
      };

  Future<void> _decide(ParticipationApplication a, String action) async {
    if (action == 'approve') {
      await widget.repository.approveTrainerApplication(a.id);
    } else {
      final isReject = action == 'reject';
      final noteC = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(isReject ? 'Başvuruyu reddet' : 'Revizyon iste'),
          content: TextField(
            controller: noteC,
            decoration: InputDecoration(
                labelText: isReject
                    ? 'Red sebebi (öğrenciye e-posta ile iletilir) *'
                    : 'Öğrenciden ne değiştirmesi istendiği (e-posta ile iletilir) *'),
            maxLines: 3,
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: Text(isReject ? 'Reddet' : 'Revizyon İste')),
          ],
        ),
      );
      if (ok != true) return;
      final note = noteC.text.trim();
      if (note.isEmpty) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(isReject ? 'Reddetme sebebi zorunludur.' : 'Revizyon notu zorunludur.')));
        return;
      }
      if (isReject) {
        await widget.repository.rejectTrainerApplication(a.id, reviewNote: note);
      } else {
        await widget.repository.requestTrainerApplicationRevision(a.id, reviewNote: note);
      }
    }
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<ParticipationApplication>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) {
          return Center(child: Text('${AdminLocale.of(context).t('trainer_load_failed')}: ${snap.error}'));
        }
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(AdminLocale.of(context).t('trainer_apps_title'),
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(AdminLocale.of(context).t('trainer_apps_sub'),
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
              const SizedBox(height: 12),
              SizedBox(
                height: 36,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: _applicationStatusFilters.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 8),
                  itemBuilder: (context, i) {
                    final (value, label) = _applicationStatusFilters[i];
                    return ChoiceChip(
                      label: Text(label),
                      selected: _status == value,
                      onSelected: (_) => setState(() {
                        _status = value;
                        _reload();
                      }),
                    );
                  },
                ),
              ),
              const SizedBox(height: 14),
              if (items.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Text('Bu filtrede başvuru yok.', style: TextStyle(color: ArucadColors.muted)),
                ),
              for (final a in items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(a.targetLabel ?? '${a.targetType} · ${a.targetId}',
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text(
                              '${a.studentName ?? a.userId}\n${_statusLabel(a.status)}'
                              '${a.reviewNote != null && a.reviewNote!.isNotEmpty ? ': ${a.reviewNote}' : ''}'),
                          isThreeLine: true,
                          trailing: (_status == null && a.isDecidable)
                              ? Row(mainAxisSize: MainAxisSize.min, children: [
                                  IconButton(
                                      icon: const Icon(Icons.check_circle_outline, color: ArucadColors.success),
                                      tooltip: 'Onayla',
                                      onPressed: () => _decide(a, 'approve')),
                                  IconButton(
                                      icon: const Icon(Icons.edit_note_outlined, color: ArucadColors.warning),
                                      tooltip: 'Revizyon iste',
                                      onPressed: () => _decide(a, 'revise')),
                                  IconButton(
                                      icon: const Icon(Icons.cancel_outlined, color: ArucadColors.danger),
                                      tooltip: 'Reddet',
                                      onPressed: () => _decide(a, 'reject')),
                                ])
                              : null,
                        ),
                        Padding(
                          padding: const EdgeInsets.only(bottom: 6),
                          child: EmailStatusRow(app: a),
                        ),
                        if (a.awaitingStudentDetail)
                          const Padding(
                            padding: EdgeInsets.only(bottom: 8),
                            child: Text(
                              'Öğrenci detay formunu henüz tamamlamadı — onay/red bu adımdan sonra açılır.',
                              style: TextStyle(color: ArucadColors.muted, fontSize: 12.5),
                            ),
                          ),
                        if (a.formPayload.isNotEmpty) ...[
                          const Divider(height: 8),
                          for (final e in a.formPayload.entries)
                            if ('${e.value}'.trim().isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(bottom: 4),
                                child: Text('${e.key}: ${e.value}',
                                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                              ),
                        ],
                      ],
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// Read-only view of this trainer's own department colleagues — never
/// another department's roster (resolved server-side).
class _RosterTab extends StatefulWidget {
  final CampusRepository repository;
  const _RosterTab({required this.repository});

  @override
  State<_RosterTab> createState() => _RosterTabState();
}

class _RosterTabState extends State<_RosterTab> {
  late Future<List<StaffProfile>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getTrainerRoster();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<StaffProfile>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) {
          return Center(child: Text('${AdminLocale.of(context).t('trainer_load_failed')}: ${snap.error}'));
        }
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(AdminLocale.of(context).t('trainer_roster_title'),
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text(AdminLocale.of(context).t('trainer_roster_sub'),
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
            const SizedBox(height: 14),
            for (final s in items)
              Card(
                child: ListTile(
                  leading: CircleAvatar(
                    backgroundColor: ArucadColors.mist,
                    child: Text(s.name.isEmpty ? '?' : s.name.substring(0, 1),
                        style: const TextStyle(
                            fontWeight: FontWeight.w800,
                            color: ArucadColors.primary)),
                  ),
                  title: Text(s.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([
                    if (s.title != null) s.title!,
                    if (s.isDepartmentHead) 'Bölüm Başkanı',
                    if (s.email != null) s.email!,
                  ].join(' · ')),
                ),
              ),
          ],
        );
      },
    );
  }
}

/// Real, honest account info — the department/faculty this account is
/// actually scoped to (from the real ARUCAD taxonomy), and the exact
/// permission this account has, worded from `GranularPermissions` rather
/// than an invented capability list.
class _DepartmentTab extends StatelessWidget {
  final CampusRepository repository;
  final CampusUser user;
  const _DepartmentTab({required this.repository, required this.user});

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    final department = user.department;
    final faculty = department != null ? CampusTaxonomy.facultyOf(department) : null;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text(strings.t('trainer_dept_title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(strings.t('trainer_dept_sub'),
            style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              _InfoRow(icon: Icons.person_outline, label: 'Ad Soyad', value: user.name),
              _InfoRow(
                  icon: Icons.apartment_outlined,
                  label: 'Bölüm',
                  value: department?.isNotEmpty == true ? department! : 'Tanımlı değil'),
              _InfoRow(
                  icon: Icons.account_balance_outlined,
                  label: 'Fakülte',
                  value: faculty ?? (department != null ? 'İdari / Destek birimi' : 'Tanımlı değil')),
            ]),
          ),
        ),
        const SizedBox(height: 20),
        Text(strings.t('trainer_permissions'), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
        const SizedBox(height: 10),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Bu hesap "events.manageOwnDepartment" yetkisine sahip:',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 10),
              const _PermissionRow(
                  icon: Icons.event_outlined,
                  text: 'Kendi bölümün adına etkinlik oluştur, düzenle, sil — yayınladığın anda öğrencilere görünür.'),
              const _PermissionRow(
                  icon: Icons.people_alt_outlined,
                  text: 'Etkinliklerine katılan öğrencilerin yoklamasını al ve onayla.'),
              const _PermissionRow(
                  icon: Icons.assignment_turned_in_outlined,
                  text: 'Bölümüne gelen üyelik/katılım başvurularını onayla, reddet ya da revizyon iste.'),
              const _PermissionRow(
                  icon: Icons.groups_outlined,
                  text: 'Aynı bölümdeki aktif personeli görüntüle (salt okunur).'),
              const SizedBox(height: 6),
              const Divider(height: 20),
              const Text(
                'Diğer bölümlerin verisine hiçbir şekilde erişemezsin — bu, arayüzdeki bir '
                'kısıtlama değil, sunucu tarafında (department-head middleware) zorunlu kılınır.',
                style: TextStyle(color: ArucadColors.muted, fontSize: 12),
              ),
            ]),
          ),
        ),
      ],
    );
  }
}

class _PermissionRow extends StatelessWidget {
  final IconData icon;
  final String text;
  const _PermissionRow({required this.icon, required this.text});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 17, color: ArucadColors.primary),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 13, height: 1.35))),
        ]),
      );
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _InfoRow({required this.icon, required this.label, required this.value});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 18, color: ArucadColors.muted),
          const SizedBox(width: 10),
          Expanded(
            child: RichText(
              text: TextSpan(
                style: const TextStyle(color: ArucadColors.ink, fontSize: 13.5),
                children: [
                  TextSpan(text: '$label: ', style: const TextStyle(fontWeight: FontWeight.w700)),
                  TextSpan(text: value),
                ],
              ),
            ),
          ),
        ]),
      );
}
