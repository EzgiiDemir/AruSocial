import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/explore/explore_screen.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/home_screen.dart';
import 'package:arucad_campus_prototype/features/profile/profile_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_shell.dart';
import 'package:arucad_campus_prototype/features/widgets/arucad_line_icon.dart';

/// Root shell: **5** primary tabs (Home · Explore · Social · Ask · Settings).
/// There used to be a standalone "Activity" tab (XP/journey/leaderboard) —
/// that content now lives inside Settings' "Kullanıcı Aktivitesi" category
/// (see [ProfileScreen]) instead of occupying its own root tab.
class CampusShell extends StatefulWidget {
  final CampusUser user;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final String initialLanguage;
  final UserRole role;
  final VoidCallback onLogout;
  final ValueChanged<String>? onLanguageChanged;

  const CampusShell({
    super.key,
    required this.user,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.initialLanguage = 'TR',
    this.role = UserRole.student,
    required this.onLogout,
    this.onLanguageChanged,
  });

  @override
  State<CampusShell> createState() => _CampusShellState();
}

class _CampusShellState extends State<CampusShell> {
  int _index = 0;
  final Set<int> _visited = {0};
  late String _language = widget.initialLanguage;
  CampusVisibility _visibility = CampusVisibility.ghost;
  bool _nearbyDiscoverable = false;
  bool _personalization = true;
  bool _isPrivateProfile = false;
  SettingsSection _settingsSection = SettingsSection.info;

  @override
  void initState() {
    super.initState();
    _loadSettings();
  }

