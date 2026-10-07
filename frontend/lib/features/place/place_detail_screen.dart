import 'dart:typed_data';
import 'package:arucad_campus_prototype/features/widgets/top_notice.dart';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';

import 'package:arucad_campus_prototype/core/config/campus_geofence.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/notification_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/campus_map_launcher.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// How close (in meters) the device's real GPS position must be to a place
/// before a check-in there is accepted.
const _checkInRadiusMeters = 150.0;

/// Drop internal catalog notes students should never see.
String _studentFacingDescription(String raw) => raw
    .replaceAll(RegExp(r'\s*\(legacy id:[^)]*\)', caseSensitive: false), '')
    .trim();

String _studentFacingMeta(CampusPlace place) {
  final parts = <String>[
    if (place.distance.trim().isNotEmpty && place.distance.trim() != '.')
      place.distance.trim(),
    if (place.street.trim().isNotEmpty && place.street.trim() != '.')
      place.street.trim(),
  ];
  return parts.join(' · ');
}

class PlaceDetailScreen extends StatefulWidget {
  final CampusPlace place;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final bool canSetCover;

  const PlaceDetailScreen({
    super.key,
    required this.place,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.canSetCover = false,
  });

  @override
  State<PlaceDetailScreen> createState() => _PlaceDetailScreenState();
}

class _PlaceDetailScreenState extends State<PlaceDetailScreen> {
  late Future<List<Review>> _reviewsFuture;
  late Future<List<FeedPost>> _feedFuture;
  final List<Uint8List> _localPhotos = [];
  int _visibleReviews = kPageSize;
  String? _coverPhoto;
  bool _checkingIn = false;

  CampusPlace get place => widget.place;
  CampusRepository get repository => widget.repository;

  @override
  void initState() {
    super.initState();
    _reviewsFuture = repository.getReviews(place.id);
    _feedFuture = repository.getFeed();
    _coverPhoto = place.coverUrl;
  }

