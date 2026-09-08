/// Current conditions over the Kyrenia campus.
///
/// Comes from the backend's `/weather` endpoint (Open-Meteo, cached
/// server-side). Null anywhere in the UI means "no reading available right
/// now" — the row is hidden rather than showing an invented temperature.
class CampusWeather {
  final double temperatureC;
  final double feelsLikeC;
  final double windKph;

  /// WMO weather code, kept so the icon can be chosen without re-parsing
  /// the human-readable summary.
  final int code;
  final String summary;
  final bool isDay;

  const CampusWeather({
    required this.temperatureC,
    required this.feelsLikeC,
    required this.windKph,
    required this.code,
    required this.summary,
    required this.isDay,
  });

  factory CampusWeather.fromJson(Map<String, dynamic> json) => CampusWeather(
        temperatureC: (json['temperatureC'] as num?)?.toDouble() ?? 0,
        feelsLikeC: (json['feelsLikeC'] as num?)?.toDouble() ?? 0,
        windKph: (json['windKph'] as num?)?.toDouble() ?? 0,
        code: (json['code'] as num?)?.toInt() ?? 0,
        summary: json['summary'] as String? ?? '',
        isDay: json['isDay'] as bool? ?? true,
      );

  String get temperatureLabel => '${temperatureC.round()}°';

  String get feelsLikeLabel => 'Hissedilen ${feelsLikeC.round()}°';

  String get windLabel => 'Rüzgâr ${windKph.round()} km/s';
}
