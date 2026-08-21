import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:geolocator/geolocator.dart';

import 'config/app_config.dart';
import '../core/auth/app_settings_store.dart';
import '../core/auth/biometric_auth_provider.dart';
import '../core/auth/entra_auth_provider.dart';
import '../core/auth/session_store.dart';
import '../core/models/campus_models.dart';
import '../core/network/api_client.dart';
import '../core/services/audit_log_store.dart';
import '../core/services/contracts.dart';
import '../core/services/mock_auth_provider.dart';
import '../core/theme/arucad_theme.dart';
import '../features/admin/admin_panel_screen.dart';
import '../features/auth/settings_screen.dart';
import '../features/campus_shell.dart';

/// Lets code below the login screen show feedback (e.g. "location denied")
/// without needing a Scaffold ancestor at the exact point it's called from.
final GlobalKey<ScaffoldMessengerState> rootMessengerKey =
    GlobalKey<ScaffoldMessengerState>();

class ArucadCampusApp extends StatelessWidget {
  final AppConfig config;
  final CampusRepository repository;
  final AuthProvider authProvider;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  /// True only when this session started at the web `/admin` URL — a real
  /// separate entry point (see `main.dart`), not just a hidden button.
  final bool startInAdminMode;

  const ArucadCampusApp({
    super.key,
    required this.config,
    required this.repository,
    required this.authProvider,
    required this.mapProvider,
    required this.analyticsTracker,
    this.startInAdminMode = false,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: config.appName,
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
      scaffoldMessengerKey: rootMessengerKey,
      home: _DemoSession(
        repository: repository,
        authProvider: authProvider,
        mapProvider: mapProvider,
        analyticsTracker: analyticsTracker,
        startInAdminMode: startInAdminMode,
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
          repository: repository,
          authProvider: authProvider,
          mapProvider: mapProvider,
          analyticsTracker: analyticsTracker,
          startInAdminMode: startInAdminMode,
        ),
      ),
    );
  }
}

class _DemoSession extends StatefulWidget {
  final CampusRepository repository;
  final AuthProvider authProvider;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final bool startInAdminMode;

  const _DemoSession({
    required this.repository,
    required this.authProvider,
    required this.mapProvider,
    required this.analyticsTracker,
    this.startInAdminMode = false,
  });

  @override
  State<_DemoSession> createState() => _DemoSessionState();
}

class _DemoSessionState extends State<_DemoSession> {
  CampusUser? user;
  bool signedIn = false;
  bool loading = false;
  // Real session persistence (docs/EKSIKLER.md "Gerçek JWT/session
  // authentication" — "sayfa yenilendiğinde kullanıcı tekrar login
  // ekranına düşmemelidir"): true only while checking for a real stored
  // session on startup, so the login screen doesn't flash before a valid
  // session has a chance to restore.
  bool restoringSession = true;
  String? error;
  String language = 'TR';
  UserRole role = UserRole.student;

  @override
  void initState() {
    super.initState();
    AppSettingsStore.language().then((lang) {
      if (mounted) setState(() => language = lang);
    });
    _restoreSession();
  }

  Future<void> _restoreSession() async {
    final stored = await SessionStore.restore();
    if (!mounted) return;
    if (stored == null) {
      setState(() => restoringSession = false);
      return;
    }
    try {
      final profile = await widget.repository.getMe();
      if (!mounted) return;
      setState(() {
        user = profile;
        signedIn = true;
        role = _roleFromName(stored.role);
        restoringSession = false;
      });
    } catch (_) {
      // The stored token is no longer valid (revoked, expired, or the
      // backend restarted with a fresh dev database) — fall back to a
      // real sign-in instead of getting stuck on a broken session.
      await SessionStore.clear();
      if (!mounted) return;
      setState(() => restoringSession = false);
    }
  }

  UserRole _roleFromName(String name) =>
      UserRole.values.firstWhere((r) => r.name == name, orElse: () => UserRole.student);

