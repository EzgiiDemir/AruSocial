import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:geolocator/geolocator.dart';

import 'config/app_config.dart';
import '../core/auth/app_settings_store.dart';
import '../core/auth/biometric_auth_provider.dart';
import '../core/auth/entra_auth_provider.dart';
import '../core/auth/rest_auth_provider.dart';
import '../core/auth/session_store.dart';
import '../core/models/campus_models.dart';
import '../core/navigation/app_deep_link.dart';
import '../core/network/api_client.dart';
import '../core/services/audit_log_store.dart';
import '../core/services/contracts.dart';
import '../core/services/location_service.dart';
import '../core/services/mock_auth_provider.dart';
import '../core/services/push_notification_service.dart';
import '../core/services/push_payload.dart';
import '../core/l10n/app_strings.dart';
import '../core/theme/arucad_theme.dart';
import '../features/admin/admin_panel_screen.dart';
import '../features/auth/access_denied_screen.dart';
import '../features/auth/privacy_notice_screen.dart';
import '../features/auth/settings_screen.dart';
import '../features/campus_shell.dart';
import '../features/place/place_detail_screen.dart';
import '../features/social/chat_screen.dart';
import '../features/social/notifications_screen.dart';
import '../features/trainer/trainer_panel_screen.dart';
import '../features/widgets/moderation_notice.dart';

/// Lets code below the login screen show feedback (e.g. "location denied")
/// without needing a Scaffold ancestor at the exact point it's called from.
final GlobalKey<ScaffoldMessengerState> rootMessengerKey =
    GlobalKey<ScaffoldMessengerState>();

class ArucadCampusApp extends StatefulWidget {
  final AppConfig config;
  final CampusRepository repository;
  final AuthProvider authProvider;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  /// True only when this session started at the web `/admin` URL — a real
  /// separate entry point (see `main.dart`), not just a hidden button.
  final bool startInAdminMode;

  /// Same pattern for `/trainer` — the Trainer Panel, a third portal for
  /// department heads separate from both the student app and full admin.
  final bool startInTrainerMode;
  final Future<void> Function()? onReloadServices;

  const ArucadCampusApp({
    super.key,
    required this.config,
    required this.repository,
    required this.authProvider,
    required this.mapProvider,
    required this.analyticsTracker,
    this.startInAdminMode = false,
    this.startInTrainerMode = false,
    this.onReloadServices,
  });

  @override
  State<ArucadCampusApp> createState() => _ArucadCampusAppState();
}

class _ArucadCampusAppState extends State<ArucadCampusApp> {
  String _language = 'TR';

  @override
  void initState() {
    super.initState();
    AppSettingsStore.language().then((lang) {
      if (mounted) setState(() => _language = lang);
    });
  }

  Future<void> _setLanguage(String language) async {
    setState(() => _language = language);
    await AppSettingsStore.setLanguage(language);
  }

  Locale get _locale => switch (_language) {
        'EN' => const Locale('en'),
        'RU' => const Locale('ru'),
        _ => const Locale('tr'),
      };

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: widget.config.appName,
      locale: _locale,
      localizationsDelegates: [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: [
        const Locale('tr'),
        const Locale('en'),
        const Locale('ru')
      ],
      debugShowCheckedModeBanner: false,
      theme: ArucadTheme.data(),
      themeMode: ThemeMode.light,
      scaffoldMessengerKey: rootMessengerKey,
      builder: (context, child) => AppLocale(
        language: languageFromCode(_language),
        child: child ?? const SizedBox.shrink(),
      ),
      home: PrivacyNoticeGate(
        child: _DemoSession(
          config: widget.config,
          repository: widget.repository,
          authProvider: widget.authProvider,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
          startInAdminMode: widget.startInAdminMode,
          startInTrainerMode: widget.startInTrainerMode,
          onReloadServices: widget.onReloadServices,
          language: _language,
          onLanguageChanged: _setLanguage,
          initialDeepLink: AppDeepLink.tryParse(
              WidgetsBinding.instance.platformDispatcher.defaultRouteName),
        ),
      ),
      // The real /admin vs / split is decided once in main.dart by reading
      // Uri.base.path directly (see startInAdminMode) — this app never uses
      // named-route navigation. But on the web, Flutter's Navigator always
      // probes the browser's actual initial path as a *named* route first
      // (this overrides `initialRoute`, so setting that alone does nothing
      // here — confirmed in WidgetsApp's `_initialRouteName` getter), and
      // with no onGenerateRoute it finds nothing for "/admin" and logs a
      // "Could not navigate to initial route" warning before falling back
      // to `home` anyway. Answering every route name with the same `home`
      // content removes the dead-end without pretending this app has real
      // named routing.
      onGenerateRoute: (settings) => MaterialPageRoute(
        settings: settings,
        builder: (_) => _DemoSession(
          config: widget.config,
          repository: widget.repository,
          authProvider: widget.authProvider,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
          startInAdminMode: widget.startInAdminMode,
          startInTrainerMode: widget.startInTrainerMode,
          onReloadServices: widget.onReloadServices,
          language: _language,
          onLanguageChanged: _setLanguage,
          initialDeepLink: AppDeepLink.tryParse(settings.name),
        ),
      ),
    );
  }
}

