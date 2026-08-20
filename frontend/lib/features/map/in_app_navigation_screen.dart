import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';

const _campusEntrance = GeoPoint(35.337395, 33.321358);

/// Turn-by-turn-style navigation rendered entirely on our own map — no
/// external Maps app, map SDK, or directions API is ever called. Real mode
/// selection (Yürüyerek/Araba/Otobüs/Kuş Bakışı) and a re-center control
/// make this a genuinely full-featured escort; the route itself is an
/// honest straight-line estimate paced per mode, since this prototype has
/// no real road-network data to route through (disclosed in the UI, not
/// hidden — see `docs/PUBLISH_READINESS.md`).
class InAppNavigationScreen extends StatefulWidget {
  final String destinationName;
  final GeoPoint destination;

  const InAppNavigationScreen({
    super.key,
    required this.destinationName,
    required this.destination,
  });

  @override
  State<InAppNavigationScreen> createState() => _InAppNavigationScreenState();
}

class _InAppNavigationScreenState extends State<InAppNavigationScreen> {
  final _mapController = CampusMapController();
  GeoPoint? _origin;
  List<GeoPoint> _extentPoints = const [];
  RouteResult? _route;
  TravelMode _mode = TravelMode.walking;
  bool _loading = true;
  bool _usedFallbackOrigin = false;
  StreamSubscription<Position>? _positionSub;

  @override
  void initState() {
    super.initState();
    _extentPoints = [widget.destination];
    _start();
  }

  @override
  void dispose() {
    _positionSub?.cancel();
    super.dispose();
  }

