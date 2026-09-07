part of '../admin_panel_screen.dart';

// ------------------------------------------------------------- Site Settings

/// Public Entra client IDs and WordPress site URL, plus write-only secrets.
/// REST mode reads/writes through [CampusRepository] (`GET/POST
/// /admin/settings/site`). Mock mode still uses [SiteSettingsStore].
/// Filling Entra in mock mode still changes sign-in on next launch
/// (`main.dart`) — there is no fake "saved!" toast here.
class _SiteSettingsTab extends StatefulWidget {
  final CampusRepository repository;
  final String actorName;
  const _SiteSettingsTab({required this.repository, required this.actorName});
  @override
  State<_SiteSettingsTab> createState() => _SiteSettingsTabState();
}

class _SiteSettingsTabState extends State<_SiteSettingsTab> {
  final _tenantC = TextEditingController();
  final _clientC = TextEditingController();
  final _redirectC = TextEditingController();
  final _wpUrlC = TextEditingController();
  final _wpTokenC = TextEditingController();

  bool _loading = true;
  Object? _loadError;
  bool _savingEntra = false;
  bool _savingWp = false;
  bool _wpBusy = false;
  String? _wpResult;
  bool _wpError = false;
  bool _wpTokenConfigured = false;
  // Real, server-side-only setting (docs/EKSIKLER.md §26) — the backend
  // never echoes the raw key back, so this only ever reflects "is one
  // configured", never the value itself.
  bool _moderationConfigured = false;

