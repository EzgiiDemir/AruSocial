/// A plain latitude/longitude pair — this app's own coordinate type,
/// independent of any map SDK. Nothing in the codebase needs to depend on
/// an external maps package just to pass a position around; only the map
/// engine (`maplibre_campus_map.dart`) converts these to MapLibre's own
/// `LatLng` at the rendering boundary.
class GeoPoint {
  final double lat;
  final double lng;
  const GeoPoint(this.lat, this.lng);

  @override
  bool operator ==(Object other) =>
      other is GeoPoint && other.lat == lat && other.lng == lng;

  @override
  int get hashCode => Object.hash(lat, lng);

  @override
  String toString() => 'GeoPoint($lat, $lng)';
}
