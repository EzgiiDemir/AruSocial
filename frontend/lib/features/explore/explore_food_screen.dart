import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/explore/explore_tiles.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExploreFoodScreen extends StatefulWidget {
  final CampusRepository repository;

  const ExploreFoodScreen({super.key, required this.repository});

  @override
  State<ExploreFoodScreen> createState() => _ExploreFoodScreenState();
}

class _ExploreFoodScreenState extends State<ExploreFoodScreen> {
  bool _loading = true;
  List<CampusFoodVenue> _foodVenues = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final food = await widget.repository.getFoodVenues();
      if (!mounted) return;
      setState(() {
        _foodVenues = food;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(strings.t('discover_food'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _foodVenues.isEmpty
                  ? ListView(
                      children: const [
                        SizedBox(height: 120),
                        Center(
                          child: Text('Henüz yemek noktası yok',
                              style: TextStyle(color: ArucadColors.muted)),
                        ),
                      ],
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
                      itemCount: _foodVenues.length,
                      itemBuilder: (context, index) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: ExploreFoodVenueTile(
                          venue: _foodVenues[index],
                          accent: brandAccentAt(index),
                        ),
                      ),
                    ),
            ),
    );
  }
}
