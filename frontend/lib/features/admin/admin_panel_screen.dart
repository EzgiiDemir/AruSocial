import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart' show slugify;
import 'package:arucad_campus_prototype/core/l10n/admin_strings.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/models/admin_stats.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/email_log.dart';
import 'package:arucad_campus_prototype/core/models/event_participant.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/admin_settings_store.dart';
import 'package:arucad_campus_prototype/core/services/audit_log_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/role_assignment_store.dart';
import 'package:arucad_campus_prototype/core/services/site_settings_store.dart';
import 'package:arucad_campus_prototype/core/services/wordpress_data_source.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/admin/content_blocks/content_block_editor.dart';
import 'package:arucad_campus_prototype/features/admin/media/media_library_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// The real "admin panel" this prototype can actually deliver: content
/// (events/clubs/sports/services/food) and moderation, editable at runtime
/// through `CampusRepository`. Rest mode writes food venues to the Laravel
/// catalog; Mock mode still uses on-device `AdminContentStore` so
/// `USE_REST_API=false` stays offline. A real product would run this as a
/// separate web app (`admin.sociallife.arucad.edu.tr`) with real Entra
/// roles behind it; this screen is the honest, buildable version of that
/// idea inside the same Flutter client, gated by
/// `UserRole.canManageContent/canModerate`.
class AdminPanelScreen extends StatefulWidget {
  final CampusRepository repository;
  final UserRole role;
  final CampusUser user;
  final VoidCallback onLogout;

  const AdminPanelScreen({
    super.key,
    required this.repository,
    required this.role,
    required this.user,
    required this.onLogout,
  });

  @override
  State<AdminPanelScreen> createState() => _AdminPanelScreenState();
}

/// One entry in the sidebar — grouped under a section header (`PLATFORMLAR`,
/// `SİSTEM`, ...) exactly like a WordPress-style CMS nav, but every entry
/// here maps to a screen that's actually real — nothing is added to the
/// sidebar before the screen behind it does something genuine.
enum _AdminSection {
  dashboard,
  stats,
  events,
  pendingActivities,
  clubs,
  sports,
  services,
  food,
  directory,
  pages,
  media,
  surveys,
  academicYears,
  emailLog,
  users,
  moderation,
  activityLog,
  siteSettings,
}

class _AdminPanelScreenState extends State<AdminPanelScreen> {
  _AdminSection _section = _AdminSection.dashboard;
  final _scaffoldKey = GlobalKey<ScaffoldState>();
  AdminLanguage _language = AdminLanguage.tr;

  static const _wideBreakpoint = 900.0;

  @override
  void initState() {
    super.initState();
    AdminSettingsStore.language().then((code) {
      if (mounted) setState(() => _language = adminLanguageFromCode(code));
    });
  }

  void _changeLanguage(AdminLanguage language) {
    setState(() => _language = language);
    AdminSettingsStore.setLanguage(language == AdminLanguage.en ? 'EN' : 'TR');
  }

  String _titleFor(_AdminSection section, AdminStrings strings) => switch (section) {
        _AdminSection.dashboard => strings.t('admin_nav_dashboard'),
        _AdminSection.stats => strings.t('admin_nav_stats'),
        _AdminSection.events => strings.t('admin_nav_events'),
        _AdminSection.pendingActivities => strings.t('admin_nav_pending_activities'),
        _AdminSection.clubs => strings.t('admin_nav_clubs'),
        _AdminSection.sports => strings.t('admin_nav_sports'),
        _AdminSection.services => strings.t('admin_nav_services'),
        _AdminSection.food => strings.t('admin_nav_food'),
        _AdminSection.directory => strings.t('admin_nav_directory'),
        _AdminSection.pages => strings.t('admin_nav_pages'),
        _AdminSection.media => strings.t('admin_nav_media'),
        _AdminSection.surveys => strings.t('admin_nav_surveys'),
        _AdminSection.academicYears => strings.t('admin_nav_academic_years'),
        _AdminSection.emailLog => strings.t('admin_nav_email_log'),
        _AdminSection.users => strings.t('admin_nav_users'),
        _AdminSection.moderation => strings.t('admin_nav_moderation'),
        _AdminSection.activityLog => strings.t('admin_nav_activity_log'),
        _AdminSection.siteSettings => strings.t('admin_nav_site_settings'),
      };