  Future<void> _finishSignIn(String method, bool ok) async {
    if (!ok) {
      setState(() => error = 'Giriş yapılamadı. Bilgilerini kontrol et.');
      widget.analyticsTracker.track('auth_failure', {'method': method});
      return;
    }
    final baseRole = widget.authProvider is MockAuthProvider
        ? (widget.authProvider as MockAuthProvider).role
        : UserRole.student;
    final signedInEmail = widget.authProvider is MockAuthProvider
        ? (widget.authProvider as MockAuthProvider).currentEmail
        : (widget.authProvider is EntraAuthProvider
            ? (widget.authProvider as EntraAuthProvider).currentEmail
            : null);
    final signedInName = widget.authProvider is EntraAuthProvider
        ? (widget.authProvider as EntraAuthProvider).currentName
        : null;

    // Real per-user backend session (docs/EKSIKLER.md "Gerçek JWT/session
    // authentication") — must happen before getMe() in Rest mode, since
    // every request (including /me itself) now requires the real bearer
    // token this returns.
    final session = await widget.repository.startSession(
      email: signedInEmail ?? 'demo@arucad.edu.tr',
      name: signedInName ?? (signedInEmail?.split('@').first ?? 'Öğrenci'),
    );

    final profile = await widget.repository.getMe();
    if (!mounted) return;
    widget.analyticsTracker.track('auth_success', {'method': method});
    // A real, admin-editable email→role table overrides the base role when
    // set — this is what makes role assignment genuinely manageable from
    // Kullanıcılar & Roller instead of only ever being the one hardcoded
    // seed admin account. In Rest mode this is a real, shared backend
    // lookup; in Mock mode it's still per-device (see RoleAssignmentStore).
    final assignedRole = await widget.repository.roleFor(signedInEmail);
    final resolvedRole = assignedRole ?? baseRole;
    if (!mounted) return;
    // Persisted with the *resolved* role (not the raw backend value), so a
    // restored session on next launch reflects the same implicit
    // baseRole fallback a fresh sign-in gets — otherwise the seeded demo
    // admin account would look like a plain student after a restart.
    await SessionStore.save(
      token: session.token,
      email: session.email,
      name: session.name,
      role: resolvedRole.name,
    );
    await AuditLogStore.log(
        actorName: profile.name, action: 'login', targetType: 'auth', targetLabel: method);
    setState(() {
      user = profile;
      signedIn = true;
      error = null;
      role = resolvedRole;
    });
    unawaited(_ensureLocationPermission());
  }

  /// Requests the device's real location permission right at login, so the
  /// live map, in-app navigation and check-in verification all have a real
  /// position to work with by the time the student reaches them, instead of
  /// each screen prompting separately later.
  Future<void> _ensureLocationPermission() async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        widget.analyticsTracker.track('location_permission', {'result': 'service_disabled'});
        return;
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      widget.analyticsTracker
          .track('location_permission', {'result': permission.name});
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        rootMessengerKey.currentState?.showSnackBar(const SnackBar(
            content: Text(
                'Konum izni verilmedi — canlı harita, navigasyon ve check-in doğrulaması kısıtlı çalışacak.')));
      }
    } catch (e) {
      widget.analyticsTracker
          .track('location_permission', {'result': 'error', 'error': e.toString()});
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
      // A real, server-enforced consequence of the moderation strike
      // system (ModerationService/EnsureNotBanned on the backend) —
      // shown here instead of the raw "ApiClientException: HTTP 403…"
      // string, since sign-in is the one place every banned account is
      // guaranteed to pass through.
      setState(() => error = e.code == 'ACCOUNT_BANNED'
          ? e.message
          : e.toString());
      widget.analyticsTracker
          .track('auth_failure', {'method': method, 'error': e.code ?? e.toString()});
    } catch (e) {
      if (!mounted) return;
      setState(() => error = e.toString());
      widget.analyticsTracker
          .track('auth_failure', {'method': method, 'error': e.toString()});
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> _signInWithCredentials(String identifier, String password) =>
      _runSignIn('password',
          () => widget.authProvider.signInWithCredentials(identifier, password));

  /// Only shown once a real Entra config has been saved from the Admin
  /// Panel's Site Settings tab (see main.dart) — `MockAuthProvider.signIn()`
  /// has no real OAuth flow to trigger, so this button would be misleading
  /// otherwise.
  bool get _entraActive => widget.authProvider is EntraAuthProvider;

  Future<void> _signInWithMicrosoft() =>
      _runSignIn('microsoft', () => widget.authProvider.signIn());

  Future<void> _signInWithBiometrics() => _runSignIn('biometric', () async {
        final method = await AppSettingsStore.biometricMethod();
        final enrolledEmail = await AppSettingsStore.biometricEmail();
        final confirmed = await BiometricAuthProvider().unlockWithBiometrics(
          reason: method == BiometricMethod.face
              ? 'Face ID ile giriş yap'
              : 'Parmak izinle giriş yap',
        );
        if (!confirmed) return false;
        return widget.authProvider.unlockWithBiometrics(email: enrolledEmail);
      });

  void _openSettings() {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => SettingsScreen(
        authProvider: widget.authProvider,
        language: language,
        onLanguageChanged: (lang) => setState(() => language = lang),
      ),
    ));
  }

  void _logout() {
    widget.analyticsTracker.track('auth_logout', {});
    if (user != null) {
      unawaited(AuditLogStore.log(
          actorName: user!.name, action: 'logout', targetType: 'auth', targetLabel: ''));
    }
    unawaited(widget.repository.endSession());
    unawaited(SessionStore.clear());
    setState(() {
      signedIn = false;
      user = null;
      error = null;
      role = UserRole.student;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (restoringSession) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (!signedIn) {
      return _DemoLoginScreen(
        loading: loading,
        error: error,
        onSignInWithCredentials: _signInWithCredentials,
        onSignInWithBiometrics: _signInWithBiometrics,
        onOpenSettings: _openSettings,
        showMicrosoftSignIn: _entraActive,
        onSignInWithMicrosoft: _signInWithMicrosoft,
      );
    }
    if (user == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (widget.startInAdminMode) {
      if (!(role.canManageContent || role.canModerate)) {
        return _AdminAccessDeniedScreen(role: role);
      }
      return AdminPanelScreen(
          repository: widget.repository, role: role, user: user!, onLogout: _logout);
    }

    return CampusShell(
      user: user!,
      repository: widget.repository,
      mapProvider: widget.mapProvider,
      analyticsTracker: widget.analyticsTracker,
      initialLanguage: language,
      role: role,
      onLogout: _logout,
    );
  }
}

/// What a non-admin account sees at the web `/admin` URL — a real denial,
/// not a silent redirect back into the student app, so it's clear the
/// restriction is deliberate.
class _AdminAccessDeniedScreen extends StatelessWidget {
  final UserRole role;
  const _AdminAccessDeniedScreen({required this.role});

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.lock_outline, size: 48, color: ArucadColors.muted),
              const SizedBox(height: 16),
              const Text('Erişim reddedildi',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
              const SizedBox(height: 8),
              Text(
                  'Bu hesap (${role.label}) yönetim paneline erişemiyor. '
                  'Normal uygulamaya gitmek için ana adresi aç.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: ArucadColors.muted)),
            ]),
          ),
        ),
      );
}

