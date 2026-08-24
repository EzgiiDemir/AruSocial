import 'package:shared_preferences/shared_preferences.dart';

/// Real Microsoft Entra app-registration values, entered by an admin instead
/// of hardcoded at build time. [redirectUri]'s *scheme* must still match
/// `android/app/build.gradle.kts`'s `appAuthRedirectScheme` — that part is
/// fixed at Android build time by the AppAuth plugin, so changing it for
/// real requires editing that file and rebuilding, not just this screen.
class EntraSiteConfig {
  final String tenantId;
  final String clientId;
  final String redirectUri;

  const EntraSiteConfig({
    required this.tenantId,
    required this.clientId,
    required this.redirectUri,
  });

  bool get isConfigured =>
      tenantId.isNotEmpty && clientId.isNotEmpty && redirectUri.isNotEmpty;
}

/// Where to pull WordPress/WPForms data from, and the token to authenticate
/// with. See [WordPressDataSource] for what these are actually used for.
class WordPressSiteConfig {
  final String siteUrl;
  final String apiToken;

  const WordPressSiteConfig({required this.siteUrl, required this.apiToken});

  bool get isConfigured => siteUrl.isNotEmpty;
}

/// Public site-settings payload the admin UI and REST client share.
/// [wordpressApiToken] is never filled from a GET body — REST
/// [SiteSettings.fromJson] always leaves it empty, even if a server
/// mistakenly echoed one. Mock mode may populate it from local prefs.
class SiteSettings {
  final EntraSiteConfig entra;
  final String wordpressSiteUrl;
  final bool wordpressApiTokenConfigured;
  final String wordpressApiToken;

  const SiteSettings({
    required this.entra,
    required this.wordpressSiteUrl,
    required this.wordpressApiTokenConfigured,
    this.wordpressApiToken = '',
  });

  factory SiteSettings.fromJson(Map<String, dynamic> json) {
    final entraRaw = json['entra'];
    final entra = entraRaw is Map
        ? Map<String, dynamic>.from(entraRaw)
        : const <String, dynamic>{};
    final wpRaw = json['wordpress'];
    final wp = wpRaw is Map
        ? Map<String, dynamic>.from(wpRaw)
        : const <String, dynamic>{};
    return SiteSettings(
      entra: EntraSiteConfig(
        tenantId: entra['tenantId'] as String? ?? '',
        clientId: entra['clientId'] as String? ?? '',
        redirectUri: entra['redirectUri'] as String? ?? '',
      ),
      wordpressSiteUrl: wp['siteUrl'] as String? ?? '',
      wordpressApiTokenConfigured: wp['apiTokenConfigured'] as bool? ?? false,
      wordpressApiToken: '',
    );
  }
}

/// On-device Entra / WordPress values used by Mock mode
/// (`USE_REST_API=false`) and by the non-REST Entra login path in
/// `main.dart`. REST mode does not treat this store as canonical —
/// `RestCampusRepository` reads and writes `GET/POST /admin/settings/site`.
class SiteSettingsStore {
  static const _kTenant = 'site.entra.tenantId';
  static const _kClient = 'site.entra.clientId';
  static const _kRedirect = 'site.entra.redirectUri';
  static const _kWpUrl = 'site.wordpress.siteUrl';
  static const _kWpToken = 'site.wordpress.apiToken';

  static Future<EntraSiteConfig> entra() async {
    final prefs = await SharedPreferences.getInstance();
    return EntraSiteConfig(
      tenantId: prefs.getString(_kTenant) ?? '',
      clientId: prefs.getString(_kClient) ?? '',
      redirectUri: prefs.getString(_kRedirect) ?? '',
    );
  }

  static Future<void> setEntra({
    required String tenantId,
    required String clientId,
    required String redirectUri,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kTenant, tenantId);
    await prefs.setString(_kClient, clientId);
    await prefs.setString(_kRedirect, redirectUri);
  }

  static Future<WordPressSiteConfig> wordpress() async {
    final prefs = await SharedPreferences.getInstance();
    return WordPressSiteConfig(
      siteUrl: prefs.getString(_kWpUrl) ?? '',
      apiToken: prefs.getString(_kWpToken) ?? '',
    );
  }

  static Future<void> setWordPress({
    required String siteUrl,
    required String apiToken,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kWpUrl, siteUrl);
    await prefs.setString(_kWpToken, apiToken);
  }
}