  /// Keeps following the student's real GPS position while they travel, so
  /// the "you are here" dot moves for real and the remaining distance/time
  /// keep counting down instead of freezing at the moment navigation
  /// started.
  void _startLiveTracking() {
    _positionSub?.cancel();
    _positionSub = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high, distanceFilter: 5),
    ).listen((position) {
      if (!mounted) return;
      final here = GeoPoint(position.latitude, position.longitude);
      setState(() {
        _origin = here;
        _route = _straightLineRoute(here, widget.destination);
      });
    }, onError: (_) {
      // Live tracking is a bonus on top of the one-shot origin fix; if the
      // stream fails we just keep showing the last known position.
    });
  }

  RouteResult _straightLineRoute(GeoPoint origin, GeoPoint destination) {
    final meters = Geolocator.distanceBetween(
        origin.lat, origin.lng, destination.lat, destination.lng);
    final minutes = (meters / _mode.fallbackMetersPerMinute).ceil();
    final suffix = switch (_mode) {
      TravelMode.walking => 'yürüyüş',
      TravelMode.driving => 'araba',
      TravelMode.transit => 'otobüs',
      TravelMode.birdseye => 'kuş uçuşu tahmini',
    };
    return RouteResult(
      points: [origin, destination],
      distanceText: '${meters.round()} m (kuş uçuşu)',
      durationText: '~$minutes dk $suffix',
    );
  }

  Future<void> _start() async {
    setState(() {
      _loading = true;
      _route = null;
    });
    final origin = _origin ?? await _resolveOrigin();
    if (!mounted) return;

    final route = _straightLineRoute(origin, widget.destination);
    setState(() {
      _origin = origin;
      _extentPoints = [origin, widget.destination];
      _route = route;
      _loading = false;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _mapController.fitPoints([origin, widget.destination]);
    });
    _startLiveTracking();
  }

  Future<void> _changeMode(TravelMode mode) async {
    if (mode == _mode) return;
    setState(() => _mode = mode);
    if (_origin != null) {
      setState(() => _route = _straightLineRoute(_origin!, widget.destination));
    } else {
      await _start();
    }
  }

  Future<GeoPoint> _resolveOrigin() async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        _usedFallbackOrigin = true;
        return _campusEntrance;
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        _usedFallbackOrigin = true;
        return _campusEntrance;
      }
      final position = await Geolocator.getCurrentPosition(
          locationSettings:
              const LocationSettings(accuracy: LocationAccuracy.high));
      return GeoPoint(position.latitude, position.longitude);
    } catch (_) {
      _usedFallbackOrigin = true;
      return _campusEntrance;
    }
  }

  /// Real re-center: snaps the view back to the student's current live
  /// position (or refits the whole route if we don't have one), so drifting
  /// away from the route while panning around never strands the view.
  void _recenter() {
    if (_origin != null && !_usedFallbackOrigin) {
      _mapController.centerOn(_origin!, zoom: 18);
    } else {
      _mapController.fitPoints(_extentPoints);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.destinationName)),
      body: Stack(children: [
        CampusMapView(
          extentPoints: _extentPoints,
          controller: _mapController,
          showUserLocation: !_usedFallbackOrigin,
          routePoints: _route?.points,
          routeDashed: true,
          markers: [
            CampusMapMarker(
              id: 'destination',
              position: widget.destination,
              label: widget.destinationName,
              color: ArucadColors.primary,
              emphasized: true,
            ),
            if (_origin != null && _usedFallbackOrigin)
              CampusMapMarker(
                id: 'origin',
                position: _origin!,
                label: 'Kampüs girişi',
                color: ArucadColors.blue,
              ),
          ],
        ),
        Positioned(
          top: 12,
          left: 12,
          right: 12,
          child: _ModeSelector(mode: _mode, onChanged: _changeMode),
        ),
        Positioned(
          right: 14,
          bottom: _route == null ? 14 : 92,
          child: _RecenterButton(onTap: _recenter),
        ),
        if (_loading)
          Positioned(
            top: 62,
            left: 16,
            right: 16,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2)),
                  const SizedBox(width: 12),
                  Text('${_mode.label} rotası hesaplanıyor...'),
                ]),
              ),
            ),
          ),
        if (_usedFallbackOrigin && !_loading)
          const Positioned(
            top: 62,
            left: 16,
            right: 16,
            child: Card(
              color: ArucadColors.mist,
              child: Padding(
                padding: EdgeInsets.all(12),
                child: Row(children: [
                  Icon(Icons.info_outline, size: 18, color: ArucadColors.muted),
                  SizedBox(width: 8),
                  Expanded(
                      child: Text(
                          'Konumun alınamadı, kampüs girişinden rota gösteriliyor.',
                          style: TextStyle(
                              fontSize: 12, color: ArucadColors.muted))),
                ]),
              ),
            ),
          ),
      ]),
      bottomNavigationBar: _route == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Row(children: [
                    Icon(_mode.icon, color: ArucadColors.blue),
                    const SizedBox(width: 8),
                    Text(_route!.durationText,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 16)),
                    const SizedBox(width: 8),
                    Text('· ${_route!.distanceText}',
                        style: const TextStyle(color: ArucadColors.muted)),
                    const Spacer(),
                    FilledButton.icon(
                        onPressed: () => Navigator.of(context).pop(),
                        icon: const Icon(Icons.close),
                        label: const Text('Bitir')),
                  ]),
                  const SizedBox(height: 4),
                  const Row(children: [
                    Icon(Icons.straighten, size: 13, color: ArucadColors.muted),
                    SizedBox(width: 5),
                    Expanded(
                      child: Text(
                        'Kendi haritamızda düz hat tahmini — ARUCAD kampüsü için gerçek yol ağı verisi ve dış API bağlantısı kullanılmıyor.',
                        style: TextStyle(fontSize: 10.5, color: ArucadColors.muted),
                      ),
                    ),
                  ]),
                ]),
              ),
            ),
    );
  }
}

class _ModeSelector extends StatelessWidget {
  final TravelMode mode;
  final ValueChanged<TravelMode> onChanged;
  const _ModeSelector({required this.mode, required this.onChanged});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        decoration: BoxDecoration(
          color: Theme.of(context).scaffoldBackgroundColor,
          borderRadius: BorderRadius.circular(999),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: .12), blurRadius: 10),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final m in TravelMode.values)
              Expanded(
                child: GestureDetector(
                  onTap: () => onChanged(m),
                  child: Container(
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: m == mode ? ArucadColors.primary : null,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Icon(m.icon,
                          size: 18, color: m == mode ? Colors.white : ArucadColors.muted),
                      const SizedBox(height: 2),
                      Text(m.label,
                          style: TextStyle(
                              fontSize: 9.5,
                              fontWeight: FontWeight.w700,
                              color: m == mode ? Colors.white : ArucadColors.muted)),
                    ]),
                  ),
                ),
              ),
          ],
        ),
      );
}

class _RecenterButton extends StatelessWidget {
  final VoidCallback onTap;
  const _RecenterButton({required this.onTap});

  @override
  Widget build(BuildContext context) => Material(
        color: Theme.of(context).scaffoldBackgroundColor,
        shape: const CircleBorder(),
        elevation: 3,
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: const Padding(
            padding: EdgeInsets.all(10),
            child: Icon(Icons.my_location, color: ArucadColors.primary, size: 20),
          ),
        ),
      );
}
