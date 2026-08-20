class AppConfig {
  final String appName;
  final String apiBaseUrl;
  final bool demoMode;
  final String defaultLanguage;
  final List<String> supportedLanguages;

  const AppConfig({
    required this.appName,
    required this.apiBaseUrl,
    required this.demoMode,
    required this.defaultLanguage,
    required this.supportedLanguages,
  });

  factory AppConfig.demo() => const AppConfig(
        appName: 'AruSocial',
        apiBaseUrl: 'https://dev-api.arucad.example/api/v1',
        demoMode: true,
        defaultLanguage: 'tr',
        supportedLanguages: ['tr', 'en', 'ru'],
      );
}