  static const _defaultRedirect =
      'com.example.arucad_campus_prototype:/oauthredirect';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final settings = await widget.repository.getSiteSettings();
      final moderationConfigured =
          await widget.repository.getImageModerationConfigured();
      if (!mounted) return;
      setState(() {
        _tenantC.text = settings.entra.tenantId;
        _clientC.text = settings.entra.clientId;
        _redirectC.text = settings.entra.redirectUri.isEmpty
            ? _defaultRedirect
            : settings.entra.redirectUri;
        _wpUrlC.text = settings.wordpressSiteUrl;
        _wpTokenC.text = settings.wordpressApiToken;
        _wpTokenConfigured = settings.wordpressApiTokenConfigured;
        _moderationConfigured = moderationConfigured;
        _loadError = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loadError = e;
        _loading = false;
      });
    }
  }

  Future<void> _saveEntra() async {
    setState(() => _savingEntra = true);
    try {
      await widget.repository.updateSiteSettings(
        entra: EntraSiteConfig(
          tenantId: _tenantC.text.trim(),
          clientId: _clientC.text.trim(),
          redirectUri: _redirectC.text.trim(),
        ),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(AdminLocale.of(context).t('admin_entra_saved_toast'))));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    } finally {
      if (mounted) setState(() => _savingEntra = false);
    }
  }

  Future<void> _saveWordPress({bool showToast = true}) async {
    setState(() => _savingWp = true);
    final typedToken = _wpTokenC.text.trim();
    try {
      final updated = await widget.repository.updateSiteSettings(
        wordpressSiteUrl: _wpUrlC.text.trim(),
        wordpressApiToken: typedToken.isEmpty ? null : typedToken,
      );
      if (typedToken.isNotEmpty) _wpTokenC.clear();
      if (!mounted) return;
      setState(() => _wpTokenConfigured = updated.wordpressApiTokenConfigured);
      if (showToast) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(AdminLocale.of(context).t('admin_wp_saved_toast'))));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      rethrow;
    } finally {
      if (mounted) setState(() => _savingWp = false);
    }
  }

  Future<void> _clearWordPressToken() async {
    setState(() => _savingWp = true);
    try {
      final updated =
          await widget.repository.updateSiteSettings(wordpressApiToken: '');
      _wpTokenC.clear();
      if (!mounted) return;
      setState(() => _wpTokenConfigured = updated.wordpressApiTokenConfigured);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    } finally {
      if (mounted) setState(() => _savingWp = false);
    }
  }

  Future<void> _pull(
    Future<List<Map<String, dynamic>>> Function() call,
    String successNoun,
  ) async {
    setState(() {
      _wpBusy = true;
      _wpResult = null;
      _wpError = false;
    });
    try {
      await _saveWordPress(showToast: false);
      final data = await call();
      if (!mounted) return;
      final strings = AdminLocale.of(context);
      setState(() {
        _wpBusy = false;
        _wpResult =
            '${data.length} $successNoun ${strings.t('admin_wp_fetched_suffix')}';
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _wpBusy = false;
        _wpError = true;
        _wpResult =
            '${AdminLocale.of(context).t('admin_wp_connection_failed')}: $e';
      });
    }
  }

  @override
  void dispose() {
    _tenantC.dispose();
    _clientC.dispose();
    _redirectC.dispose();
    _wpUrlC.dispose();
    _wpTokenC.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_loadError != null) return _AdminLoadError(error: _loadError!);
    final strings = AdminLocale.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
      children: [
        Text(strings.t('admin_entra_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_entra_desc'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 14),
        TextField(
            controller: _tenantC,
            decoration:
                InputDecoration(labelText: strings.t('admin_entra_tenant_id'))),
        const SizedBox(height: 10),
        TextField(
            controller: _clientC,
            decoration:
                InputDecoration(labelText: strings.t('admin_entra_client_id'))),
        const SizedBox(height: 10),
        TextField(
            controller: _redirectC,
            decoration: InputDecoration(
                labelText: strings.t('admin_entra_redirect_uri'))),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_entra_redirect_note'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5),
        ),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: _savingEntra ? null : _saveEntra,
          child: Text(_savingEntra
              ? strings.t('admin_saving_ellipsis')
              : strings.t('admin_entra_save')),
        ),
        const SizedBox(height: 30),
        const Divider(),
        const SizedBox(height: 18),
        Text(strings.t('admin_wp_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        Text(
          strings.t('admin_wp_desc'),
          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 14),
        TextField(
            controller: _wpUrlC,
            decoration:
                InputDecoration(labelText: strings.t('admin_wp_site_url'))),
        const SizedBox(height: 10),
        TextField(
            controller: _wpTokenC,
            obscureText: true,
            decoration:
                InputDecoration(labelText: strings.t('admin_wp_api_token'))),
        const SizedBox(height: 10),
        Row(children: [
          Icon(
              _wpTokenConfigured
                  ? Icons.check_circle
                  : Icons.radio_button_unchecked,
              size: 16,
              color: _wpTokenConfigured
                  ? ArucadColors.success
                  : ArucadColors.muted),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
                _wpTokenConfigured
                    ? strings.t('admin_wp_token_configured')
                    : strings.t('admin_wp_token_not_configured'),
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w700)),
          ),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          FilledButton(
            onPressed: _savingWp ? null : () => _saveWordPress(),
            child: Text(_savingWp
                ? strings.t('admin_saving_ellipsis')
                : strings.t('admin_wp_save')),
          ),
          if (_wpTokenConfigured) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: _savingWp ? null : _clearWordPressToken,
              child: Text(strings.t('admin_wp_clear')),
            ),
          ],
        ]),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(
            child: OutlinedButton(
              onPressed: _wpBusy
                  ? null
                  : () => _pull(
                      () => WordPressDataSource.fetchForms(
                          siteUrl: _wpUrlC.text.trim(),
                          apiToken: _wpTokenC.text.trim()),
                      strings.t('admin_wp_form_noun')),
              child: Text(strings.t('admin_wp_fetch_forms')),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: OutlinedButton(
              onPressed: _wpBusy
                  ? null
                  : () => _pull(
                      () => WordPressDataSource.fetchEntries(
                          siteUrl: _wpUrlC.text.trim(),
                          apiToken: _wpTokenC.text.trim()),
                      strings.t('admin_wp_entry_noun')),
              child: Text(strings.t('admin_wp_fetch_entries')),
            ),
          ),
        ]),
        if (_wpBusy)
          const Padding(
              padding: EdgeInsets.only(top: 14),
              child: LinearProgressIndicator()),
        if (_wpResult != null)
          Padding(
            padding: const EdgeInsets.only(top: 14),
            child: Text(_wpResult!,
                style: TextStyle(
                    color:
                        _wpError ? ArucadColors.danger : ArucadColors.success)),
          ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _wpBusy
              ? null
              : () async {
                  setState(() {
                    _wpBusy = true;
                    _wpError = false;
                    _wpResult = null;
                  });
                  try {
                    await widget.repository.snapshotWordpressForms();
                    if (!mounted) return;
                    setState(() {
                      _wpBusy = false;
                      _wpResult =
                          'Form sürümü kaydedildi (hash değişmediyse unchanged).';
                    });
                  } catch (e) {
                    if (!mounted) return;
                    setState(() {
                      _wpBusy = false;
                      _wpError = true;
                      _wpResult = '$e';
                    });
                  }
                },
          icon: const Icon(Icons.history),
          label: const Text('WordPress form sürümü al'),
        ),
        const SizedBox(height: 30),
        const Divider(),
        const SizedBox(height: 18),
        Text(strings.t('admin_moderation_title'),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 6),
        const Text(
          'Görsel ve video yüklemeleri ARUCAD’ın yerel inceleme kuyruğuna alınır. '
          'Onaylanana kadar öğrenci akışında yayınlanmaz; haricî moderasyon API anahtarı kullanılmaz.',
          style: TextStyle(color: ArucadColors.muted, fontSize: 12.5),
        ),
        const SizedBox(height: 10),
        Row(children: [
          Icon(
              _moderationConfigured
                  ? Icons.check_circle
                  : Icons.radio_button_unchecked,
              size: 16,
              color: _moderationConfigured
                  ? ArucadColors.success
                  : ArucadColors.muted),
          const SizedBox(width: 6),
          Text(
              _moderationConfigured
                  ? strings.t('admin_moderation_configured')
                  : strings.t('admin_moderation_not_configured'),
              style:
                  const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        ]),
      ],
    );
  }
}
