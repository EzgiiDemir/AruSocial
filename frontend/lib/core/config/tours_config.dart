class TourConfig {
  final String campusId;
  final String tourUrl;
  final double latitude;
  final double longitude;

  const TourConfig(
      {required this.campusId,
      required this.tourUrl,
      required this.latitude,
      required this.longitude});
}

// Coordinates match the verified entries in poi_config.dart (main campus
// entrance, Nicosia Bandabuliya, the Iris/Age of Bronze workshop cluster) —
// no longer placeholders.
const tours = <TourConfig>[
  TourConfig(
    campusId: 'main',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=main',
    latitude: 35.337395,
    longitude: 33.321358,
  ),
  TourConfig(
    campusId: 'bandabuliya',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=bandabuliya',
    latitude: 35.175513,
    longitude: 33.365029,
  ),
  TourConfig(
    campusId: 'atelier',
    tourUrl: 'https://360.arucad.edu.tr/tour?campusId=atelier',
    latitude: 35.333593,
    longitude: 33.330680,
  ),
];