class _DemoSession extends StatefulWidget {
  final AppConfig config;
  final CampusRepository repository;
  final AuthProvider authProvider;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final bool startInAdminMode;
  final bool startInTrainerMode;
  final Future<void> Function()? onReloadServices;
  final AppDeepLink? initialDeepLink;
  final String language;
  final ValueChanged<String> onLanguageChanged;

  const _DemoSession({
    required this.config,
    required this.repository,
    required this.authProvider,
    required this.mapProvider,
    required this.analyticsTracker,
    this.startInAdminMode = false,
    this.startInTrainerMode = false,
    this.onReloadServices,
    this.initialDeepLink,
    required this.language,
    required this.onLanguageChanged,
  });

  @override
  State<_DemoSession> createState() => _DemoSessionState();
}

class _DemoSessionState extends State<_DemoSession>
    with WidgetsBindingObserver {
  CampusUser? user;
  bool signedIn = false;
  bool loading = false;
  bool bootstrapping = true;
  String? error;
  UserRole role = UserRole.student;
  bool hasStoredSession = false;
  late final PushNotificationService _push;
  StreamSubscription<PushPayload>? _pushOpened;
  AppDeepLink? _pendingLink;

  String get language => widget.language;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _pendingLink = widget.initialDeepLink;
    _push = PushNotificationService.forRepository(widget.repository);
    _pushOpened = _push.opened.listen(_onPushOpened);
    unawaited(_restoreSessionIfAny());
    ApiClient.onSessionInvalid = _onSessionInvalid;
    ApiClient.onModerationNotice = _onModerationNotice;
  }

  /// Content published, but moderation had something to say.
  ///
  /// `support` is the one that must not be missed — a student who wrote
  /// about hurting themselves is being offered help — so it gets a dialog.
  /// A warning or a "queued for review" is informative rather than urgent,
  /// so it takes a snackbar and does not interrupt what they were doing.
  void _onModerationNotice(String status, String message) {
    if (!mounted) return;

    if (status == 'support') {
      final context = rootMessengerKey.currentContext;
      if (context != null) {
        unawaited(showModerationNotice(context, message: message, code: status));

        return;
      }
    }

    rootMessengerKey.currentState?.showSnackBar(SnackBar(
      content: Text(message),
      duration: const Duration(seconds: 6),
    ));
  }

  void _popToRoot() {
    final nav = Navigator.maybeOf(context, rootNavigator: true);
    nav?.popUntil((route) => route.isFirst);
  }

  void _resetSessionUi({String? message}) {
    if (!mounted) return;
    _popToRoot();
    setState(() {
      signedIn = false;
      user = null;
      error = message;
      role = UserRole.student;
      hasStoredSession = false;
    });
  }

  Future<void> _refreshStoredSessionFlag() async {
    final token = await widget.authProvider.getAccessToken();
    if (!mounted) return;
    setState(() => hasStoredSession = token != null && token.isNotEmpty);
  }

  void _onSessionInvalid(String code) {
    if (!mounted || !signedIn) return;
    final message = code == 'ACCOUNT_BANNED'
        ? 'Bu hesap askıya alındı. Lütfen öğrenci işleri ile iletişime geç.'
        : 'Oturumunuz sona erdi. Lütfen tekrar giriş yapın.';
    _resetSessionUi(message: message);
    unawaited(_push.stopAndUnregister());
    unawaited(widget.authProvider.signOut());
    if (code == 'ACCOUNT_BANNED') {
      unawaited(AppSettingsStore.disableBiometric());
    }
  }

  Future<void> _restoreSessionIfAny() async {
    try {
      final auth = widget.authProvider;
      if (auth is RestAuthProvider) {
        final biometricOn = await AppSettingsStore.biometricEnabled();
        final token = await SessionStore.token(portal: auth.portal);
        if (biometricOn && (token == null || token.isEmpty)) {
          await AppSettingsStore.disableBiometric();
          return;
        }
        if (biometricOn && token != null) {
          // Saved session is unlocked only via biometric or password.
          return;
        }
        final ok = await auth.restoreFromStore().timeout(
              const Duration(seconds: 5),
              onTimeout: () => false,
            );
        if (ok && mounted) {
          await _finishSignIn('session_restore', true);
          return;
        }
      }
    } on ApiClientException catch (e) {
      final auth = widget.authProvider;
      await SessionStore.clear(
          portal: auth is RestAuthProvider ? auth.portal : 'student');
      if (e.code == 'ACCOUNT_BANNED' && mounted) {
        setState(() => error = e.displayMessage);
        unawaited(AppSettingsStore.disableBiometric());
      }
    } catch (_) {
      final auth = widget.authProvider;
      await SessionStore.clear(
          portal: auth is RestAuthProvider ? auth.portal : 'student');
    } finally {
      await _refreshStoredSessionFlag();
      if (mounted) setState(() => bootstrapping = false);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pushOpened?.cancel();
    if (identical(ApiClient.onModerationNotice, _onModerationNotice)) {
      ApiClient.onModerationNotice = null;
    }
    if (identical(ApiClient.onSessionInvalid, _onSessionInvalid)) {
      ApiClient.onSessionInvalid = null;
    }
    unawaited(_push.dispose());
    super.dispose();
  }

  @override
  Future<bool> didPushRouteInformation(
      RouteInformation routeInformation) async {
    final link = AppDeepLink.tryParse(routeInformation.uri.toString()) ??
        AppDeepLink.tryParse(routeInformation.uri.path);
    if (link == null) return false;
    _pendingLink = link;
    if (signedIn) unawaited(_openDeepLink(link));
    return true;
  }

  void _onPushOpened(PushPayload payload) {
    if (!signedIn || !mounted) return;
    final routed = AppDeepLink.tryParse(payload.route);
    if (routed != null) {
      unawaited(_openDeepLink(routed));
      return;
    }
    final page = payload.opensChat
        ? ChatThreadScreen(repository: widget.repository, peer: payload.peer!)
        : NotificationsScreen(repository: widget.repository);
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));
  }

  Future<void> _openDeepLink(AppDeepLink link) async {
    if (!mounted) return;
    switch (link.kind) {
      case 'notifications':
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) =>
                NotificationsScreen(repository: widget.repository)));
        return;
      case 'chat':
        if (link.id == null || link.id!.isEmpty) return;
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => ChatThreadScreen(
                repository: widget.repository, peer: link.id!)));
        return;
      case 'place':
        if (link.id == null) return;
        try {
          final places = await widget.repository.getPlaces();
          CampusPlace? place;
          for (final p in places) {
            if (p.id == link.id) place = p;
          }
          if (place == null || !mounted) return;
          final selected = place;
          await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => PlaceDetailScreen(
              place: selected,
              repository: widget.repository,
              mapProvider: widget.mapProvider,
              analyticsTracker: widget.analyticsTracker,
            ),
          ));
        } catch (_) {}
        return;
      default:
        return;
    }
  }

  Future<void> _finishSignIn(String method, bool ok) async {
    if (!ok) {
      setState(() => error = AppLocale.of(context).t('login_failed'));
      widget.analyticsTracker.track('auth_failure', {'method': method});
      return;
    }
    final profile = await widget.repository.getMe();
    if (!mounted) return;
    widget.analyticsTracker.track('auth_success', {'method': method});
    final signedInEmail = widget.authProvider.currentEmail;
    if (signedInEmail != null && signedInEmail.isNotEmpty) {
      final enrolled = await AppSettingsStore.biometricEmail();
      if (enrolled != null &&
          enrolled.trim().toLowerCase() != signedInEmail.trim().toLowerCase()) {
        await AppSettingsStore.disableBiometric();
      }
    }
    UserRole resolvedRole = widget.authProvider is MockAuthProvider
        ? (widget.authProvider as MockAuthProvider).role
        : profile.parsedRole;
    try {
      final assignedRole = await widget.repository.roleFor(signedInEmail);
      if (assignedRole != null) resolvedRole = assignedRole;
    } catch (_) {
      // /me already carries the enforced role; a failed extra lookup must
      // not strand a valid session on the login screen.
    }
    if (!mounted) return;
    await AuditLogStore.logIfMock(widget.repository,
        actorName: profile.name,
        action: 'login',
        targetType: 'auth',
        targetLabel: method);
    setState(() {
      user = profile;
      signedIn = true;
      error = null;
      role = resolvedRole;
      hasStoredSession = true;
    });
    unawaited(_push.start());
    unawaited(_ensureLocationPermission());
    final pending = _pendingLink;
    _pendingLink = null;
    if (pending != null) {
      unawaited(_openDeepLink(pending));
    }
  }

  /// Requests location once at login. After first grant or clear denial
  /// acknowledgment we persist `location_permission_handled` so later
  /// app entries do not re-prompt (OS also won't re-ask deniedForever).
  Future<void> _ensureLocationPermission() async {
    const location = LocationService();
    try {
      if (await location.hasGranted()) {
        await location.markPermissionHandled();
        LocationService.notifyGranted();
        widget.analyticsTracker
            .track('location_permission', {'result': 'already_granted'});
        return;
      }

      if (await location.isPermissionHandled()) {
        widget.analyticsTracker
            .track('location_permission', {'result': 'handled_still_denied'});
        if (!await location.wasSoftBannerShown()) {
          await location.markSoftBannerShown();
          rootMessengerKey.currentState?.showSnackBar(const SnackBar(
            content: Text(
                'Yakınındaki yerler ve kampüs özellikleri için konum kapalı. Ayarlardan açabilirsin.'),
            duration: Duration(seconds: 4),
          ));
        }
        return;
      }

      if (!mounted) return;
      await location.markPermissionHandled();
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        widget.analyticsTracker
            .track('location_permission', {'result': 'service_disabled'});
        if (mounted) {
          await showDialog<void>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Konum gerekli'),
              content: const Text(
                  'Yakınındaki yerler, canlı harita, navigasyon ve check-in için cihaz konumunun açık olması gerekir.'),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Tamam'),
                ),
              ],
            ),
          );
        }
        // Remember this explanation even when device location is switched off.
        return;
      }

      if (mounted) {
        final proceed = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Konum izni'),
            content: const Text(
                'Yakınındaki yerler ve kampüs özellikleri konumuna ihtiyaç duyar. Devam ederek konum izni isteyeceğiz.'),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(ctx).pop(false),
                child: const Text('Şimdi değil'),
              ),
              TextButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: const Text('Devam'),
              ),
            ],
          ),
        );
        if (proceed != true || !mounted) return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      widget.analyticsTracker
          .track('location_permission', {'result': permission.name});

      if (permission == LocationPermission.whileInUse ||
          permission == LocationPermission.always) {
        await location.markPermissionHandled();
        LocationService.notifyGranted();
        return;
      }

      rootMessengerKey.currentState?.showSnackBar(const SnackBar(
          content: Text(
              'Konum izni verilmedi — yakınındaki yerler, canlı harita, navigasyon ve check-in doğrulaması kısıtlı çalışacak.'),
          duration: Duration(seconds: 5)));
      await location.markPermissionHandled();
    } catch (e) {
      widget.analyticsTracker.track(
          'location_permission', {'result': 'error', 'error': e.toString()});
    }
  }

  Future<void> _runSignIn(String method, Future<bool> Function() action) async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final ok = await action();
      await _finishSignIn(method, ok);
    } on ApiClientException catch (e) {
      if (!mounted) return;
      if (e.code == 'ACCOUNT_BANNED' || e.code == 'AUTH_REQUIRED') {
        unawaited(widget.authProvider.signOut());
      }
      if (e.code == 'ACCOUNT_BANNED') {
        unawaited(AppSettingsStore.disableBiometric());
      }
      setState(() => error = e.displayMessage);
      widget.analyticsTracker.track(
          'auth_failure', {'method': method, 'error': e.code ?? e.toString()});
    } catch (e) {
      if (!mounted) return;
      setState(() => error =
          isNetworkFailure(e) ? describeNetworkFailure(e) : e.toString());
      widget.analyticsTracker
          .track('auth_failure', {'method': method, 'error': e.toString()});
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> _signInWithCredentials(String identifier, String password) =>
      _runSignIn(
          'password',
          () =>
              widget.authProvider.signInWithCredentials(identifier, password));

  /// Only shown once a real Entra config has been saved from the Admin
  /// Panel's Site Settings tab (see main.dart) — `MockAuthProvider.signIn()`
  /// has no real OAuth flow to trigger, so this button would be misleading
  /// otherwise.
  bool get _entraActive =>
      widget.authProvider is EntraAuthProvider ||
      (widget.authProvider is RestAuthProvider &&
          (widget.authProvider as RestAuthProvider).microsoftSignInAvailable);

  Future<void> _signInWithMicrosoft() =>
      _runSignIn('microsoft', () => widget.authProvider.signIn());

  Future<void> _signInWithBiometrics() {
    final s = AppLocale.of(context);
    return _runSignIn('biometric', () async {
      final method = await AppSettingsStore.biometricMethod();
      final enrolledEmail = await AppSettingsStore.biometricEmail();
      final confirmed = await BiometricAuthProvider().unlockWithBiometrics(
        reason: method == BiometricMethod.face
            ? s.t('login_biometric_face')
            : s.t('login_biometric_finger'),
      );
      if (!confirmed) return false;
      final unlocked =
          await widget.authProvider.unlockWithBiometrics(email: enrolledEmail);
      return unlocked;
    });
  }

  void _openSettings() {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => SettingsScreen(
        authProvider: widget.authProvider,
        language: language,
        onLanguageChanged: widget.onLanguageChanged,
        apiBaseUrl: widget.config.apiBaseUrl,
      ),
    ));
  }

  void _logout() {
    widget.analyticsTracker.track('auth_logout', {});
    if (user != null) {
      unawaited(AuditLogStore.logIfMock(widget.repository,
          actorName: user!.name,
          action: 'logout',
          targetType: 'auth',
          targetLabel: ''));
    }
    // Revoking the session token server-side is what makes this a real
    // logout rather than the UI merely forgetting — in REST mode the token
    // is deleted from `personal_access_tokens` and stops working
    // immediately. Not awaited so the login screen appears at once; the
    // provider clears local state regardless of how the call goes.
    unawaited(_push.stopAndUnregister());
    unawaited(widget.authProvider.signOut());
    unawaited(AppSettingsStore.clearAccountLocalState());
    _resetSessionUi();
  }

  @override
  Widget build(BuildContext context) {
    if (bootstrapping) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      );
    }
    if (!signedIn) {
      return _wrapPersistenceBanner(_DemoLoginScreen(
        loading: loading,
        error: error,
        hasStoredSession: hasStoredSession,
        onSignInWithCredentials: _signInWithCredentials,
        onSignInWithBiometrics: _signInWithBiometrics,
        // Connection details are a build/deployment concern, never a task for
        // a student at sign-in. Settings contains only account preferences.
        onOpenSettings: _openSettings,
        showMicrosoftSignIn: _entraActive,
        onSignInWithMicrosoft: _signInWithMicrosoft,
      ));
    }
    if (user == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (widget.startInAdminMode) {
      if (!role.canOpenAdminPanel) {
        return _wrapPersistenceBanner(AccessDeniedScreen(
          title: 'Yönetim paneli',
          message: 'Bu hesap yönetim paneline erişemez.',
          onLogout: _logout,
        ));
      }
      return _wrapPersistenceBanner(AdminPanelScreen(
          repository: widget.repository,
          role: role,
          user: user!,
          onLogout: _logout));
    }

    if (widget.startInTrainerMode) {
      if (!role.canOpenTrainerPanel) {
        return _wrapPersistenceBanner(AccessDeniedScreen(
          title: 'Eğitmen paneli',
          message: 'Bu hesap eğitmen paneline erişemez.',
          onLogout: _logout,
        ));
      }
      return _wrapPersistenceBanner(TrainerPanelScreen(
          repository: widget.repository,
          user: user!,
          role: role,
          onLogout: _logout));
    }

    return _wrapPersistenceBanner(CampusShell(
      user: user!,
      repository: widget.repository,
      mapProvider: widget.mapProvider,
      analyticsTracker: widget.analyticsTracker,
      initialLanguage: language,
      role: role,
      onLogout: _logout,
      onLanguageChanged: widget.onLanguageChanged,
    ));
  }

  /// Mock mode keeps writes in-process only — refresh /admin in another
  /// tab looks "broken". Surface that honestly until REST is on.
  Widget _wrapPersistenceBanner(Widget child) {
    if (!widget.config.demoMode) return child;
    return Column(
      children: [
        Material(
          color: ArucadColors.yellow,
          child: SafeArea(
            bottom: false,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(
                children: [
                  const Icon(Icons.warning_amber_rounded,
                      color: ArucadColors.ink, size: 18),
                  const SizedBox(width: 8),
                  const Expanded(
                    child: Text(
                      'Mock mod: kayıtlar kalıcı değil. Admin’in görmesi için Laravel (port 4000) '
                      'açık olmalı; varsayılan artık REST — bu banner yalnızca USE_REST_API=false iken çıkar.',
                      style: TextStyle(color: ArucadColors.ink, fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        Expanded(child: child),
      ],
    );
  }
}

class _DemoLoginScreen extends StatefulWidget {
  final bool loading;
  final String? error;
  final bool hasStoredSession;
  final Future<void> Function(String identifier, String password)
      onSignInWithCredentials;
  final Future<void> Function() onSignInWithBiometrics;
  final VoidCallback? onOpenSettings;
  final bool showMicrosoftSignIn;
  final Future<void> Function() onSignInWithMicrosoft;

  const _DemoLoginScreen({
    required this.loading,
    this.error,
    required this.hasStoredSession,
    required this.onSignInWithCredentials,
    required this.onSignInWithBiometrics,
    required this.onOpenSettings,
    required this.showMicrosoftSignIn,
    required this.onSignInWithMicrosoft,
  });

  @override
  State<_DemoLoginScreen> createState() => _DemoLoginScreenState();
}

class _DemoLoginScreenState extends State<_DemoLoginScreen> {
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscure = true;
  bool _biometricAvailable = false;
  BiometricMethod? _biometricMethod;
  bool _rememberMe = false;

  @override
  void initState() {
    super.initState();
    _loadBiometricState();
    _loadRememberedIdentifier();
  }

  @override
  void didUpdateWidget(_DemoLoginScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.hasStoredSession != widget.hasStoredSession) {
      _loadBiometricState();
    }
  }

  Future<void> _loadBiometricState() async {
    final enabled = await AppSettingsStore.biometricEnabled();
    final method = await AppSettingsStore.biometricMethod();
    if (!mounted) return;
    setState(() {
      _biometricAvailable = enabled && widget.hasStoredSession;
      _biometricMethod = method;
    });
  }

  Future<void> _loadRememberedIdentifier() async {
    final remembered = await AppSettingsStore.rememberedIdentifier();
    if (!mounted || remembered == null) return;
    setState(() {
      _identifierController.text = remembered;
      _rememberMe = true;
    });
  }

  @override
  void dispose() {
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      backgroundColor: scheme.surface,
      body: SafeArea(
        child: Stack(children: [
          Align(
            alignment: Alignment.topCenter,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: SingleChildScrollView(
                padding:
                    const EdgeInsets.symmetric(horizontal: 28, vertical: 20),
                child: Column(
                  children: [
                    const SizedBox(height: 24),
                    Image.asset(
                      'assets/images/arucad_logo.png',
                      width: 190,
                      errorBuilder: (_, __, ___) => Image.asset(
                        'assets/images/ARUCAD_MAIN_LOGO.png',
                        width: 190,
                        errorBuilder: (_, __, ___) =>
                            const SizedBox(height: 72),
                      ),
                    ),
                    const SizedBox(height: 36),
                    if (widget.error != null) ...[
                      Text(widget.error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: ArucadColors.danger)),
                      const SizedBox(height: 16),
                    ],
                    TextField(
                      controller: _identifierController,
                      decoration: InputDecoration(
                        labelText: s.t('login_identifier'),
                        prefixIcon: const Icon(Icons.person_outline),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _passwordController,
                      obscureText: _obscure,
                      onSubmitted: (_) => _submitPassword(),
                      decoration: InputDecoration(
                        labelText: s.t('login_password'),
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          onPressed: () => setState(() => _obscure = !_obscure),
                          icon: Icon(_obscure
                              ? Icons.visibility_outlined
                              : Icons.visibility_off_outlined),
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Row(children: [
                      Checkbox(
                        value: _rememberMe,
                        onChanged: (v) =>
                            setState(() => _rememberMe = v ?? false),
                      ),
                      Text(s.t('login_remember')),
                    ]),
                    const SizedBox(height: 12),
                    SizedBox(
                      width: double.infinity,
                      height: 54,
                      child: FilledButton(
                        style: FilledButton.styleFrom(
                          backgroundColor: ArucadColors.primary,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(
                              borderRadius:
                                  BorderRadius.circular(ArucadRadius.compact)),
                        ),
                        onPressed: widget.loading ? null : _submitPassword,
                        child: Text(s.t('login_submit'),
                            style:
                                const TextStyle(fontWeight: FontWeight.w800)),
                      ),
                    ),
                    if (widget.showMicrosoftSignIn) ...[
                      const SizedBox(height: 12),
                      SizedBox(
                        width: double.infinity,
                        height: 54,
                        child: OutlinedButton.icon(
                          onPressed: widget.loading
                              ? null
                              : widget.onSignInWithMicrosoft,
                          icon: const Icon(Icons.window_outlined),
                          label: Text(s.t('login_microsoft'),
                              style:
                                  const TextStyle(fontWeight: FontWeight.w800)),
                        ),
                      ),
                    ],
                    if (_biometricAvailable) ...[
                      const SizedBox(height: 16),
                      GestureDetector(
                        onTap: widget.loading
                            ? null
                            : widget.onSignInWithBiometrics,
                        child: Container(
                          width: 54,
                          height: 54,
                          decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: scheme.surfaceContainerHighest),
                          child: Icon(
                              _biometricMethod == BiometricMethod.face
                                  ? Icons.face_outlined
                                  : Icons.fingerprint,
                              color: ArucadColors.primary,
                              size: 28),
                        ),
                      ),
                    ],
                    const SizedBox(height: 20),
                    if (widget.loading)
                      const Padding(
                        padding: EdgeInsets.only(top: 8),
                        child: CircularProgressIndicator(
                            color: ArucadColors.primary),
                      ),
                  ],
                ),
              ),
            ),
          ),
          if (widget.onOpenSettings != null)
            Positioned(
              top: 4,
              right: 4,
              child: IconButton(
                onPressed: widget.onOpenSettings,
                icon: Icon(Icons.settings_outlined, color: scheme.onSurface),
              ),
            ),
        ]),
      ),
    );
  }

  void _submitPassword() {
    AppSettingsStore.setRememberedIdentifier(
        _rememberMe ? _identifierController.text.trim() : null);
    widget.onSignInWithCredentials(
        _identifierController.text, _passwordController.text);
  }
}
