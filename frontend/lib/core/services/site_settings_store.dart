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

/// Admin-entered configuration for real external integrations (Microsoft
/// Entra sign-in, a WordPress/WPForms data source). Unlike
/// `AdminContentStore` (seed campus content), these are credentials, so
/// nothing here ships with a default value — the app works with none of
/// this set, and only attempts the real integration once an admin fills it
/// in from the Admin Panel's Site Settings tab.
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