  Future<void> _setCoverPhoto(BuildContext context) async {
    final bytes = await PhotoPickerService.pick(context, imageQuality: 75, maxWidth: 1080);
    if (bytes == null) return;
    try {
      final media = await repository.uploadMedia(bytes, fileName: '${place.name}-cover.jpg');
      await repository.markMediaUsed(media.id, 'place-cover:${place.id}');
      await repository.setPlaceCover(place.id, media.displaySrc);
      if (!mounted) return;
      setState(() => _coverPhoto = media.displaySrc);
    } catch (e) {
      if (!context.mounted) return;
      final denied = e is ApiClientException && e.statusCode == 403;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(denied
            ? 'Kapak fotografini yuklemen icin yetkin yok.'
            : e is ApiClientException
                ? e.message
                : 'Kapak fotografı yuklenemedi.'),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(place.name),
        leading: CampusBackButton(),
        actions: [
          IconButton(
            tooltip: strings.t('place_report'),
            onPressed: () => _report(context),
            icon: Icon(Icons.flag_outlined),
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: 760),
          child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(26),
            child: SizedBox(
              height: 210,
              child: Stack(fit: StackFit.expand, children: [
                GestureDetector(
                  onTap: () => _tour(context),
                  child: Builder(builder: (context) {
                    if (_coverPhoto != null) {
                      return _TourHintOverlay(
                          child: _CoverPhotoImage(src: _coverPhoto!));
                    }
                    final bundledPhoto = placePhoto(place.name);
                    if (bundledPhoto != null) {
                      return _TourHintOverlay(
                        child: Image.asset(
                          bundledPhoto,
                          fit: BoxFit.cover,
                          filterQuality: FilterQuality.medium,
                        ),
                      );
                    }
                    return Container(
                      color:
                          Theme.of(context).colorScheme.surfaceContainerHighest,
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            PlaceLineArtIcon(
                              placeName: place.name,
                              color:
                                  ArucadColors.primary.withValues(alpha: .78),
                              size: 92,
                            ),
                            const SizedBox(height: 6),
                            Text(AppLocale.of(context).t('place_tour_tap'),
                                style: TextStyle(
                                    fontWeight: FontWeight.w700,
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onSurface)),
                          ],
                        ),
                      ),
                    );
                  }),
                ),
                Positioned(
                  right: 10,
                  top: 10,
                  child: widget.canSetCover
                      ? IconButton.filled(
                          style: IconButton.styleFrom(
                              backgroundColor: Colors.black54),
                          tooltip: _coverPhoto == null
                              ? AppLocale.of(context).t('place_cover_add')
                              : AppLocale.of(context).t('place_cover_change'),
                          onPressed: () => _setCoverPhoto(context),
                          icon: const Icon(Icons.add_a_photo_outlined,
                              color: Colors.white, size: 20),
                        )
                      : const SizedBox.shrink(),
                ),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          Row(children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
              decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(999)),
              child: Text(place.category,
                  style: TextStyle(
                      fontWeight: FontWeight.w800,
                      color: Theme.of(context).colorScheme.onSurface)),
            ),
            Spacer(),
            Text('⭐ ${place.rating}',
                style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: 10),
          Text(place.name,
              style: Theme.of(context)
                  .textTheme
                  .headlineMedium
                  ?.copyWith(fontWeight: FontWeight.w900)),
          Builder(builder: (context) {
            final meta = _studentFacingMeta(place);
            final desc = _studentFacingDescription(place.description);
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (meta.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(meta),
                ],
                if (desc.isNotEmpty) ...[
                  SizedBox(height: 14),
                  Text(desc, style: Theme.of(context).textTheme.bodyLarge),
                ],
                SizedBox(height: 18),
              ],
            );
          }),
          Wrap(spacing: 8, runSpacing: 8, children: [
            PillChip(
                icon: Icons.people_outline,
                label: campusDensityInfo(place).$2,
                color: campusDensityInfo(place).$1),
            PillChip(
                icon: Icons.photo_library_outlined,
                label: '${place.photos} memories'),
            PillChip(
                icon: Icons.accessible_forward_outlined,
                label: place.accessible ? 'Accessible' : 'Check access'),
          ]),
          const SizedBox(height: 20),
          Row(children: [
            Expanded(
                child: FilledButton.icon(
                    // Disabled while a check-in is in flight: locating and
                    // confirming takes a moment, and with no feedback the
                    // button reads as broken and gets tapped repeatedly —
                    // which is also how duplicate check-ins happen.
                    onPressed: _checkingIn ? null : () => _checkIn(context),
                    icon: _checkingIn
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.verified_outlined),
                    label: Text(strings.t(
                        _checkingIn ? 'place_checkin_busy' : 'place_checkin')))),
            SizedBox(width: 10),
            Expanded(
                child: OutlinedButton.icon(
                    onPressed: () => _navigate(context),
                    icon: const Icon(Icons.directions_walk),
                    label: Text(strings.t('place_navigate')))),
          ]),
          const SizedBox(height: 10),
          OutlinedButton.icon(
              onPressed: () => _openOnMap(context),
              icon: Icon(Icons.map_outlined),
              label: Text(AppLocale.of(context).t('pd_show_on_map'))),
          const SizedBox(height: 10),
          OutlinedButton.icon(
              onPressed: () => _tour(context),
              icon: Icon(Icons.threesixty),
              label: Text(strings.t('place_tour'))),
          const SizedBox(height: 22),
          // Photos / Gallery
          Card(
            child: Padding(
              padding: EdgeInsets.all(12),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Text(strings.t('place_photos'),
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                      const Spacer(),
                      TextButton.icon(
                          onPressed: () => _addPhoto(context),
                          icon: const Icon(Icons.add_a_photo_outlined),
                          label: const Text('Add'))
                    ]),
                    const SizedBox(height: 8),
                    if (_localPhotos.isEmpty)
                      Text(AppLocale.of(context).t('pd_no_photos'))
                    else
                      SizedBox(
                        height: 100,
                        child: ListView.separated(
                          scrollDirection: Axis.horizontal,
                          itemBuilder: (_, i) => ClipRRect(
                              borderRadius: BorderRadius.circular(8),
                              child: Image.memory(_localPhotos[i],
                                  width: 140, height: 100, fit: BoxFit.cover)),
                          separatorBuilder: (_, __) => const SizedBox(width: 8),
                          itemCount: _localPhotos.length,
                        ),
                      ),
                  ]),
            ),
          ),
          const SizedBox(height: 14),
          _buildReviewsCard(context),
          const SizedBox(height: 14),
          _buildRecentCheckInsCard(context),
        ],
          ),
        ),
      ),
    );
  }

  Widget _buildReviewsCard(BuildContext context) {
    final strings = AppLocale.of(context);
    return Card(
      child: Padding(
        padding: EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Text(strings.t('place_reviews'),
                  style: TextStyle(fontWeight: FontWeight.w900)),
              const Spacer(),
              TextButton.icon(
                  onPressed: () => _addReview(context),
                  icon: Icon(Icons.star_outline),
                  label: Text('Puanla')),
            ]),
            SizedBox(height: 6),
            FutureBuilder<List<Review>>(
              future: _reviewsFuture,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 16),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                final reviews = snap.data ?? const <Review>[];
                if (reviews.isEmpty) {
                  return Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text(AppLocale.of(context).t('pd_no_reviews')),
                  );
                }
                final shown = _visibleReviews.clamp(0, reviews.length);
                return Column(
                  children: [
                    for (var i = 0; i < shown; i++) _ReviewTile(review: reviews[i]),
                    LoadMoreButton(
                      shown: shown,
                      total: reviews.length,
                      itemLabel: 'yorum',
                      onTap: () => setState(() => _visibleReviews += kPageSize),
                    ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRecentCheckInsCard(BuildContext context) {
    return Card(
      child: Padding(
        padding: EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(AppLocale.of(context).t('place_recent_checkins'),
                style: const TextStyle(fontWeight: FontWeight.w900)),
            SizedBox(height: 6),
            FutureBuilder<List<FeedPost>>(
              future: _feedFuture,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 16),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                final posts = (snap.data ?? const <FeedPost>[])
                    .where((post) =>
                        post.text.toLowerCase().contains(place.name.toLowerCase()))
                    .take(4)
                    .toList();
                if (posts.isEmpty) {
                  return Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text(AppLocale.of(context).t('pd_no_checkins')),
                  );
                }
                return Column(
                  children: [
                    for (final post in posts)
                      Padding(
                        padding: EdgeInsets.symmetric(vertical: 6),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.person_pin_circle_outlined,
                                size: 20, color: ArucadColors.primary),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('${post.name} ${post.text}',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w700)),
                                  Text(post.meta,
                                      style: const TextStyle(
                                          color: ArucadColors.muted,
                                          fontSize: 12)),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _checkIn(BuildContext context) async {
    if (_checkingIn) return;
    setState(() => _checkingIn = true);
    try {
      await _runCheckIn(context);
    } finally {
      if (mounted) setState(() => _checkingIn = false);
    }
  }

  Future<void> _runCheckIn(BuildContext context) async {
    final position = await _requirePosition(context);
    if (position == null || !context.mounted) return;
    if (!CampusGeofence.contains(position.latitude, position.longitude)) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(AppLocale.of(context).t('pd_checkin_on_campus_only')),
      ));
      return;
    }

    final choice = await showModalBottomSheet<bool>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(AppLocale.of(context).t('pd_checkin_ok')),
              subtitle: Text(AppLocale.of(context).t('pd_xp_once')),
            ),
            ListTile(
              leading: const Icon(Icons.lock_outline),
              title: const Text('Gizlice XP kazan'),
              subtitle: Text(AppLocale.of(context).t('pd_not_in_feed')),
              onTap: () => Navigator.pop(ctx, false),
            ),
            ListTile(
              leading: const Icon(Icons.share_outlined),
              title: Text(AppLocale.of(context).t('pd_share_social')),
              subtitle: const Text('Feed + +10 XP'),
              onTap: () => Navigator.pop(ctx, true),
            ),
          ],
        ),
      ),
    );
    if (choice == null || !context.mounted) return;

    try {
      await repository.checkIn(
        place.id,
        visibleToOthers: choice,
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
      );
    } on ApiClientException catch (e) {
      if (!context.mounted) return;
      final msg = switch (e.code) {
        'CHECKIN_OFF_CAMPUS' =>
          'Check-in yalnızca ARUCAD kampüs sınırları içinde yapılabilir.',
        'CHECKIN_TOO_FAR' => () {
            final meters = (e.details?['distanceMeters'] as num?)?.toDouble();
            if (meters == null) {
              return 'Bu mekâna çok uzaksın — check-in yapılamadı.';
            }
            if (meters >= 1000) {
              return 'Bu mekâna ~${(meters / 1000).toStringAsFixed(1)} km uzaksın — yakında olmalısın.';
            }
            return 'Bu mekâna ~${meters.round()} m uzaksın (izin verilen yarıçap aşıldı).';
          }(),
        'ALREADY_CHECKED_IN' => 'Bu mekânda yakın zamanda check-in yaptın.',
        'PLACE_LOCATION_UNKNOWN' => 'Bu mekânın koordinatı yok — check-in kapalı.',
        _ => e.message,
      };
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
      return;
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('pd_checkin_failed'))));
      return;
    }
    if (!context.mounted) return;
    widget.analyticsTracker.track('place_check_in', {
      'placeId': place.id,
      'placeName': place.name,
      'visibleToOthers': choice,
      'verifiedNearby': true,
    });

    await NotificationService()
        .showSimple('Check-in başarılı', '${place.name} · +10 XP');
    if (!context.mounted) return;
    setState(() {
      _feedFuture = repository.getFeed();
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(choice
            ? '${place.name} için check-in yapıldı · sosyal akışa eklendi · +10 XP'
            : '${place.name} için check-in yapıldı · gizli (+10 XP)')));
  }

  /// GPS is required for check-in. Missing/denied location never invents a
  /// fake coordinate — the action is refused with a clear message.
  Future<Position?> _requirePosition(BuildContext context) async {
    try {
      const location = LocationService();
      final status = await location.checkAndRequestPermission();
      if (status == LocationAccessStatus.serviceDisabled) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(AppLocale.of(context).t('pd_location_off'))));
        }
        return null;
      }
      if (!status.isGranted) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(AppLocale.of(context).t('pd_location_denied'))));
        }
        return null;
      }
      // Speed: `.high` accuracy makes the OS wait for the best possible GPS
      // fix, which can take many seconds indoors or on a cold start. The
      // check-in radius is ~150m and the backend re-validates distance
      // itself, so `.medium` is plenty and resolves far faster.
      //
      // Faster still: a student on campus has almost certainly been located
      // already (Home and the map track it), so a recent fix is reused
      // rather than asking the GPS again. Anything older than that is not
      // trustworthy for a 150m radius decision, so it falls back to a real
      // fix rather than checking someone in from yesterday's position.
      final position = LocationService.lastKnownIsFresh
          ? LocationService.lastKnown
          : await location.getCurrentPosition(
              settings: const LocationSettings(accuracy: LocationAccuracy.medium));
      if (position == null) return null;

      final meters = Geolocator.distanceBetween(
          position.latitude, position.longitude, place.lat, place.lng);
      if (meters > _checkInRadiusMeters) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(AppLocale.of(context)
                  .t('pd_too_far')
                  .replaceAll('{d}', '${meters.round()}')
                  .replaceAll('{r}', '${_checkInRadiusMeters.round()}'))));
        }
        return null;
      }
      return position;
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(AppLocale.of(context).t('pd_location_failed'))));
      }
      return null;
    }
  }

  void _navigate(BuildContext context) {
    widget.analyticsTracker.track('route_started', {'place': place.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: place.name,
            destination: GeoPoint(place.lat, place.lng),
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
  }

  Future<void> _openOnMap(BuildContext context) async {
    List<CampusPlace> places = [place];
    List<CampusEvent> events = const [];
    try {
      final results = await Future.wait([
        widget.repository.getPlaces(),
        widget.repository.getEvents(),
      ]);
      places = results[0] as List<CampusPlace>;
      events = results[1] as List<CampusEvent>;
    } catch (_) {}
    if (!context.mounted) return;
    await openCampusMapHub(
      context,
      places: places,
      events: events,
      repository: widget.repository,
      mapProvider: widget.mapProvider,
      analyticsTracker: widget.analyticsTracker,
      onOpenGalatea: () {},
      focusPlaceId: place.id,
    );
  }

  Future<void> _tour(BuildContext context) async {
    final tour = resolvePlaceTour(place);
    widget.analyticsTracker.track(
        'tour_opened', {'place': place.name, 'tourUrl': tour.url});
    await open360Tour(
      context,
      tour.url,
      tourTarget: tour.target,
      title: place.name,
    );
  }

  Future<void> _addReview(BuildContext context) async {
    int rating = 5;
    final controller = TextEditingController();
    final ok = await showDialog<bool?>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text('${place.name} için puan ver'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  for (var i = 1; i <= 5; i++)
                    IconButton(
                      onPressed: () => setDialogState(() => rating = i),
                      icon: Icon(
                          i <= rating ? Icons.star : Icons.star_border,
                          color: ArucadColors.primary),
                    ),
                ],
              ),
              TextField(
                controller: controller,
                maxLines: 3,
                decoration:
                    const InputDecoration(hintText: 'Yorumunu yaz (opsiyonel)'),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.of(ctx).pop(false),
                child: Text(AppLocale.of(context).t('act_cancel'))),
            FilledButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: Text(AppLocale.of(context).t('act_send'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await repository.addReview(place.id, rating, controller.text.trim());
    } on ContentModerationException catch (e) {
      if (!context.mounted) return;
      showTopNotice(context, message: e.reason, kind: TopNoticeKind.error);
      return;
    }
    if (!context.mounted) return;
    widget.analyticsTracker
        .track('review_added', {'placeId': place.id, 'rating': rating});
    setState(() {
      _reviewsFuture = repository.getReviews(place.id);
    });
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(AppLocale.of(context).t('pd_review_thanks'))));
  }

  Future<void> _report(BuildContext context) async {
    final reasons = [
      'Yanlış/eksik bilgi',
      'Uygunsuz içerik',
      'Erişilebilirlik sorunu',
      'Diğer',
    ];
    final controller = TextEditingController();
    String selected = reasons.first;
    final ok = await showDialog<bool?>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(AppLocale.of(context).t('pd_report_place')),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final reason in reasons)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: Icon(
                      reason == selected
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      color: reason == selected ? ArucadColors.primary : null),
                  title: Text(reason),
                  onTap: () => setDialogState(() => selected = reason),
                ),
              TextField(
                controller: controller,
                decoration:
                    InputDecoration(hintText: AppLocale.of(context).t('pd_note_optional')),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.of(ctx).pop(false),
                child: Text(AppLocale.of(context).t('act_cancel'))),
            FilledButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: Text(AppLocale.of(context).t('act_send'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    final detail = controller.text.trim();
    await repository.reportPlace(
        place.id, detail.isEmpty ? selected : '$selected · $detail');
    if (!context.mounted) return;
    widget.analyticsTracker
        .track('place_reported', {'placeId': place.id, 'reason': selected});
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(AppLocale.of(context).t('pd_report_received'))));
  }

  Future<void> _addPhoto(BuildContext context) async {
    final bytes = await PhotoPickerService.pick(context);
    if (bytes == null) return;
    setState(() => _localPhotos.insert(0, bytes));
    widget.analyticsTracker.track('photo_added', {'placeId': place.id});
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AppLocale.of(context).t('pd_photo_added'))));
  }
}

