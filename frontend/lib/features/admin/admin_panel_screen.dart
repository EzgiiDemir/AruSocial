import 'package:arucad_campus_prototype/features/widgets/top_notice.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/campus_taxonomy.dart';
import 'package:arucad_campus_prototype/core/config/content_categories.dart';
import 'package:arucad_campus_prototype/core/config/onboarding_config.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart' show slugify;
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/l10n/admin_strings.dart';
import 'package:arucad_campus_prototype/core/models/moderation_case.dart';
import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/models/admin_stats.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/email_log.dart';
import 'package:arucad_campus_prototype/core/models/event_participant.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/models/role_assignment.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/models/system_health.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/admin_settings_store.dart';
import 'package:arucad_campus_prototype/core/services/audit_log_store.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/cv_opener.dart';
import 'package:arucad_campus_prototype/core/services/site_settings_store.dart';
import 'package:arucad_campus_prototype/core/services/wordpress_data_source.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/admin/content_blocks/content_block_editor.dart';
import 'package:arucad_campus_prototype/features/admin/media/media_library_screen.dart';
import 'package:arucad_campus_prototype/features/auth/access_denied_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/language_toggle.dart';

part 'widgets/admin_shared_widgets.dart';
part 'widgets/admin_load_error.dart';
part 'sections/dashboard_tab.dart';
part 'sections/stats_tab.dart';
part 'sections/events_tab.dart';
part 'sections/pending_activities_tab.dart';
part 'sections/clubs_tab.dart';
part 'sections/places_tab.dart';
part 'sections/shuttle_tab.dart';
part 'sections/onboarding_tab.dart';
part 'sections/sports_tab.dart';
part 'sections/services_tab.dart';
part 'sections/food_tab.dart';
part 'sections/directory_tab.dart';
part 'sections/pages_tab.dart';
part 'sections/users_tab.dart';
part 'sections/activity_log_tab.dart';
part 'sections/moderation_tab.dart';
part 'sections/moderation_cases_section.dart';
part 'sections/site_settings_tab.dart';
part 'sections/system_health_tab.dart';
part 'sections/surveys_tab.dart';
part 'sections/academic_years_tab.dart';
part 'sections/email_log_tab.dart';
part 'sections/career_tab.dart';
part 'sections/applications_staff_tab.dart';
part 'sections/achievements_tab.dart';


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
  applications,
  clubs,
  places,
  shuttle,
  onboarding,
  sports,
  services,
  food,
  career,
  staff,
  achievements,
  pages,
  media,
  surveys,
  emailLog,
  users,
  moderation,
  activityLog,
  siteSettings,
  systemHealth,
  // Kept for deep-link / leftover navigation only — not shown in sidebar.
  directory,
  academicYears,
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
    AdminSettingsStore.setLanguage(adminLanguageCode(language));
  }

  String _titleFor(_AdminSection section, AdminStrings strings) => switch (section) {
        _AdminSection.dashboard => strings.t('admin_nav_dashboard'),
        _AdminSection.stats => strings.t('admin_nav_stats'),
        _AdminSection.events => strings.t('admin_nav_events'),
        _AdminSection.pendingActivities => strings.t('admin_nav_pending_activities'),
        _AdminSection.applications => strings.t('admin_nav_applications'),
        _AdminSection.clubs => strings.t('admin_nav_clubs'),
        _AdminSection.places => strings.t('admin_nav_places'),
        _AdminSection.shuttle => strings.t('admin_nav_shuttle'),
        _AdminSection.onboarding => strings.t('admin_nav_onboarding'),
        _AdminSection.sports => strings.t('admin_nav_sports'),
        _AdminSection.services => strings.t('admin_nav_services'),
        _AdminSection.food => strings.t('admin_nav_food'),
        _AdminSection.career => strings.t('admin_nav_career'),
        _AdminSection.staff => strings.t('admin_nav_staff'),
        _AdminSection.achievements => strings.t('admin_nav_achievements'),
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
        _AdminSection.systemHealth => strings.t('admin_nav_system_health'),
      };

  Widget _bodyFor(_AdminSection section) => switch (section) {
        _AdminSection.dashboard => _DashboardTab(
            repository: widget.repository,
            role: widget.role,
            onNavigate: (s) => setState(() => _section = s),
          ),
        _AdminSection.stats => _StatsTab(
            repository: widget.repository,
            role: widget.role,
            onNavigate: (s) => setState(() => _section = s),
          ),
        _AdminSection.events =>
          _EventsTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.pendingActivities =>
          _PendingActivitiesTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.applications =>
          _ApplicationsTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.clubs =>
          _ClubsTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.places =>
          _PlacesTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.shuttle =>
          _ShuttleTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.onboarding =>
          _OnboardingTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.sports =>
          _SportsTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.services =>
          _ServicesTab(repository: widget.repository, uploaderName: widget.user.name),
        _AdminSection.food =>
          _FoodTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.career =>
          _CareerOfficeHub(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.staff =>
          _StaffTab(repository: widget.repository, adminName: widget.user.name),
        _AdminSection.achievements => _AchievementsTab(repository: widget.repository),
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
        _AdminSection.systemHealth => _SystemHealthTab(repository: widget.repository),
      };

  @override
  Widget build(BuildContext context) {
    if (!widget.role.canOpenAdminPanel) {
      return AccessDeniedScreen(
        title: 'Yönetim paneli',
        message: 'Bu hesap yönetim paneline erişemez.',
        onLogout: widget.onLogout,
      );
    }
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
          LanguageToggle(
            code: adminLanguageCode(language),
            onDark: true,
            onChanged: (code) => onLanguageChanged(adminLanguageFromCode(code)),
          ),
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
          selected: selected == _AdminSection.stats
              ? _AdminSection.dashboard
              : selected,
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
            icon: Icons.assignment_turned_in_outlined,
            label: strings.t('admin_nav_applications'),
            section: _AdminSection.applications,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.groups_outlined,
            label: strings.t('admin_nav_clubs'),
            section: _AdminSection.clubs,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.place_outlined,
            label: strings.t('admin_nav_places'),
            section: _AdminSection.places,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.directions_bus_filled_outlined,
            label: strings.t('admin_nav_shuttle'),
            section: _AdminSection.shuttle,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.flag_outlined,
            label: strings.t('admin_nav_onboarding'),
            section: _AdminSection.onboarding,
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
            icon: Icons.work_outline,
            label: strings.t('admin_nav_career'),
            section: _AdminSection.career,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.badge_outlined,
            label: strings.t('admin_nav_staff'),
            section: _AdminSection.staff,
            selected: selected,
            onSelect: onSelect),
        _NavTile(
            icon: Icons.emoji_events_outlined,
            label: strings.t('admin_nav_achievements'),
            section: _AdminSection.achievements,
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
                icon: Icons.hub_outlined,
                label: strings.t('admin_nav_site_settings'),
                section: _AdminSection.siteSettings,
                selected: selected,
                onSelect: onSelect),
          if (role.canManageSiteSettings)
            _NavTile(
                icon: Icons.monitor_heart_outlined,
                label: strings.t('admin_nav_system_health'),
                section: _AdminSection.systemHealth,
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