  Widget _bodyFor(_AdminSection section) => switch (section) {
        _AdminSection.dashboard => _DashboardTab(
            repository: widget.repository,
            role: widget.role,
            onNavigate: (s) => setState(() => _section = s),
          ),
        _AdminSection.stats => _StatsTab(repository: widget.repository),
        _AdminSection.events =>
          _EventsTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.pendingActivities =>
          _PendingActivitiesTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.clubs =>
          _ClubsTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.sports =>
          _SportsTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.services =>
          _ServicesTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.food =>
          _FoodTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.directory =>
          _DirectoryTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.pages =>
          _PagesTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.media => MediaLibraryTab(repository: widget.repository),
        _AdminSection.surveys =>
          _SurveysTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.academicYears =>
          _AcademicYearsTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.emailLog => _EmailLogTab(repository: widget.repository),
        _AdminSection.users =>
          _UsersTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.moderation =>
          _ModerationTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.activityLog => _ActivityLogTab(repository: widget.repository),
        _AdminSection.siteSettings => _SiteSettingsTab(
            repository: widget.repository, actorName: widget.user.name),
      };

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= _wideBreakpoint;
    final strings = AdminStrings(_language);
    final sidebar = _AdminSidebar(
      role: widget.role,
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
          _AdminTopBar(
            user: widget.user,
            role: widget.role,
            title: _titleFor(_section, strings),
            onLogout: widget.onLogout,
            onOpenDrawer: wide ? null : () => _scaffoldKey.currentState?.openDrawer(),
            language: _language,
            onLanguageChanged: _changeLanguage,
          ),
          Expanded(
            child: Row(children: [
              if (wide)
                SizedBox(
                  width: 260,
                  child: Material(color: ArucadColors.paper, child: sidebar),
                ),
              if (wide) const VerticalDivider(width: 1),
              Expanded(child: _bodyFor(_section)),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// The always-visible top bar: ARUCAD wordmark on the left (or a menu
/// button on narrow screens), the current section's title, and — mirroring
/// Home's header exactly, per instruction — a role chip and a real logout
/// icon on the right.
class _AdminTopBar extends StatelessWidget {
  final CampusUser user;
  final UserRole role;
  final String title;
  final VoidCallback onLogout;
  final VoidCallback? onOpenDrawer;
  final AdminLanguage language;
  final ValueChanged<AdminLanguage> onLanguageChanged;

  const _AdminTopBar({
    required this.user,
    required this.role,
    required this.title,
    required this.onLogout,
    this.onOpenDrawer,
    required this.language,
    required this.onLanguageChanged,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    final width = MediaQuery.sizeOf(context).width;
    // The role chip is the least essential piece here (nice-to-have context,
    // not navigation) — on a narrow phone the fixed-width language toggle +
    // logout icon + chip padding together left too little room for it and
    // the title, so the row overflowed by a few pixels. Dropping the chip
    // below ~480px keeps everything else at full, uncramped size.
    final showRoleChip = width >= 480;
    return Container(
      color: ArucadColors.navy,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: SafeArea(
        bottom: false,
        child: Row(children: [
          if (onOpenDrawer != null) ...[
            IconButton(
              onPressed: onOpenDrawer,
              icon: const Icon(Icons.menu, color: Colors.white),
            ),
            const SizedBox(width: 4),
          ] else ...[
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                  color: Colors.white, borderRadius: BorderRadius.circular(8)),
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
          const SizedBox(width: 10),
          _LanguageToggle(language: language, onChanged: onLanguageChanged),
          if (showRoleChip) ...[
            const SizedBox(width: 10),
            Flexible(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .1),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.shield_outlined, size: 15, color: Colors.white),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text('${user.name} · ${role.label}',
                        overflow: TextOverflow.ellipsis,
                        maxLines: 1,
                        style: const TextStyle(
                            color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12)),
                  ),
                ]),
              ),
            ),
          ],
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

/// Compact TR/EN segmented control for the admin panel's own language,
/// styled to sit naturally in the navy top bar next to the role chip.
class _LanguageToggle extends StatelessWidget {
  final AdminLanguage language;
  final ValueChanged<AdminLanguage> onChanged;
  const _LanguageToggle({required this.language, required this.onChanged});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(999),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          _LanguageOption(
              label: 'TR', selected: language == AdminLanguage.tr, onTap: () => onChanged(AdminLanguage.tr)),
          _LanguageOption(
              label: 'EN', selected: language == AdminLanguage.en, onTap: () => onChanged(AdminLanguage.en)),
        ]),
      );
}

class _LanguageOption extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;
  const _LanguageOption({required this.label, required this.selected, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
          decoration: BoxDecoration(
            color: selected ? Colors.white : Colors.transparent,
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(label,
              style: TextStyle(
                  color: selected ? ArucadColors.navy : Colors.white70,
                  fontWeight: FontWeight.w800,
                  fontSize: 12)),
        ),
      );
}

class _AdminSidebar extends StatelessWidget {
  final UserRole role;
  final _AdminSection selected;
  final ValueChanged<_AdminSection> onSelect;

  const _AdminSidebar({required this.role, required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [
        _NavTile(
          icon: Icons.dashboard_outlined,
          label: strings.t('admin_nav_dashboard'),
          section: _AdminSection.dashboard,
          selected: selected,
          onSelect: onSelect,
        ),
        _NavTile(
          icon: Icons.query_stats_outlined,
          label: strings.t('admin_nav_stats'),
          section: _AdminSection.stats,
          selected: selected,
          onSelect: onSelect,
        ),
        _SidebarGroupLabel(strings.t('admin_section_platforms')),
        _NavTile(
            icon: Icons.event_outlined,
            label: strings.t('admin_nav_events'),
            section: _AdminSection.events,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.pending_actions_outlined,
            label: strings.t('admin_nav_pending_activities'),
            section: _AdminSection.pendingActivities,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.groups_outlined,
            label: strings.t('admin_nav_clubs'),
            section: _AdminSection.clubs,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.sports_outlined,
            label: strings.t('admin_nav_sports'),
            section: _AdminSection.sports,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.support_agent_outlined,
            label: strings.t('admin_nav_services'),
            section: _AdminSection.services,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.restaurant_outlined,
            label: strings.t('admin_nav_food'),
            section: _AdminSection.food,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.meeting_room_outlined,
            label: strings.t('admin_nav_directory'),
            section: _AdminSection.directory,
            selected: selected,
            onSelect: onSelect),
        _SidebarGroupLabel(strings.t('admin_section_content')),
        _NavTile(
            icon: Icons.article_outlined,
            label: strings.t('admin_nav_pages'),
            section: _AdminSection.pages,
            selected: selected,
            onSelect: onSelect),
        _SidebarGroupLabel(strings.t('admin_section_media')),
        _NavTile(
            icon: Icons.photo_library_outlined,
            label: strings.t('admin_nav_media'),
            section: _AdminSection.media,
            selected: selected,
            onSelect: onSelect),
        _SidebarGroupLabel(strings.t('admin_section_engagement')),
        _NavTile(
            icon: Icons.poll_outlined,
            label: strings.t('admin_nav_surveys'),
            section: _AdminSection.surveys,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.calendar_today_outlined,
            label: strings.t('admin_nav_academic_years'),
            section: _AdminSection.academicYears,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.mail_outline,
            label: strings.t('admin_nav_email_log'),
            section: _AdminSection.emailLog,
            selected: selected,
            onSelect: onSelect),
        if (role.canModerate || role.canManageSiteSettings) ...[
          _SidebarGroupLabel(strings.t('admin_section_system')),
          if (role.canModerate)
            _NavTile(
                icon: Icons.flag_outlined,
                label: strings.t('admin_nav_moderation'),
                section: _AdminSection.moderation,
                selected: selected,
                onSelect: onSelect),
          if (role.canModerate || role.canManageSiteSettings)
            _NavTile(
                icon: Icons.history_outlined,
                label: strings.t('admin_nav_activity_log'),
                section: _AdminSection.activityLog,
                selected: selected,
                onSelect: onSelect),
          if (role.canManageSiteSettings)
            _NavTile(
                icon: Icons.admin_panel_settings_outlined,
                label: strings.t('admin_nav_users'),
                section: _AdminSection.users,
                selected: selected,
                onSelect: onSelect),
          if (role.canManageSiteSettings)
            _NavTile(
                icon: Icons.settings_outlined,
                label: strings.t('admin_nav_site_settings'),
                section: _AdminSection.siteSettings,
                selected: selected,
                onSelect: onSelect),
        ],
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
  final _AdminSection section;
  final _AdminSection selected;
  final ValueChanged<_AdminSection> onSelect;

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
        leading: Icon(icon,
            size: 20, color: isSelected ? ArucadColors.primary : ArucadColors.muted),
        title: Text(label,
            style: TextStyle(
                fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                fontSize: 13.5,
                color: isSelected ? ArucadColors.primary : ArucadColors.ink)),
        onTap: () => onSelect(section),
      ),
    );
  }
}

/// A real management-center landing page — every number here is computed
/// live from the same stores the other tabs read/write, not invented.
class _DashboardTab extends StatefulWidget {
  final CampusRepository repository;
  final UserRole role;
  final ValueChanged<_AdminSection> onNavigate;
  const _DashboardTab({required this.repository, required this.role, required this.onNavigate});

  @override
  State<_DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<_DashboardTab> {
  late Future<_DashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_DashboardData> _load() async {
    final results = await Future.wait([
      widget.repository.getEvents(includeUnpublished: true),
      widget.repository.getClubs(),
      widget.repository.getSports(),
      widget.repository.getServices(),
      widget.repository.getFoodVenues(),
      widget.repository.getDirectoryEntries(),
      widget.repository.getReports(),
      widget.repository.getMedia(),
      widget.repository.getAuditLog(),
    ]);
    final events = results[0] as List<CampusEvent>;
    final reports = results[6] as List<ModerationReport>;
    return _DashboardData(
      events: events.length,
      draftEvents: events.where((e) => e.draft).length,
      clubs: (results[1] as List<CampusClub>).length,
      sports: (results[2] as List<CampusSport>).length,
      services: (results[3] as List<CampusService>).length,
      foodVenues: (results[4] as List<CampusFoodVenue>).length,
      directoryEntries: (results[5] as List).length,
      recentActivity: (results[8] as List<AuditLogEntry>).take(5).toList(),
      pendingReports: reports.where((r) => r.action == null).length,
      mediaItems: (results[7] as List).length,
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<_DashboardData>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final d = snap.data!;
          final strings = AdminLocale.of(context);
          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(strings.t('admin_dash_quick_actions'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              Wrap(spacing: 10, runSpacing: 10, children: [
                _QuickAction(
                    icon: Icons.add_circle_outline,
                    label: strings.t('admin_dash_new_event'),
                    onTap: () => widget.onNavigate(_AdminSection.events)),
                _QuickAction(
                    icon: Icons.groups_outlined,
                    label: strings.t('admin_dash_new_club'),
                    onTap: () => widget.onNavigate(_AdminSection.clubs)),
                _QuickAction(
                    icon: Icons.restaurant_outlined,
                    label: strings.t('admin_dash_enter_menu'),
                    onTap: () => widget.onNavigate(_AdminSection.food)),
                if (widget.role.canModerate)
                  _QuickAction(
                      icon: Icons.flag_outlined,
                      label: strings.t('admin_dash_open_moderation'),
                      onTap: () => widget.onNavigate(_AdminSection.moderation)),
              ]),
              const SizedBox(height: 24),
              Text(strings.t('admin_dash_overview'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: MediaQuery.of(context).size.width >= 700 ? 4 : 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.6,
                children: [
                  _StatCard(
                      label: strings.t('admin_dash_stat_events'),
                      value: '${d.events}',
                      sub: d.draftEvents > 0
                          ? '${d.draftEvents} ${strings.t('admin_dash_stat_draft_suffix')}'
                          : null,
                      icon: Icons.event_outlined,
                      accentColor: ArucadColors.terracotta,
                      onTap: () => widget.onNavigate(_AdminSection.events)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_clubs'),
                      value: '${d.clubs}',
                      icon: Icons.groups_outlined,
                      accentColor: ArucadColors.slateBlue,
                      onTap: () => widget.onNavigate(_AdminSection.clubs)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_sports'),
                      value: '${d.sports}',
                      icon: Icons.sports_outlined,
                      accentColor: ArucadColors.sage,
                      onTap: () => widget.onNavigate(_AdminSection.sports)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_services'),
                      value: '${d.services}',
                      icon: Icons.support_agent_outlined,
                      accentColor: ArucadColors.dustyRose,
                      onTap: () => widget.onNavigate(_AdminSection.services)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_food_venues'),
                      value: '${d.foodVenues}',
                      icon: Icons.restaurant_outlined,
                      accentColor: ArucadColors.honey,
                      onTap: () => widget.onNavigate(_AdminSection.food)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_directory'),
                      value: '${d.directoryEntries}',
                      icon: Icons.meeting_room_outlined,
                      accentColor: ArucadColors.mistLilac,
                      onTap: () => widget.onNavigate(_AdminSection.directory)),
                  _StatCard(
                      label: strings.t('admin_dash_stat_media'),
                      value: '${d.mediaItems}',
                      icon: Icons.photo_library_outlined,
                      accentColor: ArucadColors.slateBlue,
                      onTap: () => widget.onNavigate(_AdminSection.media)),
                  if (widget.role.canModerate)
                    _StatCard(
                        label: strings.t('admin_dash_stat_pending_moderation'),
                        value: '${d.pendingReports}',
                        icon: Icons.flag_outlined,
                        highlight: d.pendingReports > 0,
                        onTap: () => widget.onNavigate(_AdminSection.moderation)),
                ],
              ),
              if (d.recentActivity.isNotEmpty) ...[
                const SizedBox(height: 24),
                Row(children: [
                  Expanded(
                    child: Text(strings.t('admin_dash_recent_activity'),
                        style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                  ),
                  TextButton(
                    onPressed: () => widget.onNavigate(_AdminSection.activityLog),
                    child: Text(strings.t('admin_dash_view_all')),
                  ),
                ]),
                Card(
                  child: Column(children: [
                    for (final e in d.recentActivity)
                      ListTile(
                        dense: true,
                        leading: const Icon(Icons.history_outlined,
                            size: 18, color: ArucadColors.muted),
                        title: Text('${e.actorName} · ${e.action}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
                        subtitle: Text(
                            e.targetLabel.isEmpty ? e.targetType : e.targetLabel,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontSize: 11.5)),
                      ),
                  ]),
                ),
              ],
              if (d.pendingReports == 0 &&
                  d.events == 0 &&
                  d.clubs == 0 &&
                  d.directoryEntries == 0) ...[
                const SizedBox(height: 20),
                Text(
                  strings.t('admin_dash_local_note'),
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 12),
                ),
              ],
            ],
          );
        },
      );
}

/// Real usage statistics — every number here comes straight from
/// [CampusRepository.getAdminStats] (a live aggregate query in Rest mode,
/// the same real aggregation over in-memory state in Mock mode). Answers
/// exactly what was asked: check-ins, most-visited places, event
/// participation, content/survey/email activity — across every real field
/// and service this app has, not just events/clubs like the Dashboard tab.
class _StatsTab extends StatefulWidget {
  final CampusRepository repository;
  const _StatsTab({required this.repository});

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  late Future<AdminStats> _future;
  int _days = 14;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAdminStats(days: _days);
  }

  void _changeDays(int days) {
    setState(() {
      _days = days;
      _future = widget.repository.getAdminStats(days: _days);
    });
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return FutureBuilder<AdminStats>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final s = snap.data!;
        final wide = MediaQuery.of(context).size.width >= 700;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            // Honest first, not buried — the one number people usually ask
            // for first ("kaç kişi indirdi") is exactly the one this
            // backend can't answer, so it's surfaced plainly instead of
            // hidden or faked.
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                  color: ArucadColors.mist, borderRadius: BorderRadius.circular(14)),
              child: Row(children: [
                const Icon(Icons.info_outline, size: 18, color: ArucadColors.muted),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(s.appUsage.note,
                      style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
                ),
              ]),
            ),
            const SizedBox(height: 8),
            Text(s.userSummary.note,
                style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),

            const SizedBox(height: 22),
            _StatsSectionHeader(strings.t('admin_stats_checkins')),
            const SizedBox(height: 10),
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: wide ? 4 : 2,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _StatCard(
                    label: strings.t('admin_stats_total_checkins'),
                    value: '${s.checkins.total}',
                    icon: Icons.pin_drop_outlined,
                    accentColor: ArucadColors.terracotta,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_visible_checkins'),
                    value: '${s.checkins.visibleToOthers}',
                    icon: Icons.visibility_outlined,
                    accentColor: ArucadColors.sage,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_total_xp'),
                    value: '${s.userSummary.totalXp}',
                    icon: Icons.bolt_outlined,
                    accentColor: ArucadColors.honey,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_banned_accounts'),
                    value: '${s.userSummary.bannedAccounts}',
                    icon: Icons.block_outlined,
                    highlight: s.userSummary.bannedAccounts > 0,
                    onTap: () {}),
              ],
            ),
            const SizedBox(height: 14),
            Text(strings.t('admin_stats_most_checked_in_places'),
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
            const SizedBox(height: 8),
            _RankedBarList(
              items: [for (final p in s.checkins.mostCheckedInPlaces) (p.placeName, p.total)],
              emptyLabel: strings.t('admin_stats_no_data_yet'),
            ),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(
                child: Text(strings.t('admin_stats_checkins_by_day'),
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              ),
              for (final d in [7, 14, 30])
                Padding(
                  padding: const EdgeInsets.only(left: 6),
                  child: SelectableChip(
                    label: '${d}g',
                    selected: _days == d,
                    onSelected: (_) => _changeDays(d),
                  ),
                ),
            ]),
            const SizedBox(height: 8),
            _DailyTrendChart(data: s.checkins.byDay),

            const SizedBox(height: 26),
            _StatsSectionHeader(strings.t('admin_stats_events')),
            const SizedBox(height: 10),
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: wide ? 4 : 2,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _StatCard(
                    label: strings.t('admin_stats_total_joins'),
                    value: '${s.events.totalJoins}',
                    icon: Icons.how_to_reg_outlined,
                    accentColor: ArucadColors.slateBlue,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_forms_submitted'),
                    value: '${s.events.formsSubmitted}',
                    icon: Icons.assignment_turned_in_outlined,
                    accentColor: ArucadColors.mistLilac,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_attendance_approved'),
                    value: '${s.events.attendanceApproved}',
                    icon: Icons.verified_outlined,
                    accentColor: ArucadColors.dustyRose,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_pending_review_events'),
                    value: '${s.events.pendingReview}',
                    icon: Icons.pending_actions_outlined,
                    highlight: s.events.pendingReview > 0,
                    onTap: () {}),
              ],
            ),
            const SizedBox(height: 14),
            Text(strings.t('admin_stats_most_joined_events'),
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
            const SizedBox(height: 8),
            _RankedBarList(
              items: [for (final e in s.events.mostJoinedEvents) (e.title, e.total)],
              emptyLabel: strings.t('admin_stats_no_data_yet'),
            ),

            const SizedBox(height: 26),
            _StatsSectionHeader(strings.t('admin_stats_social')),
            const SizedBox(height: 10),
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: wide ? 4 : 2,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _StatCard(
                    label: strings.t('admin_stats_feed_posts'),
                    value: '${s.social.feedPosts}',
                    icon: Icons.dynamic_feed_outlined,
                    accentColor: ArucadColors.slateBlue,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_comments'),
                    value: '${s.social.comments}',
                    icon: Icons.chat_bubble_outline,
                    accentColor: ArucadColors.sage,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_stories'),
                    value: '${s.social.stories}',
                    icon: Icons.auto_stories_outlined,
                    accentColor: ArucadColors.honey,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_reviews'),
                    value: '${s.social.reviews}',
                    sub: s.social.reviews > 0
                        ? '⭐ ${s.social.averageRating.toStringAsFixed(1)} ${strings.t('admin_stats_average_suffix')}'
                        : null,
                    icon: Icons.star_outline,
                    accentColor: ArucadColors.terracotta,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_reports_filed'),
                    value: '${s.social.moderationReportsFiled}',
                    sub: s.social.moderationReportsUnresolved > 0
                        ? '${s.social.moderationReportsUnresolved} ${strings.t('admin_stats_unresolved_suffix')}'
                        : null,
                    icon: Icons.flag_outlined,
                    highlight: s.social.moderationReportsUnresolved > 0,
                    onTap: () {}),
              ],
            ),

            const SizedBox(height: 26),
            _StatsSectionHeader(strings.t('admin_stats_activity_by_kind')),
            const SizedBox(height: 10),
            _RankedBarList(
              items: [
                for (final k in s.activityByKind) (_activityKindLabel(k.kind, strings), k.total)
              ],
              emptyLabel: strings.t('admin_stats_no_data_yet'),
            ),

            const SizedBox(height: 26),
            _StatsSectionHeader(strings.t('admin_stats_surveys_email')),
            const SizedBox(height: 10),
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: wide ? 4 : 2,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _StatCard(
                    label: strings.t('admin_stats_total_surveys'),
                    value: '${s.surveys.total}',
                    icon: Icons.poll_outlined,
                    accentColor: ArucadColors.mistLilac,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_survey_responses'),
                    value: '${s.surveys.totalResponses}',
                    icon: Icons.how_to_vote_outlined,
                    accentColor: ArucadColors.dustyRose,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_emails_sent'),
                    value: '${s.email.sent}',
                    icon: Icons.mail_outline,
                    accentColor: ArucadColors.sage,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_emails_failed'),
                    value: '${s.email.failed}',
                    icon: Icons.error_outline,
                    highlight: s.email.failed > 0,
                    onTap: () {}),
              ],
            ),

            const SizedBox(height: 26),
            _StatsSectionHeader(strings.t('admin_stats_catalog')),
            const SizedBox(height: 10),
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: wide ? 4 : 2,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _StatCard(
                    label: strings.t('admin_dash_stat_events'),
                    value: '${s.events.total}',
                    icon: Icons.event_outlined,
                    accentColor: ArucadColors.terracotta,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_stats_places'),
                    value: '${s.catalog.places}',
                    icon: Icons.map_outlined,
                    accentColor: ArucadColors.slateBlue,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_clubs'),
                    value: '${s.catalog.clubs}',
                    icon: Icons.groups_outlined,
                    accentColor: ArucadColors.sage,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_sports'),
                    value: '${s.catalog.sports}',
                    icon: Icons.sports_outlined,
                    accentColor: ArucadColors.honey,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_services'),
                    value: '${s.catalog.services}',
                    icon: Icons.support_agent_outlined,
                    accentColor: ArucadColors.dustyRose,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_food_venues'),
                    value: '${s.catalog.foodVenues}',
                    icon: Icons.restaurant_outlined,
                    accentColor: ArucadColors.mistLilac,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_directory'),
                    value: '${s.catalog.directoryEntries}',
                    icon: Icons.meeting_room_outlined,
                    accentColor: ArucadColors.slateBlue,
                    onTap: () {}),
                _StatCard(
                    label: strings.t('admin_dash_stat_media'),
                    value: '${s.catalog.mediaItems}',
                    icon: Icons.photo_library_outlined,
                    accentColor: ArucadColors.terracotta,
                    onTap: () {}),
              ],
            ),
          ],
        );
      },
    );
  }
}