/// The hero is the 360° tour's tap target. With a photo behind it the
/// placeholder's hint is gone, so keep the hint over the photo — scrimmed
/// so it stays readable on a bright cover.
class _TourHintOverlay extends StatelessWidget {
  final Widget child;
  const _TourHintOverlay({required this.child});

  @override
  Widget build(BuildContext context) {
    return Stack(fit: StackFit.expand, children: [
      child,
      const DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.center,
            end: Alignment.bottomCenter,
            colors: [Colors.transparent, Colors.black54],
          ),
        ),
      ),
      Align(
        alignment: Alignment.bottomLeft,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.threesixty, color: Colors.white, size: 20),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                AppLocale.of(context).t('place_tour_tap'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    color: Colors.white, fontWeight: FontWeight.w700),
              ),
            ),
          ]),
        ),
      ),
    ]);
  }
}

class _CoverPhotoImage extends StatelessWidget {
  final String src;
  const _CoverPhotoImage({required this.src});

  @override
  Widget build(BuildContext context) {
    try {
      return mediaPreview(src);
    } catch (_) {
      return Container(
          color: ArucadColors.mist,
          child: const Icon(Icons.broken_image_outlined, color: ArucadColors.muted));
    }
  }
}

class _ReviewTile extends StatelessWidget {
  final Review review;
  const _ReviewTile({required this.review});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Text(review.author,
                style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(width: 8),
            Row(children: [
              for (var i = 1; i <= 5; i++)
                Icon(i <= review.rating ? Icons.star : Icons.star_border,
                    size: 14, color: ArucadColors.primary),
            ]),
            const Spacer(),
            Text(review.meta,
                style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
          ]),
          if (review.comment.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(review.comment),
          ],
          const Divider(height: 18),
        ],
      ),
    );
  }
}