  @override
  void didUpdateWidget(covariant CampusShell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initialLanguage != widget.initialLanguage &&
        widget.initialLanguage != _language) {
      _language = widget.initialLanguage;
    }
  }

  Future<void> _loadSettings() async {
    try {
      final settings = await widget.repository.getUserSettings();
      if (!mounted) return;
      final lang = settings.preferredLanguage;
      setState(() {
        _visibility = _visibilityFromName(settings.locationVisibility);
        _nearbyDiscoverable = settings.nearbyDiscoverable;
        _personalization = settings.personalization;
        _isPrivateProfile = settings.isPrivateProfile;
        if (lang == 'TR' || lang == 'EN' || lang == 'RU') {
          _language = lang;
        }
      });
      if (lang == 'TR' || lang == 'EN' || lang == 'RU') {
        widget.onLanguageChanged?.call(lang);
        await AppSettingsStore.setLanguage(lang);
      }
    } catch (_) {
      // Defaults above still work until the student opens Settings.
    }
  }

  Future<void> _changeLanguage(String value) async {
    setState(() => _language = value);
    widget.onLanguageChanged?.call(value);
    await AppSettingsStore.setLanguage(value);
    try {
      await widget.repository.updateUserSettings(preferredLanguage: value);
    } catch (_) {}
  }

  void _openTab(int index, {SettingsSection? settingsSection}) {
    setState(() {
      _visited.add(index);
      _index = index;
      if (settingsSection != null) _settingsSection = settingsSection;
    });
  }

  Widget _lazyTab(int index, Widget child) {
    if (!_visited.contains(index)) return const SizedBox.shrink();
    return RepaintBoundary(child: child);
  }

  CampusVisibility _visibilityFromName(String name) {
    for (final value in CampusVisibility.values) {
      if (value.name == name) return value;
    }
    return CampusVisibility.ghost;
  }

  @override
  Widget build(BuildContext context) {
    final lang = languageFromCode(_language);
    final strings = AppStrings(lang);
    return AppLocale(
      language: lang,
      child: Scaffold(
        body: SafeArea(
          // Each tab gets its own RepaintBoundary: without one, an
          // animation or scroll inside the active tab (or a setState up
          // here, e.g. a settings toggle) forces Flutter to reconsider
          // paint for every offstage tab in the IndexedStack too, which is
          // exactly the kind of invisible per-navigation cost that adds up
          // to "everything feels slow."
          child: IndexedStack(
            index: _index,
            children: [
              _lazyTab(
                0,
                HomeScreen(
                  user: widget.user,
                  repository: widget.repository,
                  mapProvider: widget.mapProvider,
                  analyticsTracker: widget.analyticsTracker,
                  onExplore: () => _openTab(1),
                  onQuests: () =>
                      _openTab(4, settingsSection: SettingsSection.activity),
                  onAI: () => _openTab(3),
                  onSocial: () => _openTab(2),
                  onLogout: _confirmLogout,
                  initialVisibility: _visibility,
                  showForYou: _personalization,
                ),
              ),
              _lazyTab(
                1,
                ExploreScreen(
                  repository: widget.repository,
                  mapProvider: widget.mapProvider,
                  analyticsTracker: widget.analyticsTracker,
                  onAI: () => _openTab(3),
                  initialVisibility: _visibility,
                ),
              ),
              _lazyTab(
                  2,
                  SocialShell(
                    repository: widget.repository,
                    mapProvider: widget.mapProvider,
                    analyticsTracker: widget.analyticsTracker,
                  )),
              _lazyTab(
                3,
                AskArucadScreen(
                  repository: widget.repository,
                  mapProvider: widget.mapProvider,
                  analyticsTracker: widget.analyticsTracker,
                ),
              ),
              _lazyTab(
                4,
                ProfileScreen(
                  user: widget.user,
                  onLogout: _confirmLogout,
                  repository: widget.repository,
                  mapProvider: widget.mapProvider,
                  analyticsTracker: widget.analyticsTracker,
                  role: widget.role,
                  language: _language,
                  locationVisibility: _visibility,
                  nearbyDiscoverable: _nearbyDiscoverable,
                  personalization: _personalization,
                  isPrivateProfile: _isPrivateProfile,
                  initialSection: _settingsSection,
                  onLanguage: _changeLanguage,
                  onLocationVisibility: (value) {
                    setState(() => _visibility = value);
                    widget.repository
                        .updateUserSettings(locationVisibility: value.name);
                  },
                  onNearbyDiscoverable: (value) {
                    setState(() => _nearbyDiscoverable = value);
                    widget.repository
                        .updateUserSettings(nearbyDiscoverable: value);
                  },
                  onPersonalization: (value) {
                    setState(() => _personalization = value);
                    widget.repository
                        .updateUserSettings(personalization: value);
                  },
                  onPrivateProfile: (value) {
                    setState(() => _isPrivateProfile = value);
                    widget.repository
                        .updateUserSettings(isPrivateProfile: value);
                  },
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: _RootNavigationBar(
          selectedIndex: _index,
          onDestinationSelected: _openTab,
          strings: strings,
          userName: widget.user.name,
        ),
      ),
    );
  }

  Future<void> _confirmLogout() async {
    final strings = AppStrings(languageFromCode(_language));
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(strings.t('common_logout')),
        content: Text(strings.t('common_logout_confirm')),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(strings.t('social_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(strings.t('common_logout'))),
        ],
      ),
    );
    if (confirmed == true) widget.onLogout();
  }
}

class _RootNavigationBar extends StatelessWidget {
  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final AppStrings strings;

  /// The signed-in student's display name, used to label the account tab.
  final String userName;

  const _RootNavigationBar({
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.strings,
    required this.userName,
  });

  /// First name for the account tab, trimmed to fit a nav label.
  ///
  /// The tab carries the student's own name rather than "Ayarlar" — it is
  /// their account, and a name is what makes it read as theirs. A bottom-bar
  /// item has very little room, so a long name is shortened rather than
  /// ellipsised into something unreadable, and an unloaded name falls back
  /// to the translated label so the bar never shows an empty item.
  String get _accountLabel {
    final full = userName.trim();
    if (full.isEmpty) return strings.t('nav_profile');

    final space = full.indexOf(' ');
    final first = space < 0 ? full : full.substring(0, space);

    return first.length <= 10 ? first : '${first.substring(0, 9)}…';
  }

  /// Measures the long label and swaps to the short one when it will not
  /// fit, instead of guessing from screen width alone — the deciding factor
  /// is the rendered width, which depends on the language and the reader's
  /// font-size setting as much as on the device.
  String _askLabel(BuildContext context, AppStrings strings, double perTab) {
    final full = strings.t('nav_ask');
    final painter = TextPainter(
      text: TextSpan(text: full, style: const TextStyle(fontSize: 11)),
      textDirection: TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context)
          .clamp(minScaleFactor: 1.0, maxScaleFactor: 1.3),
    )..layout();

    // 8px of breathing room, so it swaps just before it actually collides.
    return painter.width <= perTab - 8 ? full : strings.t('nav_ask_short');
  }

  @override
  Widget build(BuildContext context) {
    final surface = Theme.of(context).colorScheme.surface;
    final onSurface = Theme.of(context).colorScheme.onSurface;

    // Five labels have to share the screen width, and the longest of them
    // ("Arucad'a Sor") is what decides whether the bar looks right. Two
    // things break it on a real phone that a default-settings simulator
    // never shows:
    //
    //  - a 360px device gives each tab ~72px, and
    //  - the system font-size setting scales every label, without limit.
    //
    // So the size is derived from the width actually available, and the
    // user's text scale is applied but capped. Capping is the honest
    // trade-off here: a nav label that grows without bound pushes the icon
    // out of the bar entirely, which helps nobody — the rest of the app
    // still honours the setting in full.
    final width = MediaQuery.sizeOf(context).width;
    final perTab = width / 5;
    final base = perTab < 68 ? 9.0 : (perTab < 78 ? 10.0 : 11.0);

    final scaler = MediaQuery.textScalerOf(context).clamp(
      minScaleFactor: 1.0,
      maxScaleFactor: 1.3,
    );

    return MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: scaler),
      child: NavigationBarTheme(
      data: NavigationBarThemeData(
        backgroundColor: surface,
        indicatorColor: ArucadColors.primary.withValues(alpha: .12),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            size: perTab < 68 ? 20 : 22,
            color: states.contains(WidgetState.selected)
                ? ArucadColors.primary
                : onSurface,
          ),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: states.contains(WidgetState.selected) ? base : base - 0.5,
              fontWeight: states.contains(WidgetState.selected)
                  ? FontWeight.w800
                  : FontWeight.w600,
              height: 1.1,
              color: states.contains(WidgetState.selected)
                  ? ArucadColors.primary
                  : onSurface,
            )),
      ),
      child: NavigationBar(
        height: 70,
        selectedIndex: selectedIndex,
        onDestinationSelected: onDestinationSelected,
        destinations: [
          NavigationDestination(
              icon: ArucadLineIcon(
                  icon: ArucadLineIconKind.home,
                  semanticLabel: strings.t('nav_home')),
              selectedIcon: ArucadLineIcon(
                  icon: ArucadLineIconKind.home,
                  filled: true,
                  color: ArucadColors.primary,
                  semanticLabel: strings.t('nav_home')),
              label: strings.t('nav_home')),
          NavigationDestination(
              icon: ArucadLineIcon(
                  icon: ArucadLineIconKind.explore,
                  semanticLabel: strings.t('nav_explore')),
              selectedIcon: ArucadLineIcon(
                  icon: ArucadLineIconKind.explore,
                  color: ArucadColors.primary,
                  semanticLabel: strings.t('nav_explore')),
              label: strings.t('nav_explore')),
          NavigationDestination(
              icon: ArucadLineIcon(
                  icon: ArucadLineIconKind.social,
                  semanticLabel: strings.t('nav_social')),
              selectedIcon: ArucadLineIcon(
                  icon: ArucadLineIconKind.social,
                  color: ArucadColors.primary,
                  semanticLabel: strings.t('nav_social')),
              label: strings.t('nav_social')),
          NavigationDestination(
              icon: ArucadLineIcon(
                  icon: ArucadLineIconKind.ask,
                  semanticLabel: strings.t('nav_ask')),
              selectedIcon: ArucadLineIcon(
                  icon: ArucadLineIconKind.ask,
                  color: ArucadColors.primary,
                  semanticLabel: strings.t('nav_ask')),
              // "Arucad'a Sor" is the longest label by some margin and is
              // what makes the bar overflow first. Rather than shrink every
              // label until they are all hard to read, this one falls back
              // to its short form once the space per tab (after the user's
              // font-size setting) can no longer hold it. The full wording
              // stays as the semantic label, so a screen reader still says
              // "Arucad'a Sor".
              label: _askLabel(context, strings, perTab)),
          NavigationDestination(
              icon: ArucadLineIcon(
                  icon: ArucadLineIconKind.profile,
                  semanticLabel: strings.t('nav_profile')),
              selectedIcon: ArucadLineIcon(
                  icon: ArucadLineIconKind.profile,
                  color: ArucadColors.primary,
                  semanticLabel: strings.t('nav_profile')),
              // The tab carries the signed-in student's own first name
              // rather than "Ayarlar" — it is their account, and a name is
              // what makes the tab read as theirs. Falls back to the
              // translated label when the name is not loaded yet, so the
              // bar never shows an empty item.
              label: _accountLabel),
        ],
      ),
      ),
    );
  }
}