/// Maps `ActivityLog.kind`'s real, fixed value set (checkIn, eventJoin,
/// review, comment, like, report — see backend `ActivityLogger`) to a
/// readable label, falling back to the raw value for anything unmapped
/// rather than hiding it.
String _activityKindLabel(String kind, AdminStrings strings) => switch (kind) {
      'checkIn' => strings.t('admin_stats_kind_checkin'),
      'eventJoin' => strings.t('admin_stats_kind_event_join'),
      'review' => strings.t('admin_stats_kind_review'),
      'comment' => strings.t('admin_stats_kind_comment'),
      'like' => strings.t('admin_stats_kind_like'),
      'report' => strings.t('admin_stats_kind_report'),
      _ => kind,
    };

class _StatsSectionHeader extends StatelessWidget {
  final String label;
  const _StatsSectionHeader(this.label);
  @override
  Widget build(BuildContext context) =>
      Text(label, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15));
}

/// A simple, real ranked-bar breakdown (top place/event by count) — no
/// charting package involved, just a `Card` + proportional bars, matching
/// the same lightweight approach `_CampusJourneyCard` uses elsewhere.
class _RankedBarList extends StatelessWidget {
  final List<(String, int)> items;
  final String emptyLabel;
  const _RankedBarList({required this.items, required this.emptyLabel});

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(emptyLabel, style: const TextStyle(color: ArucadColors.muted)),
        ),
      );
    }
    final maxCount = items.map((e) => e.$2).fold(0, (m, v) => v > m ? v : m);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(
                        child: Text(item.$1,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
                    Text('${item.$2}',
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                  ]),
                  const SizedBox(height: 4),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      value: maxCount == 0 ? 0 : item.$2 / maxCount,
                      minHeight: 7,
                      backgroundColor: ArucadColors.mist,
                      color: ArucadColors.primary,
                    ),
                  ),
                ]),
              ),
          ],
        ),
      ),
    );
  }
}

/// A minimal real bar chart for the check-in daily trend — deliberately
/// hand-rolled (no charting package dependency) but still a real
/// proportional visualization of [data], not a placeholder.
class _DailyTrendChart extends StatelessWidget {
  final List<DailyCount> data;
  const _DailyTrendChart({required this.data});

  @override
  Widget build(BuildContext context) {
    if (data.isEmpty) {
      return const SizedBox.shrink();
    }
    final maxCount = data.map((e) => e.total).fold(0, (m, v) => v > m ? v : m);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 16, 12, 10),
        child: SizedBox(
          height: 90,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final d in data)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 2),
                    child: Tooltip(
                      message: '${d.day}: ${d.total}',
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          Container(
                            height: maxCount == 0 ? 2 : 70 * (d.total / maxCount).clamp(0.03, 1.0),
                            decoration: BoxDecoration(
                              color: ArucadColors.primary,
                              borderRadius: BorderRadius.circular(3),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(d.day.substring(5),
                              style: const TextStyle(fontSize: 8, color: ArucadColors.muted)),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DashboardData {
  final int events;
  final int draftEvents;
  final int clubs;
  final int sports;
  final int services;
  final int foodVenues;
  final int directoryEntries;
  final int pendingReports;
  final int mediaItems;
  final List<AuditLogEntry> recentActivity;
  const _DashboardData({
    required this.events,
    required this.draftEvents,
    required this.clubs,
    required this.sports,
    required this.services,
    required this.foodVenues,
    required this.directoryEntries,
    required this.pendingReports,
    required this.mediaItems,
    required this.recentActivity,
  });
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
  final String? sub;
  final IconData icon;
  final bool highlight;
  final VoidCallback onTap;
  final Color? accentColor;

  const _StatCard({
    required this.label,
    required this.value,
    this.sub,
    required this.icon,
    this.highlight = false,
    required this.onTap,
    this.accentColor,
  });

  @override
  Widget build(BuildContext context) {
    final accent = accentColor ?? ArucadColors.primary;
    return Card(
        color: highlight ? ArucadColors.warning.withValues(alpha: .1) : accent.withValues(alpha: .06),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: highlight ? ArucadColors.warning : accent, size: 20),
                const Spacer(),
                Text(value,
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
                Text(label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
                if (sub != null)
                  Text(sub!,
                      style: const TextStyle(color: ArucadColors.warning, fontSize: 10.5)),
              ],
            ),
          ),
        ),
      );
  }
}

/// Shared search + bulk-selection bar for every admin content list — real
/// client-side filtering (everything's already loaded in memory) and a
/// real multi-select bulk delete, driven entirely by the owning tab's
/// state (this widget itself holds nothing).
class _AdminListToolbar extends StatelessWidget {
  final ValueChanged<String> onQueryChanged;
  final String searchHint;
  final int selectedCount;
  final VoidCallback? onDeleteSelected;
  final VoidCallback? onCancelSelection;

  const _AdminListToolbar({
    required this.onQueryChanged,
    required this.searchHint,
    this.selectedCount = 0,
    this.onDeleteSelected,
    this.onCancelSelection,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    if (selectedCount > 0) {
      return Container(
        color: ArucadColors.primary.withValues(alpha: .08),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(children: [
          IconButton(icon: const Icon(Icons.close), onPressed: onCancelSelection),
          Expanded(
              child: Text('$selectedCount ${strings.t('admin_toolbar_selected_suffix')}',
                  style: const TextStyle(fontWeight: FontWeight.w800))),
          TextButton.icon(
            onPressed: onDeleteSelected,
            icon: const Icon(Icons.delete_outline, color: ArucadColors.danger),
            label: Text(strings.t('admin_toolbar_delete'), style: const TextStyle(color: ArucadColors.danger)),
          ),
        ]),
      );
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: TextField(
        decoration: InputDecoration(
          hintText: searchHint,
          prefixIcon: const Icon(Icons.search, size: 20),
          isDense: true,
        ),
        onChanged: onQueryChanged,
      ),
    );
  }
}

/// Shared visual shell for every add/edit dialog in this panel: caps the
/// content at a comfortable reading width on wide/desktop screens while
/// still shrinking naturally on narrow ones (the [ConstrainedBox] only
/// bounds the *max*, so a small screen's own width constraint still wins),
/// and gives every field a consistent vertical rhythm instead of fields
/// butting up against each other.
class _DialogShell extends StatelessWidget {
  final List<Widget> fields;
  const _DialogShell({required this.fields});

  static const double maxWidth = 480;
  static const double gap = 14;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: maxWidth),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (int i = 0; i < fields.length; i++) ...[
                if (i > 0) const SizedBox(height: gap),
                fields[i],
              ],
            ],
          ),
        ),
      );
}

/// A small, muted, all-caps section label with a trailing rule — used to
/// separate logically distinct groups of fields inside a dialog (e.g.
/// "Basic Info" vs "Publishing & Audience"), in the same quiet register as
/// the sidebar's own [_SidebarGroupLabel].
class _DialogSection extends StatelessWidget {
  final String label;
  const _DialogSection(this.label);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 4, bottom: 2),
        child: Row(children: [
          Text(label.toUpperCase(),
              style: const TextStyle(
                  color: ArucadColors.primary,
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  letterSpacing: .6)),
          const SizedBox(width: 8),
          const Expanded(child: Divider(color: ArucadColors.mist, thickness: 1.4, height: 1)),
        ]),
      );
}

// ---------------------------------------------------------------- Events

class _EventsTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _EventsTab({required this.repository, required this.uploaderName});

  @override
  State<_EventsTab> createState() => _EventsTabState();
}

