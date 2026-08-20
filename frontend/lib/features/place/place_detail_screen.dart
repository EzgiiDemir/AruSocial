import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/media_library_store.dart';
import 'package:arucad_campus_prototype/core/services/notification_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/services/place_photo_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// How close (in meters) the device's real GPS position must be to a place
/// before a check-in there is accepted.
const _checkInRadiusMeters = 150.0;

class PlaceDetailScreen extends StatefulWidget {
  final CampusPlace place;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  const PlaceDetailScreen({
    super.key,
    required this.place,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
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

  CampusPlace get place => widget.place;
  CampusRepository get repository => widget.repository;

  @override
  void initState() {
    super.initState();
    _reviewsFuture = repository.getReviews(place.id);
    _feedFuture = repository.getFeed();
    PlacePhotoStore.photoFor(place.id).then((url) {
      if (mounted && url != null) setState(() => _coverPhoto = url);
    });
  }

  Future<void> _setCoverPhoto(BuildContext context) async {
    final bytes = await PhotoPickerService.pick(context, imageQuality: 75, maxWidth: 1080);
    if (bytes == null) return;
    // Also registers in the Media Library (with a real "used in" tag) so an
    // admin can see it centrally, not just on this one place's hero.
    final media = await MediaLibraryStore.upload(bytes,
        fileName: '${place.name}-cover.jpg', uploadedBy: 'Öğrenci');
    await MediaLibraryStore.markUsed(media.id, 'place-cover:${place.id}');
    await PlacePhotoStore.setPhoto(place.id, media.dataUri);
    if (!mounted) return;
    setState(() => _coverPhoto = media.dataUri);
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(place.name),
        actions: [
          IconButton(
            tooltip: strings.t('place_report'),
            onPressed: () => _report(context),
            icon: const Icon(Icons.flag_outlined),
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
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
                  child: _coverPhoto == null
                      ? Container(
                          color: ArucadColors.mist,
                          child: const Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.threesixty, size: 72, color: ArucadColors.primary),
                                SizedBox(height: 6),
                                Text('360° turu görüntülemek için dokun',
                                    style: TextStyle(fontWeight: FontWeight.w700)),
                              ],
                            ),
                          ),
                        )
                      : _CoverPhotoImage(dataUri: _coverPhoto!),
                ),
                Positioned(
                  right: 10,
                  top: 10,
                  child: IconButton.filled(
                    style: IconButton.styleFrom(backgroundColor: Colors.black54),
                    tooltip: _coverPhoto == null ? 'Kapak fotoğrafı ekle' : 'Kapak fotoğrafını değiştir',
                    onPressed: () => _setCoverPhoto(context),
                    icon: const Icon(Icons.add_a_photo_outlined, color: Colors.white, size: 20),
                  ),
                ),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          Row(children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
              decoration: BoxDecoration(
                  color: ArucadColors.mist,
                  borderRadius: BorderRadius.circular(999)),
              child: Text(place.category,
                  style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
            const Spacer(),
            Text('⭐ ${place.rating}',
                style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: 10),
          Text(place.name,
              style: Theme.of(context)
                  .textTheme
                  .headlineMedium
                  ?.copyWith(fontWeight: FontWeight.w900)),
          const SizedBox(height: 4),
          Text('${place.distance} · ${place.street}'),
          const SizedBox(height: 14),
          Text(place.description, style: Theme.of(context).textTheme.bodyLarge),
          const SizedBox(height: 18),
          Wrap(spacing: 8, runSpacing: 8, children: [
            PillChip(icon: Icons.people_outline, label: place.density),
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
                    onPressed: () => _checkIn(context),
                    icon: const Icon(Icons.verified_outlined),
                    label: Text(strings.t('place_checkin')))),
            const SizedBox(width: 10),
            Expanded(
                child: OutlinedButton.icon(
                    onPressed: () => _navigate(context),
                    icon: const Icon(Icons.directions_walk),
                    label: Text(strings.t('place_navigate')))),
          ]),
          const SizedBox(height: 10),
          OutlinedButton.icon(
              onPressed: () => _tour(context),
              icon: const Icon(Icons.threesixty),
              label: Text(strings.t('place_tour'))),
          const SizedBox(height: 22),
          // Photos / Gallery
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
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
                      const Text('Bu oturumda henüz fotoğraf eklenmedi')
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
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Text(strings.t('place_reviews'),
                  style: const TextStyle(fontWeight: FontWeight.w900)),
              const Spacer(),
              TextButton.icon(
                  onPressed: () => _addReview(context),
                  icon: const Icon(Icons.star_outline),
                  label: const Text('Puanla')),
            ]),
            const SizedBox(height: 6),
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
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text('Henüz yorum yok · ilk yorumu sen ekle.'),
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
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(AppLocale.of(context).t('place_recent_checkins'),
                style: const TextStyle(fontWeight: FontWeight.w900)),
            const SizedBox(height: 6),
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
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text('Bu yerde henüz yakın zamanda check-in yok.'),
                  );
                }
                return Column(
                  children: [
                    for (final post in posts)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
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
    final proximity = await _checkProximity();

    final visibleToOthers = await AppSettingsStore.checkInVisible();
    if (!context.mounted) return;
    try {
      await repository.checkIn(place.id, visibleToOthers: visibleToOthers);
    } catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Check-in kaydedilemedi. Lütfen tekrar dene.')));
      return;
    }
    if (!context.mounted) return;
    widget.analyticsTracker.track('place_check_in', {
      'placeId': place.id,
      'placeName': place.name,
      'visibleToOthers': visibleToOthers,
      'verifiedNearby': proximity.verified,
    });

    await NotificationService()
        .showSimple('Check-in başarılı', '${place.name} · +30 XP');
    if (!context.mounted) return;
    setState(() => _feedFuture = repository.getFeed());
    // Always saves — a check-in isn't blocked on GPS being available or
    // matching, but it honestly says so when the position couldn't be
    // confirmed, rather than silently claiming "buradasın" either way.
    final locationNote = proximity.verified ? '' : ' · ${proximity.note}';
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(visibleToOthers
            ? '${place.name} için check-in yapıldı · sosyal akışa eklendi · +30 XP$locationNote'
            : '${place.name} için check-in yapıldı · gizli (+30 XP)$locationNote')));
  }

  /// Best-effort real GPS proximity check — never blocks the check-in
  /// itself (denied permission, an unavailable location service, or
  /// actually being far away all still let the check-in through), but the
  /// result is surfaced honestly in the confirmation message instead of
  /// silently claiming "you're here" when that couldn't be confirmed.
  Future<({bool verified, String note})> _checkProximity() async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        return (verified: false, note: 'konum kapalı, uzaktan check-in');
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return (verified: false, note: 'konum izni yok, uzaktan check-in');
      }
      final position = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
      final meters = Geolocator.distanceBetween(
          position.latitude, position.longitude, place.lat, place.lng);
      if (meters > _checkInRadiusMeters) {
        return (verified: false, note: 'uzaktan check-in (~${meters.round()} m)');
      }
      return (verified: true, note: '');
    } catch (_) {
      return (verified: false, note: 'konum alınamadı, uzaktan check-in');
    }
  }

  void _navigate(BuildContext context) {
    widget.analyticsTracker.track('route_started', {'place': place.name});
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
            destinationName: place.name,
            destination: GeoPoint(place.lat, place.lng))));
  }

  Future<void> _tour(BuildContext context) async {
    if (place.tourUrl == null || place.tourUrl!.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('360° linki bu yer için mevcut değil.')));
      return;
    }

    widget.analyticsTracker.track(
        'tour_opened', {'place': place.name, 'tourUrl': place.tourUrl});
    await open360Tour(context, place.tourUrl!);
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
                child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: const Text('Gönder')),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await repository.addReview(place.id, rating, controller.text.trim());
    } on ContentModerationException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
      return;
    }
    if (!context.mounted) return;
    widget.analyticsTracker
        .track('review_added', {'placeId': place.id, 'rating': rating});
    setState(() => _reviewsFuture = repository.getReviews(place.id));
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('Yorumun eklendi, teşekkürler!')));
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
          title: const Text('Bu yeri şikayet et'),
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
                    const InputDecoration(hintText: 'Ek açıklama (opsiyonel)'),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.of(ctx).pop(false),
                child: const Text('Vazgeç')),
            FilledButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: const Text('Gönder')),
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
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Şikayetin alındı, kampüs ekibine iletilecek.')));
  }

  Future<void> _addPhoto(BuildContext context) async {
    final bytes = await PhotoPickerService.pick(context);
    if (bytes == null) return;
    setState(() => _localPhotos.insert(0, bytes));
    widget.analyticsTracker.track('photo_added', {'placeId': place.id});
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Fotoğraf eklendi')));
  }
}

class _CoverPhotoImage extends StatelessWidget {
  final String dataUri;
  const _CoverPhotoImage({required this.dataUri});

  @override
  Widget build(BuildContext context) {
    try {
      final bytes = base64Decode(dataUri.split(',').last);
      return Image.memory(bytes, fit: BoxFit.cover);
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