class _DemoLoginScreen extends StatefulWidget {
  final bool loading;
  final String? error;
  final Future<void> Function(String identifier, String password)
      onSignInWithCredentials;
  final Future<void> Function() onSignInWithBiometrics;
  final VoidCallback onOpenSettings;
  final bool showMicrosoftSignIn;
  final Future<void> Function() onSignInWithMicrosoft;

  const _DemoLoginScreen({
    required this.loading,
    this.error,
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

  Future<void> _loadBiometricState() async {
    final enabled = await AppSettingsStore.biometricEnabled();
    final method = await AppSettingsStore.biometricMethod();
    if (!mounted) return;
    setState(() {
      _biometricAvailable = enabled;
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
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Stack(children: [
          SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 20),
            child: Column(
              children: [
                const SizedBox(height: 24),
                Image.asset('assets/images/arucad_logo.png', width: 190),
                const SizedBox(height: 36),
                if (widget.error != null) ...[
                  Text(widget.error!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: ArucadColors.danger)),
                  const SizedBox(height: 16),
                ],
                TextField(
                  controller: _identifierController,
                  decoration: const InputDecoration(
                    labelText: 'E-posta veya Öğrenci No',
                    prefixIcon: Icon(Icons.person_outline),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _passwordController,
                  obscureText: _obscure,
                  onSubmitted: (_) => _submitPassword(),
                  decoration: InputDecoration(
                    labelText: 'Şifre',
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
                    onChanged: (v) => setState(() => _rememberMe = v ?? false),
                  ),
                  const Text('Beni Hatırla'),
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
                          borderRadius: BorderRadius.circular(14)),
                    ),
                    onPressed: widget.loading ? null : _submitPassword,
                    child: const Text('Giriş Yap',
                        style: TextStyle(fontWeight: FontWeight.w800)),
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
                      label: const Text('Microsoft ile Giriş Yap',
                          style: TextStyle(fontWeight: FontWeight.w800)),
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
                      decoration: const BoxDecoration(
                          shape: BoxShape.circle, color: ArucadColors.mist),
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
                    child: CircularProgressIndicator(color: ArucadColors.primary),
                  ),
              ],
            ),
          ),
          Positioned(
            top: 4,
            right: 4,
            child: IconButton(
              onPressed: widget.onOpenSettings,
              icon: const Icon(Icons.settings_outlined, color: ArucadColors.ink),
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