class _EventsTabState extends State<_EventsTab> {
  late Future<List<CampusEvent>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getEvents(includeUnpublished: true);
  }

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteEvent(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'event',
        targetLabel: '${_selected.length} etkinlik (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  void _reload() =>
      setState(() => _future = widget.repository.getEvents(includeUnpublished: true));

  DateTime? _parseDate(String text) {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return null;
    return DateTime.tryParse(trimmed);
  }

  Future<void> _editEvent([CampusEvent? existing]) async {
    final strings = AdminLocale.of(context);
    final places = await widget.repository.getPlaces();
    final academicYears = await widget.repository.getAcademicYears();
    if (!mounted) return;
    final titleC = TextEditingController(text: existing?.title);
    final timeC = TextEditingController(text: existing?.time ?? '14:00');
    final placeC = TextEditingController(text: existing?.placeName);
    final categoryC = TextEditingController(text: existing?.category);
    final attendeesC = TextEditingController(text: '${existing?.attendees ?? 0}');
    final xpC = TextEditingController(text: '${existing?.xp ?? 50}');
    final audienceC = TextEditingController(text: existing?.audience ?? 'Tümü');
    final organizerC = TextEditingController(text: existing?.organizer);
    final organizerEmailC = TextEditingController(text: existing?.organizerEmail);
    final descriptionC = TextEditingController(text: existing?.description);
    final publishC =
        TextEditingController(text: existing?.publishAt?.toIso8601String().substring(0, 10) ?? '');
    final expiresC =
        TextEditingController(text: existing?.expiresAt?.toIso8601String().substring(0, 10) ?? '');
    var draft = existing?.draft ?? false;
    var body = existing?.body ?? const <ContentBlock>[];
    String? placeId = existing?.placeId;
    String? academicYearId = existing?.academicYearId;
    DateTime? eventDate = existing?.eventDate;
    var booked = const <PlaceBooking>[];

    // Real "boş/dolu" mekân müsaitliği (docs/EKSIKLER.md §4): what's
    // already booked at the chosen place on the chosen date, so the admin
    // sees a real conflict before hitting Kaydet, not just after a
    // rejected save.
    Future<void> refreshAvailability(void Function(void Function()) setDialogState) async {
      final pid = placeId;
      final date = eventDate;
      if (pid == null || date == null) {
        setDialogState(() => booked = const []);
        return;
      }
      final result = await widget.repository.getPlaceAvailability(pid, date);
      setDialogState(() => booked = result.where((b) => b.eventId != existing?.id).toList());
    }

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_event_new') : strings.t('admin_event_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_basic_info')),
            TextField(controller: titleC, decoration: InputDecoration(labelText: strings.t('admin_field_title'))),
            TextField(
                controller: timeC,
                decoration: InputDecoration(labelText: strings.t('admin_event_time')),
                onChanged: (_) => refreshAvailability(setDialogState)),
            InkWell(
              onTap: () async {
                final now = DateTime.now();
                final picked = await showDatePicker(
                  context: ctx,
                  initialDate: eventDate ?? now,
                  firstDate: now.subtract(const Duration(days: 365)),
                  lastDate: now.add(const Duration(days: 730)),
                );
                if (picked == null) return;
                setDialogState(() => eventDate = picked);
                await refreshAvailability(setDialogState);
              },
              child: InputDecorator(
                decoration: const InputDecoration(labelText: 'Tarih (müsaitlik kontrolü için)'),
                child: Text(eventDate == null
                    ? 'Tarih seç (opsiyonel)'
                    : '${eventDate!.day.toString().padLeft(2, '0')}.${eventDate!.month.toString().padLeft(2, '0')}.${eventDate!.year}'),
              ),
            ),
            if (booked.isNotEmpty)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                    color: ArucadColors.warning.withValues(alpha: .1),
                    borderRadius: BorderRadius.circular(12)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Bu mekân o gün şu saatlerde dolu:',
                      style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                  const SizedBox(height: 4),
                  for (final b in booked)
                    Text('${b.time} — ${b.title}',
                        style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                ]),
              ),
            DropdownButtonFormField<String?>(
              initialValue: places.any((p) => p.id == placeId) ? placeId : null,
              decoration: InputDecoration(labelText: strings.t('admin_event_registered_place')),
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('— Serbest metin —')),
                ...places.map((p) => DropdownMenuItem<String?>(value: p.id, child: Text(p.name))),
              ],
              onChanged: (v) {
                setDialogState(() {
                  placeId = v;
                  if (v != null) placeC.text = places.firstWhere((p) => p.id == v).name;
                });
                refreshAvailability(setDialogState);
              },
            ),
            TextField(controller: placeC, decoration: InputDecoration(labelText: strings.t('admin_field_place'))),
            TextField(controller: categoryC, decoration: InputDecoration(labelText: strings.t('admin_field_category'))),
            TextField(controller: organizerC, decoration: InputDecoration(labelText: strings.t('admin_event_organizer'))),
            TextField(
                controller: organizerEmailC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_event_organizer_email'),
                    hintText: strings.t('admin_event_organizer_email_hint'))),
            TextField(
                controller: descriptionC,
                decoration: InputDecoration(labelText: strings.t('admin_field_description_short')),
                maxLines: 3),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_event_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'event:${existing.id}');
                  setDialogState(() => body = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${body.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'event:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () => _manageParticipationTypes(existing),
                  icon: const Icon(Icons.groups_2_outlined, size: 16),
                  label: Text(strings.t('admin_event_participation_types')),
                ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () => _manageAttendance(existing),
                  icon: const Icon(Icons.how_to_reg_outlined, size: 16),
                  label: Text(strings.t('admin_event_attendance')),
                ),
            ]),
            TextField(
                controller: attendeesC,
                decoration: InputDecoration(labelText: strings.t('admin_event_attendees')),
                keyboardType: TextInputType.number),
            TextField(
                controller: xpC,
                decoration: InputDecoration(labelText: strings.t('admin_event_xp')),
                keyboardType: TextInputType.number),
            _DialogSection(strings.t('admin_section_publishing')),
            TextField(
                controller: audienceC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_event_audience'),
                    hintText: strings.t('admin_event_audience_hint'))),
            DropdownButtonFormField<String?>(
              initialValue: academicYears.any((y) => y.id == academicYearId) ? academicYearId : null,
              decoration: InputDecoration(labelText: strings.t('admin_event_academic_year')),
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('— Yok —')),
                ...academicYears.map((y) => DropdownMenuItem<String?>(value: y.id, child: Text(y.label))),
              ],
              onChanged: (v) => setDialogState(() => academicYearId = v),
            ),
            TextField(
                controller: publishC,
                decoration: InputDecoration(labelText: strings.t('admin_event_publish_date'))),
            TextField(
                controller: expiresC,
                decoration: InputDecoration(labelText: strings.t('admin_event_expiry_date'))),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(strings.t('admin_event_draft_switch')),
              value: draft,
              onChanged: (v) => setDialogState(() => draft = v),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty) return;
    final id = existing?.id ?? 'event-${DateTime.now().millisecondsSinceEpoch}';
    try {
      await widget.repository.upsertEvent(CampusEvent(
      id: id,
      title: titleC.text.trim(),
      time: timeC.text.trim(),
      eventDate: eventDate,
      placeName: placeC.text.trim(),
      placeId: placeId,
      category: categoryC.text.trim(),
      attendees: int.tryParse(attendeesC.text) ?? 0,
      xp: int.tryParse(xpC.text) ?? 0,
      draft: draft,
      publishAt: _parseDate(publishC.text),
      expiresAt: _parseDate(expiresC.text),
      audience: audienceC.text.trim().isEmpty ? 'Tümü' : audienceC.text.trim(),
      organizer: organizerC.text.trim(),
      organizerEmail: organizerEmailC.text.trim().isEmpty ? null : organizerEmailC.text.trim(),
      description: descriptionC.text.trim(),
      academicYearId: academicYearId,
      body: body,
      ));
      await widget.repository.recordRevision('event:$id', body, widget.uploaderName);
      await AuditLogStore.logIfMock(widget.repository,
          actorName: widget.uploaderName,
          action: existing == null ? 'create' : 'update',
          targetType: 'event',
          targetLabel: titleC.text.trim());
      _reload();
    } on PlaceConflictException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
    }
  }

  /// Real, admin-managed participation options for one event (e.g.
  /// Katılımcı/Gönüllü/Organizasyon) — what the student-facing join popup
  /// (`showEventJoinSheet`) actually reads. Immediate-effect (each add/
  /// delete calls the backend right away), independent of the outer
  /// dialog's own "Kaydet" button.
  Future<void> _manageParticipationTypes(CampusEvent event) async {
    final strings = AdminLocale.of(context);
    var types = [...event.participationTypes];
    final labelC = TextEditingController();
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_event_participation_types')),
          content: SizedBox(
            width: 360,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (types.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Text(strings.t('admin_event_participation_types_empty'),
                      style: const TextStyle(color: ArucadColors.muted)),
                )
              else
                for (final t in types)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(t.label),
                    trailing: IconButton(
                      icon: const Icon(Icons.delete_outline, size: 20),
                      onPressed: () async {
                        await widget.repository.deleteParticipationType(event.id, t.id);
                        setDialogState(() => types = types.where((x) => x.id != t.id).toList());
                        _reload();
                      },
                    ),
                  ),
              const Divider(),
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: labelC,
                    decoration: InputDecoration(hintText: strings.t('admin_event_participation_type_hint')),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.add_circle_outline),
                  onPressed: () async {
                    final label = labelC.text.trim();
                    if (label.isEmpty) return;
                    final created = await widget.repository.upsertParticipationType(event.id,
                        label: label, sortOrder: types.length);
                    labelC.clear();
                    setDialogState(() => types = [...types, created]);
                    _reload();
                  },
                ),
              ]),
            ]),
          ),
          actions: [
            FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(strings.t('admin_close'))),
          ],
        ),
      ),
    );
  }

  /// Real attendance roster ("yoklama") — who actually joined, with their
  /// chosen participation type, approvable one by one. This is the real
  /// club-manager/teacher step after the join-time 2-stage email: joining
  /// alone doesn't mean "attending" until someone here says so.
  Future<void> _manageAttendance(CampusEvent event) async {
    final strings = AdminLocale.of(context);
    var future = widget.repository.getEventParticipants(event.id);
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_event_attendance')),
          content: SizedBox(
            width: 380,
            child: FutureBuilder<List<EventParticipant>>(
              future: future,
              builder: (context, snap) {
                if (!snap.hasData) {
                  return const SizedBox(
                      height: 80, child: Center(child: CircularProgressIndicator()));
                }
                final participants = snap.data!;
                if (participants.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text(strings.t('admin_event_attendance_empty'),
                        style: const TextStyle(color: ArucadColors.muted)),
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
                        subtitle: Text(p.participationTypeLabel ?? strings.t('admin_none')),
                        // Approval is a hard backend gate on form
                        // completion (FORM_NOT_SUBMITTED) — mirrored here
                        // so the button never fires a request doomed to
                        // fail, and the admin sees exactly why it's not
                        // actionable yet.
                        trailing: p.isApproved
                            ? const Icon(Icons.check_circle, color: ArucadColors.success)
                            : !p.isFormSubmitted
                                ? Text(strings.t('admin_event_attendance_form_pending'),
                                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5))
                                : TextButton(
                                    onPressed: () async {
                                      await widget.repository
                                          .approveEventParticipant(event.id, p.id);
                                      setDialogState(() => future =
                                          widget.repository.getEventParticipants(event.id));
                                    },
                                    child: Text(strings.t('admin_event_attendance_approve')),
                                  ),
                      );
                    },
                  ),
                );
              },
            ),
          ),
          actions: [
            FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(strings.t('admin_close'))),
          ],
        ),
      ),
    );
  }

  String? _statusLabel(AdminStrings strings, CampusEvent e) {
    if (e.draft) return strings.t('admin_status_draft');
    final now = DateTime.now();
    if (e.publishAt != null && e.publishAt!.isAfter(now)) return strings.t('admin_status_scheduled');
    if (e.expiresAt != null && e.expiresAt!.isBefore(now)) return strings.t('admin_status_expired');
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editEvent(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_events'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusEvent>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final events = snap.data!
                  .where((e) => e.title.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              if (events.isEmpty) {
                return Center(child: Text(AdminLocale.of(context).t('admin_empty_events')));
              }
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: events.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final e = events[i];
                  final status = _statusLabel(AdminLocale.of(context), e);
                  final isSelected = _selected.contains(e.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(e.id) : _selected.add(e.id)),
                            ),
                      title: Row(children: [
                        Expanded(
                            child: Text(e.title,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontWeight: FontWeight.w800))),
                        if (status != null) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                                color: ArucadColors.warning.withValues(alpha: .15),
                                borderRadius: BorderRadius.circular(999)),
                            child: Text(status,
                                style:
                                    const TextStyle(fontSize: 11, color: ArucadColors.warning)),
                          ),
                        ],
                      ]),
                      subtitle: Text(
                          '${e.time} · ${e.placeName} · ${e.category} · +${e.xp} XP · ${e.audience}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editEvent(e) : setState(() => isSelected
                              ? _selected.remove(e.id)
                              : _selected.add(e.id)),
                      onLongPress: () => setState(() => _selected.add(e.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteEvent(e.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'event',
                                    targetLabel: e.title);
                                _reload();
                              },
                            ),
                    ),
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

// --------------------------------------------------- Pending Activities

/// Real "Kendi Aktiviteni Oluştur" review queue — every student-submitted
/// activity starts as `pending_review` and stays invisible to everyone
/// else until an admin genuinely approves or rejects it here (see
/// `Api\Admin\EventController::approveActivity/rejectActivity`).
class _PendingActivitiesTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _PendingActivitiesTab({required this.repository, required this.adminName});
  @override
  State<_PendingActivitiesTab> createState() => _PendingActivitiesTabState();
}

class _PendingActivitiesTabState extends State<_PendingActivitiesTab> {
  late Future<List<CampusEvent>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getPendingActivities();
  }

  void _reload() => setState(() => _future = widget.repository.getPendingActivities());

  Future<void> _approve(CampusEvent e) async {
    await widget.repository.approveActivity(e.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'approve', targetType: 'event', targetLabel: e.title);
    _reload();
  }

  Future<void> _reject(CampusEvent e) async {
    final noteC = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Aktiviteyi reddet'),
        content: TextField(
          controller: noteC,
          decoration: const InputDecoration(labelText: 'Sebep (öğrenciye gösterilir)'),
          maxLines: 3,
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Reddet')),
        ],
      ),
    );
    if (confirmed != true) return;
    await widget.repository.rejectActivity(e.id, reviewNote: noteC.text.trim());
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'reject', targetType: 'event', targetLabel: e.title);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<CampusEvent>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final events = snap.data!;
        if (events.isEmpty) {
          return const Center(
            child: Padding(
              padding: EdgeInsets.all(32),
              child: Text('İncelenmeyi bekleyen öğrenci aktivitesi yok.',
                  textAlign: TextAlign.center, style: TextStyle(color: ArucadColors.muted)),
            ),
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
          itemCount: events.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (context, i) {
            final e = events[i];
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(e.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 4),
                  Text('${e.organizer} · ${e.placeName} · ${e.category}',
                      style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                  if (e.description.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(e.description, maxLines: 3, overflow: TextOverflow.ellipsis),
                  ],
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _reject(e),
                        child: const Text('Reddet'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: FilledButton(
                        onPressed: () => _approve(e),
                        child: const Text('Onayla'),
                      ),
                    ),
                  ]),
                ]),
              ),
            );
          },
        );
      },
    );
  }
}

// ----------------------------------------------------------------- Clubs

class _ClubsTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _ClubsTab({required this.repository, required this.uploaderName});
  @override
  State<_ClubsTab> createState() => _ClubsTabState();
}

