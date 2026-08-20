import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/chat_screen.dart';
import 'package:arucad_campus_prototype/features/social/people_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_screen.dart';

/// Below this width, [SocialShell] switches from a left-side navigation
/// rail (desktop/tablet) to a compact bottom bar (phone) — a full-height
/// rail on a ~360-430px-wide phone screen eats a large fraction of the
/// already-tight width and squeezes every list/card behind it.
const _kRailBreakpoint = 768.0;

/// The Social tab's own internal shell — Home / Messages / Search / Profile
/// behind a navigation rail on wide screens, or a compact bottom bar on
/// phones. Never both at once, and never the wide rail on a narrow screen.
class SocialShell extends StatefulWidget {
  final CampusRepository repository;
  const SocialShell({super.key, required this.repository});

  @override
  State<SocialShell> createState() => _SocialShellState();
}

class _SocialShellState extends State<SocialShell> {
  int _index = 0;
  List<String> _peers = const [];

  @override
  void initState() {
    super.initState();
    widget.repository.getLeaderboard().then((entries) {
      if (!mounted) return;
      setState(() => _peers =
          entries.where((e) => !e.isMe).map((e) => e.name).toList());
    });
  }

  @override
  Widget build(BuildContext context) {
    final content = IndexedStack(index: _index, children: [
      SocialScreen(repository: widget.repository),
      ChatThreadsScreen(repository: widget.repository, knownPeers: _peers),
      PeopleScreen(repository: widget.repository),
      SocialProfileScreen(repository: widget.repository),
    ]);

    return LayoutBuilder(builder: (context, constraints) {
      final isWide = constraints.maxWidth >= _kRailBreakpoint;
      if (isWide) {
        return Scaffold(
          backgroundColor: Colors.transparent,
          body: Row(children: [
            NavigationRail(
              backgroundColor: ArucadColors.primary,
              selectedIndex: _index,
              onDestinationSelected: (value) => setState(() => _index = value),
              labelType: NavigationRailLabelType.all,
              indicatorColor: Colors.white.withValues(alpha: .18),
              unselectedIconTheme: const IconThemeData(color: Colors.white70),
              selectedIconTheme: const IconThemeData(color: Colors.white),
              unselectedLabelTextStyle: const TextStyle(color: Colors.white70, fontSize: 11),
              selectedLabelTextStyle: const TextStyle(
                  color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700),
              destinations: const [
                NavigationRailDestination(
                    icon: Icon(Icons.home_outlined),
                    selectedIcon: Icon(Icons.home),
                    label: Text('Home')),
                NavigationRailDestination(
                    icon: Icon(Icons.chat_bubble_outline),
                    selectedIcon: Icon(Icons.chat_bubble),
                    label: Text('Mesajlar')),
                NavigationRailDestination(
                    icon: Icon(Icons.search),
                    selectedIcon: Icon(Icons.search),
                    label: Text('Arama')),
                NavigationRailDestination(
                    icon: Icon(Icons.person_outline),
                    selectedIcon: Icon(Icons.person),
                    label: Text('Profil')),
              ],
            ),
            Expanded(child: content),
          ]),
        );
      }
      return Scaffold(
        backgroundColor: Colors.transparent,
        body: content,
        bottomNavigationBar: NavigationBarTheme(
          data: NavigationBarThemeData(
            iconTheme: WidgetStateProperty.resolveWith((states) => const IconThemeData(size: 22)),
          ),
          child: NavigationBar(
            height: 60,
            selectedIndex: _index,
            onDestinationSelected: (value) => setState(() => _index = value),
            labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
                  fontSize: states.contains(WidgetState.selected) ? 11.5 : 11,
                  fontWeight: states.contains(WidgetState.selected)
                      ? FontWeight.w700
                      : FontWeight.w500,
                )),
            destinations: const [
              NavigationDestination(
                  icon: Icon(Icons.home_outlined),
                  selectedIcon: Icon(Icons.home),
                  label: 'Home'),
              NavigationDestination(
                  icon: Icon(Icons.chat_bubble_outline),
                  selectedIcon: Icon(Icons.chat_bubble),
                  label: 'Mesajlar'),
              NavigationDestination(
                  icon: Icon(Icons.search), selectedIcon: Icon(Icons.search), label: 'Arama'),
              NavigationDestination(
                  icon: Icon(Icons.person_outline),
                  selectedIcon: Icon(Icons.person),
                  label: 'Profil'),
            ],
          ),
        ),
      );
    });
  }
}