class _ClubsTabState extends State<_ClubsTab> {
  late Future<List<CampusClub>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getClubs();
  }

  void _reload() => setState(() => _future = widget.repository.getClubs());

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteClub(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'club',
        targetLabel: '${_selected.length} kulüp (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editClub([CampusClub? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    final categoryC = TextEditingController(text: existing?.category);
    final descC = TextEditingController(text: existing?.description);
    var body = existing?.body ?? const <ContentBlock>[];

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_club_new') : strings.t('admin_club_edit')),
          content: _DialogShell(fields: [
            TextField(
                controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_club_name'))),
            TextField(
                controller: categoryC, decoration: InputDecoration(labelText: strings.t('admin_field_category'))),
            TextField(
                controller: descC,
                decoration: InputDecoration(labelText: strings.t('admin_field_description_short')),
                maxLines: 2),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_club_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'club:${existing.id}');
                  setDialogState(() => body = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${body.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'club:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    final id = existing?.id ?? 'club-${slugify(nameC.text)}';
    await widget.repository.upsertClub(CampusClub(
      id: id,
      name: nameC.text.trim(),
      category: categoryC.text.trim(),
      description: descC.text.trim(),
      body: body,
    ));
    await widget.repository.recordRevision('club:$id', body, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'club',
        targetLabel: nameC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editClub(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_clubs'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusClub>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final clubs = snap.data!
                  .where((c) => c.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: clubs.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final c = clubs[i];
                  final isSelected = _selected.contains(c.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(c.id) : _selected.add(c.id)),
                            ),
                      title: Text(c.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${c.category} · ${c.description}',
                          maxLines: 2, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editClub(c) : setState(() => isSelected
                              ? _selected.remove(c.id)
                              : _selected.add(c.id)),
                      onLongPress: () => setState(() => _selected.add(c.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteClub(c.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'club',
                                    targetLabel: c.name);
                                _reload();
                              },
                            ),
                    ),
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

// ---------------------------------------------------------------- Sports

class _SportsTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _SportsTab({required this.repository, required this.adminName});
  @override
  State<_SportsTab> createState() => _SportsTabState();
}

class _SportsTabState extends State<_SportsTab> {
  late Future<List<CampusSport>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getSports();
  }

  void _reload() => setState(() => _future = widget.repository.getSports());

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteSport(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'sport',
        targetLabel: '${_selected.length} spor (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editSport([CampusSport? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    final facilityC = TextEditingController(text: existing?.facility);
    final contactC = TextEditingController(text: existing?.contact);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(existing == null ? strings.t('admin_sport_new') : strings.t('admin_sport_edit')),
        content: _DialogShell(fields: [
          TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_field_name'))),
          TextField(controller: facilityC, decoration: InputDecoration(labelText: strings.t('admin_sport_facility'))),
          TextField(
              controller: contactC,
              decoration: InputDecoration(labelText: strings.t('admin_sport_contact_email'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
        ],
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertSport(CampusSport(
      id: existing?.id ?? 'sport-${slugify(nameC.text)}',
      name: nameC.text.trim(),
      facility: facilityC.text.trim(),
      contact: contactC.text.trim().isEmpty ? null : contactC.text.trim(),
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'sport',
        targetLabel: nameC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editSport(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_sports'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusSport>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final sports = snap.data!
                  .where((s) => s.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: sports.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final s = sports[i];
                  final isSelected = _selected.contains(s.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(s.id) : _selected.add(s.id)),
                            ),
                      title: Text(s.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(s.facility, maxLines: 1, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editSport(s) : setState(() => isSelected
                              ? _selected.remove(s.id)
                              : _selected.add(s.id)),
                      onLongPress: () => setState(() => _selected.add(s.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteSport(s.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.adminName,
                                    action: 'delete',
                                    targetType: 'sport',
                                    targetLabel: s.name);
                                _reload();
                              },
                            ),
                    ),
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

// -------------------------------------------------------------- Services

class _ServicesTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _ServicesTab({required this.repository, required this.uploaderName});
  @override
  State<_ServicesTab> createState() => _ServicesTabState();
}

class _ServicesTabState extends State<_ServicesTab> {
  late Future<List<CampusService>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getServices();
  }

  void _reload() => setState(() => _future = widget.repository.getServices());

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteService(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'service',
        targetLabel: '${_selected.length} hizmet (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editService([CampusService? existing]) async {
    final strings = AdminLocale.of(context);
    final titleC = TextEditingController(text: existing?.title);
    final categoryC = TextEditingController(text: existing?.category);
    final descC = TextEditingController(text: existing?.description);
    final contactC = TextEditingController(text: existing?.contact);
    final buildingC = TextEditingController(text: existing?.building);
    final floorC = TextEditingController(text: existing?.floor);
    final roomC = TextEditingController(text: existing?.room);
    final personC = TextEditingController(text: existing?.contactPerson);
    final hoursC = TextEditingController(text: existing?.hours);
    final topicsC = TextEditingController(text: existing?.topics.join(', '));
    var body = existing?.body ?? const <ContentBlock>[];

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_service_new') : strings.t('admin_service_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_basic_info')),
            TextField(controller: titleC, decoration: InputDecoration(labelText: strings.t('admin_field_title'))),
            TextField(
                controller: categoryC, decoration: InputDecoration(labelText: strings.t('admin_field_category'))),
            TextField(
                controller: descC,
                decoration: InputDecoration(labelText: strings.t('admin_field_description_short')),
                maxLines: 2),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_service_content_title'),
                      initialBlocks: body,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'service:${existing.id}');
                  setDialogState(() => body = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${body.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'service:${existing.id}');
                    if (restored != null) setDialogState(() => body = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
            TextField(controller: contactC, decoration: InputDecoration(labelText: strings.t('admin_field_email'))),
            TextField(
                controller: topicsC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_service_topics'),
                    hintText: strings.t('admin_service_topics_hint'))),
            TextField(
                controller: hoursC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_field_hours'), hintText: strings.t('admin_hours_hint'))),
            _DialogSection(strings.t('admin_section_location_optional')),
            TextField(controller: buildingC, decoration: InputDecoration(labelText: strings.t('admin_field_building'))),
            TextField(controller: floorC, decoration: InputDecoration(labelText: strings.t('admin_field_floor'))),
            TextField(controller: roomC, decoration: InputDecoration(labelText: strings.t('admin_field_room'))),
            TextField(
                controller: personC, decoration: InputDecoration(labelText: strings.t('admin_service_contact_person'))),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty) return;
    String? orNull(String s) => s.trim().isEmpty ? null : s.trim();
    final id = existing?.id ?? 'service-${slugify(titleC.text)}';
    await widget.repository.upsertService(CampusService(
      id: id,
      title: titleC.text.trim(),
      category: categoryC.text.trim(),
      description: descC.text.trim(),
      contact: contactC.text.trim(),
      building: orNull(buildingC.text),
      floor: orNull(floorC.text),
      room: orNull(roomC.text),
      contactPerson: orNull(personC.text),
      hours: orNull(hoursC.text),
      topics: topicsC.text.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList(),
      body: body,
    ));
    await widget.repository.recordRevision('service:$id', body, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'service',
        targetLabel: titleC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editService(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_services'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusService>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final services = snap.data!
                  .where((s) => s.title.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: services.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final s = services[i];
                  final isSelected = _selected.contains(s.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(s.id) : _selected.add(s.id)),
                            ),
                      title: Text(s.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${s.category} · ${s.description}',
                          maxLines: 2, overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editService(s) : setState(() => isSelected
                              ? _selected.remove(s.id)
                              : _selected.add(s.id)),
                      onLongPress: () => setState(() => _selected.add(s.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await widget.repository.deleteService(s.id);
                                await AuditLogStore.logIfMock(widget.repository,
                                    actorName: widget.uploaderName,
                                    action: 'delete',
                                    targetType: 'service',
                                    targetLabel: s.title);
                                _reload();
                              },
                            ),
                    ),
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

// ------------------------------------------------------------- Yemek

class _FoodTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _FoodTab({required this.repository, required this.adminName});
  @override
  State<_FoodTab> createState() => _FoodTabState();
}

class _FoodTabState extends State<_FoodTab> {
  late Future<List<CampusFoodVenue>> _future;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getFoodVenues();
  }

  void _reload() => setState(() => _future = widget.repository.getFoodVenues());

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteFoodVenue(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'food_venue',
        targetLabel: '${_selected.length} yemek noktası (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editVenue([CampusFoodVenue? existing]) async {
    final strings = AdminLocale.of(context);
    final nameC = TextEditingController(text: existing?.name);
    final hoursC = TextEditingController(text: existing?.hours);
    final fileUrlC = TextEditingController(text: existing?.menuFileUrl);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(existing == null ? strings.t('admin_venue_new') : strings.t('admin_venue_edit')),
        content: _DialogShell(fields: [
          TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_field_name'))),
          TextField(
              controller: hoursC,
              decoration: InputDecoration(
                  labelText: strings.t('admin_venue_hours'), hintText: strings.t('admin_venue_hours_hint'))),
          TextField(
              controller: fileUrlC,
              decoration: InputDecoration(
                  labelText: strings.t('admin_venue_menu_file'),
                  hintText: strings.t('admin_venue_menu_file_hint')),
              maxLines: 2),
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              strings.t('admin_venue_note'),
              style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5),
            ),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
        ],
      ),
    );
    if (saved != true || nameC.text.trim().isEmpty) return;
    await widget.repository.upsertFoodVenue(CampusFoodVenue(
      id: existing?.id ?? 'food-${slugify(nameC.text)}',
      name: nameC.text.trim(),
      hours: hoursC.text.trim().isEmpty ? null : hoursC.text.trim(),
      dailyMenus: existing?.dailyMenus ?? const [],
      menuFileUrl: fileUrlC.text.trim().isEmpty ? null : fileUrlC.text.trim(),
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'food_venue',
        targetLabel: nameC.text.trim());
    _reload();
  }

  Future<void> _manageCalendar(CampusFoodVenue venue) async {
    final strings = AdminLocale.of(context);
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _FoodMenuCalendarScreen(
            repository: widget.repository, venue: venue, strings: strings)));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editVenue(), child: const Icon(Icons.add)),
      body: Column(children: [
        _AdminListToolbar(
          searchHint: strings.t('admin_search_food'),
          onQueryChanged: (v) => setState(() => _query = v),
          selectedCount: _selected.length,
          onCancelSelection: () => setState(_selected.clear),
          onDeleteSelected: _deleteSelected,
        ),
        Expanded(
          child: FutureBuilder<List<CampusFoodVenue>>(
            future: _future,
            builder: (context, snap) {
              if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
              final venues = snap.data!
                  .where((v) => v.name.toLowerCase().contains(_query.toLowerCase()))
                  .toList();
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                itemCount: venues.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final v = venues[i];
                  final isSelected = _selected.contains(v.id);
                  return Card(
                    child: ListTile(
                      leading: _selected.isEmpty
                          ? null
                          : Checkbox(
                              value: isSelected,
                              onChanged: (_) => setState(() =>
                                  isSelected ? _selected.remove(v.id) : _selected.add(v.id)),
                            ),
                      title: Text(v.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(
                          v.dailyMenus.isEmpty
                              ? strings.t('admin_food_daily_menu_empty')
                              : '${v.dailyMenus.length} ${strings.t('admin_food_daily_menu_count')}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                      onTap: () =>
                          _selected.isEmpty ? _editVenue(v) : setState(() => isSelected
                              ? _selected.remove(v.id)
                              : _selected.add(v.id)),
                      onLongPress: () => setState(() => _selected.add(v.id)),
                      trailing: _selected.isNotEmpty
                          ? null
                          : Row(mainAxisSize: MainAxisSize.min, children: [
                              IconButton(
                                icon: const Icon(Icons.calendar_month_outlined),
                                tooltip: strings.t('admin_food_calendar_tooltip'),
                                onPressed: () => _manageCalendar(v),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline),
                                onPressed: () async {
                                  await widget.repository.deleteFoodVenue(v.id);
                                  await AuditLogStore.logIfMock(widget.repository,
                                      actorName: widget.adminName,
                                      action: 'delete',
                                      targetType: 'food_venue',
                                      targetLabel: v.name);
                                  _reload();
                                },
                              ),
                            ]),
                    ),
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

/// Per-venue daily menu editor — add/edit/delete a specific calendar day's
/// items/price/hours, so a student picking a date on the Garden's calendar
/// sees exactly what an admin actually entered for that day.
class _FoodMenuCalendarScreen extends StatefulWidget {
  final CampusRepository repository;
  final CampusFoodVenue venue;
  // Passed in explicitly rather than read via `AdminLocale.of(context)`:
  // this screen is reached via `Navigator.push`, which mounts it as a new
  // route outside the `AdminPanelScreen` subtree that `AdminLocale` wraps,
  // so there is no ancestor to look up here.
  final AdminStrings strings;
  const _FoodMenuCalendarScreen(
      {required this.repository, required this.venue, required this.strings});

  @override
  State<_FoodMenuCalendarScreen> createState() => _FoodMenuCalendarScreenState();
}

class _FoodMenuCalendarScreenState extends State<_FoodMenuCalendarScreen> {
  late List<DailyMenu> _menus;

  @override
  void initState() {
    super.initState();
    _menus = [...widget.venue.dailyMenus]..sort((a, b) => a.date.compareTo(b.date));
  }

  Future<void> _persistMenu(DailyMenu menu) async {
    await widget.repository.upsertFoodMenu(widget.venue.id, menu);
  }

  Future<void> _persistDelete(DailyMenu menu) async {
    await widget.repository.deleteFoodMenu(widget.venue.id, menu.date);
  }

  Future<void> _editDay([DailyMenu? existing]) async {
    final strings = widget.strings;
    DateTime date = existing?.date ?? DateTime.now();
    final itemsC = TextEditingController(text: existing?.items.join(', '));
    final priceC = TextEditingController(text: existing?.price);
    final hoursC = TextEditingController(text: existing?.hours);

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(strings.t('admin_day_title')),
          content: _DialogShell(fields: [
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event_outlined),
              title: Text('${date.day}.${date.month}.${date.year}'),
              trailing: TextButton(
                onPressed: () async {
                  final picked = await showDatePicker(
                    context: ctx,
                    initialDate: date,
                    firstDate: DateTime.now().subtract(const Duration(days: 365)),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                  );
                  if (picked != null) setDialogState(() => date = picked);
                },
                child: Text(strings.t('admin_day_pick_date')),
              ),
            ),
            TextField(
                controller: itemsC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_items'), hintText: strings.t('admin_day_items_hint')),
                maxLines: 2),
            TextField(
                controller: priceC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_price'), hintText: strings.t('admin_day_price_hint'))),
            TextField(
                controller: hoursC,
                decoration: InputDecoration(
                    labelText: strings.t('admin_day_hours'), hintText: strings.t('admin_day_hours_hint'))),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true) return;
    final items =
        itemsC.text.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList();
    final entry = DailyMenu(
      date: date,
      items: items,
      price: priceC.text.trim().isEmpty ? null : priceC.text.trim(),
      hours: hoursC.text.trim().isEmpty ? null : hoursC.text.trim(),
    );
    setState(() {
      _menus.removeWhere((m) =>
          m.date.year == entry.date.year &&
          m.date.month == entry.date.month &&
          m.date.day == entry.date.day);
      _menus.add(entry);
      _menus.sort((a, b) => a.date.compareTo(b.date));
    });
    await _persistMenu(entry);
  }

  Future<void> _deleteDay(DailyMenu menu) async {
    setState(() => _menus.remove(menu));
    await _persistDelete(menu);
  }

  @override
  Widget build(BuildContext context) {
    final strings = widget.strings;
    return Scaffold(
      appBar: AppBar(
          title: Text('${widget.venue.name} · ${strings.t('admin_day_calendar_title')}',
              maxLines: 1, overflow: TextOverflow.ellipsis)),
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editDay(), child: const Icon(Icons.add)),
      body: _menus.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(strings.t('admin_day_empty'), textAlign: TextAlign.center),
              ),
            )
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
              itemCount: _menus.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final m = _menus[i];
                return Card(
                  child: ListTile(
                    title: Text('${m.date.day}.${m.date.month}.${m.date.year}',
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    subtitle: Text([
                      if (m.items.isNotEmpty) m.items.join(' · '),
                      if (m.price != null) m.price!,
                      if (m.hours != null) m.hours!,
                    ].join(' — '), maxLines: 2, overflow: TextOverflow.ellipsis),
                    onTap: () => _editDay(m),
                    trailing: IconButton(
                      icon: const Icon(Icons.delete_outline),
                      onPressed: () => _deleteDay(m),
                    ),
                  ),
                );
              },
            ),
    );
  }
}

// --------------------------------------------------------- Bina Dizini

/// The Building → Floor → Room → Person layer: who/what is actually behind
/// a given door. Starts empty on every install — see `DirectoryEntry`'s
/// doc comment for why nothing is seeded here.
class _DirectoryTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _DirectoryTab({required this.repository, required this.adminName});
  @override
  State<_DirectoryTab> createState() => _DirectoryTabState();
}

class _DirectoryTabState extends State<_DirectoryTab> {
  late Future<List<DirectoryEntry>> _future;
  late Future<List<CampusService>> _servicesFuture;
  String _query = '';
  final Set<String> _selected = {};

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getDirectoryEntries();
    _servicesFuture = widget.repository.getServices();
  }

  void _reload() => setState(() => _future = widget.repository.getDirectoryEntries());

  Future<void> _deleteSelected() async {
    for (final id in _selected) {
      await widget.repository.deleteDirectoryEntry(id);
    }
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'delete',
        targetType: 'directory_entry',
        targetLabel: '${_selected.length} kayıt (toplu)');
    setState(() => _selected.clear());
    _reload();
  }

  Future<void> _editEntry(List<CampusService> services, [DirectoryEntry? existing]) async {
    final strings = AdminLocale.of(context);
    final buildingC = TextEditingController(text: existing?.building);
    final floorC = TextEditingController(text: existing?.floor);
    final roomC = TextEditingController(text: existing?.room);
    final nameC = TextEditingController(text: existing?.occupantName);
    final roleC = TextEditingController(text: existing?.occupantRole);
    String? relatedServiceId = existing?.relatedServiceId;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_dir_new') : strings.t('admin_dir_edit')),
          content: _DialogShell(fields: [
            _DialogSection(strings.t('admin_section_location')),
            TextField(controller: buildingC, decoration: InputDecoration(labelText: strings.t('admin_field_building'))),
            TextField(controller: floorC, decoration: InputDecoration(labelText: strings.t('admin_field_floor'))),
            TextField(controller: roomC, decoration: InputDecoration(labelText: strings.t('admin_field_room'))),
            _DialogSection(strings.t('admin_section_occupant')),
            TextField(controller: nameC, decoration: InputDecoration(labelText: strings.t('admin_dir_occupant_name'))),
            TextField(controller: roleC, decoration: InputDecoration(labelText: strings.t('admin_dir_occupant_role'))),
            DropdownButtonFormField<String?>(
              initialValue: relatedServiceId,
              decoration: InputDecoration(labelText: strings.t('admin_dir_related_service')),
              items: [
                DropdownMenuItem<String?>(value: null, child: Text(strings.t('admin_none'))),
                ...services.map((s) => DropdownMenuItem<String?>(value: s.id, child: Text(s.title))),
              ],
              onChanged: (v) => setDialogState(() => relatedServiceId = v),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || buildingC.text.trim().isEmpty || nameC.text.trim().isEmpty) return;
    String? orNull(String s) => s.trim().isEmpty ? null : s.trim();
    await widget.repository.upsertDirectoryEntry(DirectoryEntry(
      id: existing?.id ?? 'dir-${DateTime.now().millisecondsSinceEpoch}',
      building: buildingC.text.trim(),
      floor: orNull(floorC.text),
      room: orNull(roomC.text),
      occupantName: nameC.text.trim(),
      occupantRole: orNull(roleC.text),
      relatedServiceId: relatedServiceId,
    ));
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'directory_entry',
        targetLabel: '${nameC.text.trim()} (${buildingC.text.trim()})');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return FutureBuilder<List<CampusService>>(
      future: _servicesFuture,
      builder: (context, serviceSnap) {
        final services = serviceSnap.data ?? const [];
        return Scaffold(
          floatingActionButton: FloatingActionButton(
              onPressed: () => _editEntry(services), child: const Icon(Icons.add)),
          body: Column(children: [
            _AdminListToolbar(
              searchHint: strings.t('admin_search_directory'),
              onQueryChanged: (v) => setState(() => _query = v),
              selectedCount: _selected.length,
              onCancelSelection: () => setState(_selected.clear),
              onDeleteSelected: _deleteSelected,
            ),
            Expanded(
              child: FutureBuilder<List<DirectoryEntry>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
                  final entries = snap.data!
                      .where((e) =>
                          e.occupantName.toLowerCase().contains(_query.toLowerCase()))
                      .toList();
                  if (entries.isEmpty) {
                    return Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(
                          AdminLocale.of(context).t('admin_directory_empty'),
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: ArucadColors.muted),
                        ),
                      ),
                    );
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
                    itemCount: entries.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      final e = entries[i];
                      final where =
                          [e.building, e.floor, e.room].whereType<String>().join(', ');
                      final isSelected = _selected.contains(e.id);
                      return Card(
                        child: ListTile(
                          leading: _selected.isEmpty
                              ? null
                              : Checkbox(
                                  value: isSelected,
                                  onChanged: (_) => setState(() => isSelected
                                      ? _selected.remove(e.id)
                                      : _selected.add(e.id)),
                                ),
                          title: Text(e.occupantName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w800)),
                          subtitle: Text(
                              [where, if (e.occupantRole != null) e.occupantRole!]
                                  .join(' · '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis),
                          onTap: () => _selected.isEmpty
                              ? _editEntry(services, e)
                              : setState(() => isSelected
                                  ? _selected.remove(e.id)
                                  : _selected.add(e.id)),
                          onLongPress: () => setState(() => _selected.add(e.id)),
                          trailing: _selected.isNotEmpty
                              ? null
                              : IconButton(
                                  icon: const Icon(Icons.delete_outline),
                                  onPressed: () async {
                                    await widget.repository.deleteDirectoryEntry(e.id);
                                    await AuditLogStore.logIfMock(widget.repository,
                                        actorName: widget.adminName,
                                        action: 'delete',
                                        targetType: 'directory_entry',
                                        targetLabel: e.occupantName);
                                    _reload();
                                  },
                                ),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ]),
        );
      },
    );
  }
}

// ---------------------------------------------------------------- Pages

class _PagesTab extends StatefulWidget {
  final CampusRepository repository;
  final String uploaderName;
  const _PagesTab({required this.repository, required this.uploaderName});
  @override
  State<_PagesTab> createState() => _PagesTabState();
}

class _PagesTabState extends State<_PagesTab> {
  late Future<List<AdminPage>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getPages();
  }

  void _reload() => setState(() => _future = widget.repository.getPages());

  Future<void> _editPage([AdminPage? existing]) async {
    final strings = AdminLocale.of(context);
    final titleC = TextEditingController(text: existing?.title);
    final slugC = TextEditingController(text: existing?.slug);
    var blocks = existing?.blocks ?? const <ContentBlock>[];
    var status = existing?.status ?? AdminPageStatus.draft;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_page_new') : strings.t('admin_page_edit')),
          content: _DialogShell(fields: [
            TextField(
              controller: titleC,
              decoration: InputDecoration(labelText: strings.t('admin_field_title')),
              onChanged: (v) {
                if (existing == null) {
                  slugC.text = slugify(v);
                }
              },
            ),
            TextField(controller: slugC, decoration: InputDecoration(labelText: strings.t('admin_page_slug'))),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlinedButton.icon(
                onPressed: () async {
                  final result = await openBlockEditor(ctx,
                      title: strings.t('admin_page_content_title'),
                      initialBlocks: blocks,
                      uploaderName: widget.uploaderName,
                      repository: widget.repository,
                      draftKey: existing == null ? null : 'page:${existing.id}');
                  setDialogState(() => blocks = result);
                },
                icon: const Icon(Icons.view_agenda_outlined, size: 16),
                label: Text(
                    '${strings.t('admin_edit_content')} (${blocks.length} ${strings.t('admin_blocks_suffix')})'),
              ),
              if (existing != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final restored =
                        await openRevisionHistory(ctx,
                            repository: widget.repository, contentKey: 'page:${existing.id}');
                    if (restored != null) setDialogState(() => blocks = restored);
                  },
                  icon: const Icon(Icons.history_outlined, size: 16),
                  label: Text(strings.t('admin_history')),
                ),
            ]),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(strings.t('admin_page_publish_switch')),
              value: status == AdminPageStatus.published,
              onChanged: (v) => setDialogState(
                  () => status = v ? AdminPageStatus.published : AdminPageStatus.draft),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || titleC.text.trim().isEmpty) return;
    final id = existing?.id ?? 'page-${DateTime.now().millisecondsSinceEpoch}';
    await widget.repository.upsertPage(AdminPage(
      id: id,
      title: titleC.text.trim(),
      slug: slugC.text.trim().isEmpty ? slugify(titleC.text) : slugC.text.trim(),
      blocks: blocks,
      status: status,
      updatedAt: DateTime.now(),
      updatedBy: widget.uploaderName,
    ));
    await widget.repository.recordRevision('page:$id', blocks, widget.uploaderName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: existing == null ? 'create' : 'update',
        targetType: 'page',
        targetLabel: titleC.text.trim());
    _reload();
  }

  Future<void> _delete(AdminPage page) async {
    await widget.repository.deletePage(page.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.uploaderName,
        action: 'delete',
        targetType: 'page',
        targetLabel: page.title);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editPage(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<AdminPage>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final pages = snap.data!;
          if (pages.isEmpty) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  strings.t('admin_pages_empty'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: ArucadColors.muted),
                ),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: pages.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final p = pages[i];
              return Card(
                child: ListTile(
                  title: Text(p.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '/${p.slug} · ${p.blocks.length} blok · '
                      '${p.status == AdminPageStatus.published ? strings.t('admin_status_published') : strings.t('admin_status_draft')}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  onTap: () => _editPage(p),
                  trailing: IconButton(
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () => _delete(p),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

// ------------------------------------------------------- Users & Roles

class _UsersTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _UsersTab({required this.repository, required this.adminName});
  @override
  State<_UsersTab> createState() => _UsersTabState();
}

class _UsersTabState extends State<_UsersTab> {
  late Future<List<RoleAssignment>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getRoleAssignments();
  }

  void _reload() => setState(() => _future = widget.repository.getRoleAssignments());

  Future<void> _editAssignment([RoleAssignment? existing]) async {
    final strings = AdminLocale.of(context);
    final emailC = TextEditingController(text: existing?.email);
    var role = existing?.role ?? UserRole.student;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_role_new') : strings.t('admin_role_edit')),
          content: _DialogShell(fields: [
            TextField(
              controller: emailC,
              enabled: existing == null,
              decoration: InputDecoration(labelText: strings.t('admin_role_email')),
            ),
            DropdownButtonFormField<UserRole>(
              initialValue: role,
              decoration: InputDecoration(labelText: strings.t('admin_field_role')),
              items: UserRole.values
                  .map((r) => DropdownMenuItem(value: r, child: Text(r.label)))
                  .toList(),
              onChanged: (v) => setDialogState(() => role = v ?? role),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || emailC.text.trim().isEmpty) return;
    await widget.repository
        .setRoleAssignment(emailC.text.trim(), role, assignedBy: widget.adminName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'role_change',
        targetType: 'user',
        targetLabel: '${emailC.text.trim()} → ${role.label}');
    _reload();
  }

  Future<void> _remove(RoleAssignment a) async {
    await widget.repository.deleteRoleAssignment(a.email);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'role_change',
        targetType: 'user',
        targetLabel: '${a.email} → (kaldırıldı, varsayılana döner)');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editAssignment(), child: const Icon(Icons.person_add_outlined)),
      body: FutureBuilder<List<RoleAssignment>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final assignments = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            children: [
              Card(
                color: ArucadColors.mist,
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    strings.t('admin_role_local_note'),
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              if (assignments.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                      child: Text(strings.t('admin_role_none_yet'),
                          style: const TextStyle(color: ArucadColors.muted))),
                )
              else
                for (final a in assignments)
                  Card(
                    child: ListTile(
                      leading: const Icon(Icons.person_outline, color: ArucadColors.primary),
                      title: Text(a.email,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                          strings
                              .t('admin_role_assigned_by')
                              .replaceAll('{role}', a.role.label)
                              .replaceAll('{name}', a.assignedBy),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                      onTap: () => _editAssignment(a),
                      trailing: IconButton(
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () => _remove(a),
                      ),
                    ),
                  ),
            ],
          );
        },
      ),
    );
  }
}

// -------------------------------------------------------- Activity Log

class _ActivityLogTab extends StatefulWidget {
  final CampusRepository repository;
  const _ActivityLogTab({required this.repository});
  @override
  State<_ActivityLogTab> createState() => _ActivityLogTabState();
}

class _ActivityLogTabState extends State<_ActivityLogTab> {
  static const _apiPageSize = 20;
  List<AuditLogEntry> _entries = const [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  int _page = 0;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final initial = _entries.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final page = await widget.repository.getAuditLogPage(page: 1, perPage: _apiPageSize);
      if (!mounted) return;
      setState(() {
        _entries = page.items;
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loading = false;
        _loadingMore = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository.getAuditLogPage(page: _page + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _entries.map((e) => e.id).toSet();
      setState(() {
        _entries = [..._entries, ...page.items.where((e) => !seen.contains(e.id))];
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  (IconData, Color) _visual(String action) => switch (action) {
        'create' => (Icons.add_circle_outline, ArucadColors.success),
        'update' => (Icons.edit_outlined, ArucadColors.blue),
        'delete' => (Icons.delete_outline, ArucadColors.danger),
        'login' => (Icons.login, ArucadColors.muted),
        'logout' => (Icons.logout, ArucadColors.muted),
        'role_change' => (Icons.admin_panel_settings_outlined, ArucadColors.warning),
        'moderation' => (Icons.flag_outlined, ArucadColors.warning),
        _ => (Icons.circle_outlined, ArucadColors.muted),
      };

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_entries.isEmpty) {
      return Center(
        child: Text(strings.t('admin_no_activity_yet'),
            style: const TextStyle(color: ArucadColors.muted)),
      );
    }
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        itemCount: _entries.length + 1,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          if (i == _entries.length) {
            return LoadMoreButton(
              shown: _entries.length,
              total: _total,
              itemLabel: strings.t('admin_record_noun'),
              onTap: _hasMore && !_loadingMore ? _loadMore : () {},
            );
          }
          final e = _entries[i];
          final (icon, color) = _visual(e.action);
          return Card(
            child: ListTile(
              dense: true,
              leading: CircleAvatar(
                  radius: 16,
                  backgroundColor: color.withValues(alpha: .14),
                  child: Icon(icon, size: 16, color: color)),
              title: Text('${e.actorName} · ${e.action}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              subtitle: Text(
                  e.targetLabel.isEmpty ? e.targetType : '${e.targetType}: ${e.targetLabel}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12)),
              trailing: Text(
                  '${e.at.day}.${e.at.month} ${e.at.hour.toString().padLeft(2, '0')}:${e.at.minute.toString().padLeft(2, '0')}',
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11)),
            ),
          );
        },
      ),
    );
  }
}

// ----------------------------------------------------------- Moderation

class _ModerationTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _ModerationTab({required this.repository, required this.adminName});
  @override
  State<_ModerationTab> createState() => _ModerationTabState();
}

class _ModerationTabState extends State<_ModerationTab> {
  late Future<List<ModerationReport>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getReports();
  }

  void _reload() => setState(() => _future = widget.repository.getReports());

  Future<void> _act(ModerationReport report, ModerationAction action) async {
    await widget.repository.resolveReport(report.id, action);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'moderation',
        targetType: report.kind.name,
        targetLabel: '${report.targetLabel} → ${action.name}');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return FutureBuilder<List<ModerationReport>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final reports = snap.data!;
        if (reports.isEmpty) {
          return Center(
              child: Text(strings.t('admin_no_pending_reports'),
                  style: const TextStyle(color: ArucadColors.muted)));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: reports.length,
          separatorBuilder: (_, __) => const SizedBox(height: 10),
          itemBuilder: (context, i) {
            final r = reports[i];
            final resolved = r.action != null;
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Icon(
                          r.kind == ReportedKind.post
                              ? Icons.dynamic_feed_outlined
                              : Icons.place_outlined,
                          size: 18, color: ArucadColors.muted),
                      const SizedBox(width: 6),
                      Expanded(
                          child: Text(r.targetLabel,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w800))),
                      if (resolved)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                              color: ArucadColors.success.withValues(alpha: .12),
                              borderRadius: BorderRadius.circular(999)),
                          child: Text(_actionLabel(strings, r.action!),
                              style: const TextStyle(fontSize: 11, color: ArucadColors.success)),
                        ),
                    ]),
                    const SizedBox(height: 6),
                    Text('${strings.t('admin_reason_prefix')}: ${r.reason}',
                        style: const TextStyle(color: ArucadColors.muted)),
                    if (!resolved) ...[
                      const SizedBox(height: 10),
                      Wrap(spacing: 8, children: [
                        OutlinedButton(
                            onPressed: () => _act(r, ModerationAction.dismissed),
                            child: Text(strings.t('admin_action_dismiss'))),
                        OutlinedButton(
                            onPressed: () => _act(r, ModerationAction.warned),
                            child: Text(strings.t('admin_action_warn'))),
                        FilledButton(
                            onPressed: () => _act(r, ModerationAction.removed),
                            style: FilledButton.styleFrom(backgroundColor: ArucadColors.danger),
                            child: Text(strings.t('admin_content_remove'))),
                      ]),
                    ],
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  String _actionLabel(AdminStrings strings, ModerationAction action) => switch (action) {
        ModerationAction.dismissed => strings.t('admin_action_dismissed'),
        ModerationAction.warned => strings.t('admin_action_warned'),
        ModerationAction.removed => strings.t('admin_action_removed'),
      };
}

// ------------------------------------------------------------- Site Settings

/// Public Entra client IDs and WordPress site URL, plus write-only secrets.
/// REST mode reads/writes through [CampusRepository] (`GET/POST
/// /admin/settings/site`). Mock mode still uses [SiteSettingsStore].
/// Filling Entra in mock mode still changes sign-in on next launch
/// (`main.dart`) — there is no fake "saved!" toast here.
class _SiteSettingsTab extends StatefulWidget {
  final CampusRepository repository;
  final String actorName;
  const _SiteSettingsTab({required this.repository, required this.actorName});
  @override
  State<_SiteSettingsTab> createState() => _SiteSettingsTabState();
}

class _SiteSettingsTabState extends State<_SiteSettingsTab> {
  final _tenantC = TextEditingController();
  final _clientC = TextEditingController();
  final _redirectC = TextEditingController();
  final _wpUrlC = TextEditingController();
  final _wpTokenC = TextEditingController();
  final _moderationKeyC = TextEditingController();

  bool _loading = true;
  Object? _loadError;
  bool _savingEntra = false;
  bool _savingWp = false;
  bool _savingModeration = false;
  bool _wpBusy = false;
  String? _wpResult;
  bool _wpError = false;
  bool _wpTokenConfigured = false;
  // Real, server-side-only setting (docs/EKSIKLER.md §26) — the backend
  // never echoes the raw key back, so this only ever reflects "is one
  // configured", never the value itself.
  bool _moderationConfigured = false;

  static const _defaultRedirect =
      'com.example.arucad_campus_prototype:/oauthredirect';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final settings = await widget.repository.getSiteSettings();
      final moderationConfigured =
          await widget.repository.getImageModerationConfigured();
      if (!mounted) return;
      setState(() {
        _tenantC.text = settings.entra.tenantId;
        _clientC.text = settings.entra.clientId;
        _redirectC.text = settings.entra.redirectUri.isEmpty
            ? _defaultRedirect
            : settings.entra.redirectUri;
        _wpUrlC.text = settings.wordpressSiteUrl;
        _wpTokenC.text = settings.wordpressApiToken;
        _wpTokenConfigured = settings.wordpressApiTokenConfigured;
        _moderationConfigured = moderationConfigured;
        _loadError = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loadError = e;
        _loading = false;
      });
    }
  }

  Future<void> _saveModeration() async {
    setState(() => _savingModeration = true);
    final configured =
        await widget.repository.setImageModerationApiKey(_moderationKeyC.text.trim());
    _moderationKeyC.clear();
    if (!mounted) return;
    setState(() => _moderationConfigured = configured);
    if (!mounted) return;
    setState(() => _savingModeration = false);
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AdminLocale.of(context).t('admin_moderation_saved_toast'))));
  }

  Future<void> _saveEntra() async {
    setState(() => _savingEntra = true);
    try {
      await widget.repository.updateSiteSettings(
        entra: EntraSiteConfig(
          tenantId: _tenantC.text.trim(),
          clientId: _clientC.text.trim(),
          redirectUri: _redirectC.text.trim(),
        ),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AdminLocale.of(context).t('admin_entra_saved_toast'))));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    } finally {
      if (mounted) setState(() => _savingEntra = false);
    }
  }

  Future<void> _saveWordPress({bool showToast = true}) async {
    setState(() => _savingWp = true);
    final typedToken = _wpTokenC.text.trim();
    try {
      final updated = await widget.repository.updateSiteSettings(
        wordpressSiteUrl: _wpUrlC.text.trim(),
        wordpressApiToken: typedToken.isEmpty ? null : typedToken,
      );
      if (typedToken.isNotEmpty) _wpTokenC.clear();
      if (!mounted) return;
      setState(() => _wpTokenConfigured = updated.wordpressApiTokenConfigured);
      if (showToast) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(AdminLocale.of(context).t('admin_wp_saved_toast'))));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      rethrow;
    } finally {
      if (mounted) setState(() => _savingWp = false);
    }
  }

  Future<void> _clearWordPressToken() async {
    setState(() => _savingWp = true);
    try {
      final updated = await widget.repository.updateSiteSettings(wordpressApiToken: '');
      _wpTokenC.clear();
      if (!mounted) return;
      setState(() => _wpTokenConfigured = updated.wordpressApiTokenConfigured);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    } finally {
      if (mounted) setState(() => _savingWp = false);
    }
  }

  Future<void> _pull(
    Future<List<Map<String, dynamic>>> Function() call,
    String successNoun,
  ) async {
    setState(() {
      _wpBusy = true;
      _wpResult = null;
      _wpError = false;
    });
    try {
      await _saveWordPress(showToast: false);
      final data = await call();
      if (!mounted) return;
      final strings = AdminLocale.of(context);
      setState(() {
        _wpBusy = false;
        _wpResult = '${data.length} $successNoun ${strings.t('admin_wp_fetched_suffix')}';
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _wpBusy = false;
        _wpError = true;
        _wpResult = '${AdminLocale.of(context).t('admin_wp_connection_failed')}: $e';
      });
    }
  }

  @override
  void dispose() {
    _tenantC.dispose();
    _clientC.dispose();
    _redirectC.dispose();
    _wpUrlC.dispose();
    _wpTokenC.dispose();
    _moderationKeyC.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_loadError != null) return _AdminLoadError(error: _loadError!);
    final strings = AdminLocale.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
      children: [
        Text(strings.t('admin_entra_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_entra_desc'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 14),
        TextField(
            controller: _tenantC,
            decoration: InputDecoration(labelText: strings.t('admin_entra_tenant_id'))),
        const SizedBox(height: 10),
        TextField(
            controller: _clientC,
            decoration: InputDecoration(labelText: strings.t('admin_entra_client_id'))),
        const SizedBox(height: 10),
        TextField(
            controller: _redirectC,
            decoration: InputDecoration(labelText: strings.t('admin_entra_redirect_uri'))),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_entra_redirect_note'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5),
        ),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: _savingEntra ? null : _saveEntra,
          child: Text(_savingEntra
              ? strings.t('admin_saving_ellipsis')
              : strings.t('admin_entra_save')),
        ),
        const SizedBox(height: 30),
        const Divider(),
        const SizedBox(height: 18),
        Text(strings.t('admin_wp_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_wp_desc'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 14),
        TextField(
            controller: _wpUrlC,
            decoration: InputDecoration(labelText: strings.t('admin_wp_site_url'))),
        const SizedBox(height: 10),
        TextField(
            controller: _wpTokenC,
            obscureText: true,
            decoration: InputDecoration(labelText: strings.t('admin_wp_api_token'))),
        const SizedBox(height: 10),
        Row(children: [
          Icon(
              _wpTokenConfigured ? Icons.check_circle : Icons.radio_button_unchecked,
              size: 16,
              color: _wpTokenConfigured ? ArucadColors.success : ArucadColors.muted),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
                _wpTokenConfigured
                    ? strings.t('admin_wp_token_configured')
                    : strings.t('admin_wp_token_not_configured'),
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
          ),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          FilledButton(
            onPressed: _savingWp ? null : () => _saveWordPress(),
            child: Text(_savingWp
                ? strings.t('admin_saving_ellipsis')
                : strings.t('admin_wp_save')),
          ),
          if (_wpTokenConfigured) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: _savingWp ? null : _clearWordPressToken,
              child: Text(strings.t('admin_wp_clear')),
            ),
          ],
        ]),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(
            child: OutlinedButton(
              onPressed: _wpBusy
                  ? null
                  : () => _pull(
                      () => WordPressDataSource.fetchForms(
                          siteUrl: _wpUrlC.text.trim(), apiToken: _wpTokenC.text.trim()),
                      strings.t('admin_wp_form_noun')),
              child: Text(strings.t('admin_wp_fetch_forms')),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: OutlinedButton(
              onPressed: _wpBusy
                  ? null
                  : () => _pull(
                      () => WordPressDataSource.fetchEntries(
                          siteUrl: _wpUrlC.text.trim(), apiToken: _wpTokenC.text.trim()),
                      strings.t('admin_wp_entry_noun')),
              child: Text(strings.t('admin_wp_fetch_entries')),
            ),
          ),
        ]),
        if (_wpBusy)
          const Padding(
              padding: EdgeInsets.only(top: 14), child: LinearProgressIndicator()),
        if (_wpResult != null)
          Padding(
            padding: const EdgeInsets.only(top: 14),
            child: Text(_wpResult!,
                style: TextStyle(
                    color: _wpError ? ArucadColors.danger : ArucadColors.success)),
          ),
        const SizedBox(height: 30),
        const Divider(),
        const SizedBox(height: 18),
        Text(strings.t('admin_moderation_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_moderation_desc'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 10),
        Row(children: [
          Icon(
              _moderationConfigured ? Icons.check_circle : Icons.radio_button_unchecked,
              size: 16,
              color: _moderationConfigured ? ArucadColors.success : ArucadColors.muted),
          const SizedBox(width: 6),
          Text(
              _moderationConfigured
                  ? strings.t('admin_moderation_configured')
                  : strings.t('admin_moderation_not_configured'),
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        ]),
        const SizedBox(height: 10),
        TextField(
            controller: _moderationKeyC,
            obscureText: true,
            decoration: InputDecoration(labelText: strings.t('admin_moderation_key_label'))),
        const SizedBox(height: 12),
        Row(children: [
          FilledButton(
            onPressed: _savingModeration ? null : _saveModeration,
            child: Text(_savingModeration
                ? strings.t('admin_saving_ellipsis')
                : strings.t('admin_moderation_save')),
          ),
          if (_moderationConfigured) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: _savingModeration
                  ? null
                  : () {
                      _moderationKeyC.clear();
                      _saveModeration();
                    },
              child: Text(strings.t('admin_moderation_clear')),
            ),
          ],
        ]),
      ],
    );
  }
}

// --------------------------------------------------------------- Surveys

/// Real poll/survey management — the same shape the student-facing popup
/// (`SurveyPopup`) reads from `/surveys/active`. Options are replaced
/// wholesale on every save (see `SurveyController::upsert()`'s own doc
/// comment) — simplest correct model for something that shouldn't be
/// restructured mid-vote anyway.
class _SurveysTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _SurveysTab({required this.repository, required this.adminName});
  @override
  State<_SurveysTab> createState() => _SurveysTabState();
}

class _SurveysTabState extends State<_SurveysTab> {
  late Future<List<Survey>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAllSurveys();
  }

  void _reload() => setState(() => _future = widget.repository.getAllSurveys());

  Future<void> _editSurvey([Survey? existing]) async {
    final questionC = TextEditingController(text: existing?.question);
    final descC = TextEditingController(text: existing?.description);
    final optionControllers = [
      for (final o in existing?.options ?? const <SurveyOption>[]) TextEditingController(text: o.label),
    ];
    if (optionControllers.length < 2) {
      optionControllers.addAll(List.generate(2 - optionControllers.length, (_) => TextEditingController()));
    }
    var multipleChoice = existing?.multipleChoice ?? false;
    var anonymous = existing?.anonymous ?? true;
    var showResults = existing?.showResults ?? true;
    var active = existing?.active ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni Anket' : 'Anketi Düzenle'),
          content: SizedBox(
            width: 380,
            child: _DialogShell(fields: [
              TextField(controller: questionC, decoration: const InputDecoration(labelText: 'Soru')),
              TextField(
                  controller: descC,
                  decoration: const InputDecoration(labelText: 'Açıklama (opsiyonel)'),
                  maxLines: 2),
              const _DialogSection('Seçenekler'),
              for (var i = 0; i < optionControllers.length; i++)
                Row(children: [
                  Expanded(
                    child: TextField(
                        controller: optionControllers[i],
                        decoration: InputDecoration(labelText: 'Seçenek ${i + 1}')),
                  ),
                  if (optionControllers.length > 2)
                    IconButton(
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () => setDialogState(() => optionControllers.removeAt(i)),
                    ),
                ]),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () => setDialogState(() => optionControllers.add(TextEditingController())),
                  icon: const Icon(Icons.add, size: 16),
                  label: const Text('Seçenek ekle'),
                ),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Çoklu seçim'),
                value: multipleChoice,
                onChanged: (v) => setDialogState(() => multipleChoice = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Anonim'),
                value: anonymous,
                onChanged: (v) => setDialogState(() => anonymous = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Sonuçları göster'),
                value: showResults,
                onChanged: (v) => setDialogState(() => showResults = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Aktif'),
                value: active,
                onChanged: (v) => setDialogState(() => active = v),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    final options = optionControllers.map((c) => c.text.trim()).where((t) => t.isNotEmpty).toList();
    if (saved != true || questionC.text.trim().isEmpty || options.length < 2) return;
    await widget.repository.upsertSurvey(
      id: existing?.id,
      question: questionC.text.trim(),
      description: descC.text.trim().isEmpty ? null : descC.text.trim(),
      multipleChoice: multipleChoice,
      anonymous: anonymous,
      showResults: showResults,
      active: active,
      options: options,
    );
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'survey',
        targetLabel: questionC.text.trim());
    _reload();
  }

  Future<void> _delete(Survey s) async {
    await widget.repository.deleteSurvey(s.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'delete', targetType: 'survey', targetLabel: s.question);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editSurvey(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<Survey>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final surveys = snap.data!;
          if (surveys.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Text('Henüz anket yok.', style: TextStyle(color: ArucadColors.muted)),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: surveys.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final s = surveys[i];
              return Card(
                child: ListTile(
                  title: Text(s.question,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '${s.options.length} seçenek · ${s.totalVotes} oy · '
                      '${s.active ? "Aktif" : "Pasif"}',
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                  onTap: () => _editSurvey(s),
                  trailing: IconButton(
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () => _delete(s),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

// --------------------------------------------------------- Academic Years

class _AcademicYearsTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _AcademicYearsTab({required this.repository, required this.adminName});
  @override
  State<_AcademicYearsTab> createState() => _AcademicYearsTabState();
}

class _AcademicYearsTabState extends State<_AcademicYearsTab> {
  late Future<List<AcademicYear>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAcademicYears();
  }

  void _reload() => setState(() => _future = widget.repository.getAcademicYears());

  Future<void> _editYear([AcademicYear? existing]) async {
    final idC = TextEditingController(text: existing?.id);
    final labelC = TextEditingController(text: existing?.label);
    final startsC = TextEditingController(
        text: existing?.startsOn.toIso8601String().substring(0, 10));
    final endsC =
        TextEditingController(text: existing?.endsOn.toIso8601String().substring(0, 10));
    var isActive = existing?.isActive ?? false;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni Akademik Yıl' : 'Akademik Yılı Düzenle'),
          content: _DialogShell(fields: [
            TextField(
                controller: idC,
                enabled: existing == null,
                decoration: const InputDecoration(labelText: 'ID (örn. 2026-2027)')),
            TextField(controller: labelC, decoration: const InputDecoration(labelText: 'Etiket')),
            TextField(
                controller: startsC,
                decoration: const InputDecoration(labelText: 'Başlangıç (YYYY-MM-DD)')),
            TextField(
                controller: endsC, decoration: const InputDecoration(labelText: 'Bitiş (YYYY-MM-DD)')),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Aktif yıl (diğerlerini pasifleştirir)'),
              value: isActive,
              onChanged: (v) => setDialogState(() => isActive = v),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    final starts = DateTime.tryParse(startsC.text.trim());
    final ends = DateTime.tryParse(endsC.text.trim());
    if (saved != true || idC.text.trim().isEmpty || labelC.text.trim().isEmpty ||
        starts == null || ends == null) {
      return;
    }
    await widget.repository.upsertAcademicYear(
      id: idC.text.trim(),
      label: labelC.text.trim(),
      startsOn: starts,
      endsOn: ends,
      isActive: isActive,
    );
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'academic_year',
        targetLabel: labelC.text.trim());
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editYear(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<AcademicYear>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final years = snap.data!;
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: years.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final y = years[i];
              return Card(
                child: ListTile(
                  title: Text(y.label, style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '${y.startsOn.toIso8601String().substring(0, 10)} → '
                      '${y.endsOn.toIso8601String().substring(0, 10)}'),
                  trailing: y.isActive
                      ? Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                              color: ArucadColors.success.withValues(alpha: .15),
                              borderRadius: BorderRadius.circular(999)),
                          child: const Text('Aktif',
                              style: TextStyle(fontSize: 11, color: ArucadColors.success)),
                        )
                      : null,
                  onTap: () => _editYear(y),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

// -------------------------------------------------------------- Email Log

class _EmailLogTab extends StatefulWidget {
  final CampusRepository repository;
  const _EmailLogTab({required this.repository});
  @override
  State<_EmailLogTab> createState() => _EmailLogTabState();
}

class _EmailLogTabState extends State<_EmailLogTab> {
  static const _apiPageSize = 20;
  List<EmailLogEntry> _logs = const [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  int _page = 0;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final initial = _logs.isEmpty;
    if (initial) setState(() => _loading = true);
    try {
      final page = await widget.repository.getEmailLogsPage(page: 1, perPage: _apiPageSize);
      if (!mounted) return;
      setState(() {
        _logs = page.items;
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _loading) return;
    setState(() => _loadingMore = true);
    try {
      final page = await widget.repository.getEmailLogsPage(page: _page + 1, perPage: _apiPageSize);
      if (!mounted) return;
      final seen = _logs.map((e) => e.id).toSet();
      setState(() {
        _logs = [..._logs, ...page.items.where((e) => !seen.contains(e.id))];
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _total = page.total;
        _loadingMore = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  Future<void> _sendBulk() async {
    final recipientsC = TextEditingController();
    final subjectC = TextEditingController();
    final bodyC = TextEditingController();
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Toplu E-posta Gönder'),
        content: _DialogShell(fields: [
          TextField(
              controller: recipientsC,
              decoration: const InputDecoration(
                  labelText: 'Alıcılar (virgülle ayrılmış)',
                  hintText: 'ali@arucad.edu.tr, ayse@arucad.edu.tr'),
              maxLines: 2),
          TextField(controller: subjectC, decoration: const InputDecoration(labelText: 'Konu')),
          TextField(
              controller: bodyC, decoration: const InputDecoration(labelText: 'İçerik'), maxLines: 5),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Gönder')),
        ],
      ),
    );
    final recipients = recipientsC.text.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
    if (saved != true || recipients.isEmpty || subjectC.text.trim().isEmpty) return;
    final sent = await widget.repository.sendBulkEmail(
        recipients: recipients, subject: subjectC.text.trim(), body: bodyC.text.trim());
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$sent / ${recipients.length} e-posta gönderildi.')));
    _refresh();
  }

  Future<void> _retry(EmailLogEntry log) async {
    await widget.repository.retryEmail(log.id);
    _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _sendBulk,
        icon: const Icon(Icons.send_outlined),
        label: const Text('Toplu E-posta'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _logs.isEmpty
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Text('Henüz gönderilmiş e-posta yok.',
                        style: TextStyle(color: ArucadColors.muted)),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
                    itemCount: _logs.length + 1,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      if (i == _logs.length) {
                        return LoadMoreButton(
                          shown: _logs.length,
                          total: _total,
                          itemLabel: 'kayıt',
                          onTap: _hasMore && !_loadingMore ? _loadMore : () {},
                        );
                      }
                      final l = _logs[i];
                      final ok = l.status == 'sent';
                      return Card(
                        child: ListTile(
                          leading: Icon(ok ? Icons.check_circle_outline : Icons.error_outline,
                              color: ok ? ArucadColors.success : ArucadColors.danger),
                          title: Text(l.subject, maxLines: 1, overflow: TextOverflow.ellipsis),
                          subtitle: Text(
                              '${l.toEmail} · ${l.template}${l.error != null ? " · ${l.error}" : ""}',
                              maxLines: 2, overflow: TextOverflow.ellipsis),
                          trailing: ok
                              ? null
                              : IconButton(
                                  icon: const Icon(Icons.refresh, size: 20),
                                  onPressed: () => _retry(l),
                                ),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}

/// What a panel section shows when its data can't be loaded.
///
/// The common case is now a real one: the backend authorizes each admin
/// section separately (`EnsurePermission`), so a role that can open the
/// panel at all may still be refused a particular tab. Before this, every
/// section treated "no data yet" and "the server said no" identically and
/// spun forever, which reads as a broken app rather than as a boundary
/// doing its job.
class _AdminLoadError extends StatelessWidget {
  final Object error;
  const _AdminLoadError({required this.error});

  @override
  Widget build(BuildContext context) {
    final e = error;
    final denied = e is ApiClientException && e.statusCode == 403;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(denied ? Icons.lock_outline : Icons.error_outline,
                size: 36, color: ArucadColors.muted),
            const SizedBox(height: 12),
            Text(
              denied ? 'Bu bölüm için yetkin yok.' : 'Bu bölüm yüklenemedi.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Text(
              denied
                  ? 'Erişim gerekiyorsa bir yöneticiden rolünü güncellemesini iste.'
                  : e is ApiClientException
                      ? e.message
                      : '$e',
              textAlign: TextAlign.center,
              style: const TextStyle(color: ArucadColors.muted, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}
