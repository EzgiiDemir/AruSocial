import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'tour_360_web_view_stub.dart'
    if (dart.library.html) 'tour_360_web_view.dart' as web_tour;

/// Whether `webview_flutter` (a native platform WebView) can host ARUCAD
/// 360 as a top-level in-app document. Web never uses this — it goes
/// through [webTourProxyUrlFor] + an `<iframe>` instead (see below).
bool get supportsInApp360WebView {
  if (kIsWeb) return false;
  switch (defaultTargetPlatform) {
    case TargetPlatform.android:
    case TargetPlatform.iOS:
    case TargetPlatform.macOS:
      return true;
    default:
      return false;
  }
}

/// Real fix for "360 tours only open in an external tab on web": rewrites
/// the tour URL to go through our own backend's `/tour-proxy/{path}` (see
/// `TourProxyController`), which mirrors 360.arucad.edu.tr's own path
/// structure under our origin. That mirroring matters, not just the missing
/// X-Frame-Options: the tour's own relative script/image/XHR references
/// then resolve back to this same route tree instead of the real external
/// host, so every follow-up request stays same-origin — the first version
/// of this only proxied the one HTML document and pointed a `<base href>`
/// at the external host, which made every sub-resource a real cross-origin
/// request that 360.arucad.edu.tr's missing CORS headers then blocked.
/// Returns null when there's no bound backend to proxy through (Mock mode
/// has none, or the URL isn't actually on the one host this proxies) — an
/// honest "open externally" fallback applies then.
String? webTourProxyUrlFor(String url) {
  final apiBase = MediaUrl.apiBase;
  if (apiBase == null || apiBase.isEmpty) return null;
  final uri = Uri.tryParse(url);
  if (uri == null || uri.host != '360.arucad.edu.tr') return null;
  final path = uri.path.startsWith('/') ? uri.path.substring(1) : uri.path;
  final query = uri.hasQuery ? '?${uri.query}' : '';
  final fragment = uri.hasFragment ? '#${uri.fragment}' : '';
  return '$apiBase/tour-proxy/$path$query$fragment';
}

/// Full-screen, responsive in-app 360° tour (no external browser).
class Tour360Screen extends StatelessWidget {
  final String url;
  final String? title;
  final String? tourTarget;

  const Tour360Screen({
    super.key,
    required this.url,
    this.title,
    this.tourTarget,
  });

  @override
  Widget build(BuildContext context) {
    final resolved = composeTourUrl(url, tourTarget);
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        leading: const CampusBackButton(color: Colors.white),
        title: Text(
          title?.trim().isNotEmpty == true ? title! : '360° Tur',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
        ),
      ),
      body: SafeArea(
        top: false,
        child: Tour360View(url: resolved, expand: true),
      ),
    );
  }
}

/// Embeddable 360 preview / player — fills parent; use inside map panels,
/// place cards, or [Tour360Screen].
class Tour360View extends StatefulWidget {
  final String url;
  final bool expand;

  const Tour360View({super.key, required this.url, this.expand = false});

  @override
  State<Tour360View> createState() => _Tour360ViewState();
}

class _Tour360ViewState extends State<Tour360View> {
  WebViewController? _controller;
  String? _webIframeUrl;
  var _loading = true;
  var _failed = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _boot(widget.url);
  }

  @override
  void didUpdateWidget(covariant Tour360View oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.url != widget.url) {
      _boot(widget.url);
    }
  }

  void _boot(String url) {
    if (kIsWeb) {
      final proxied = webTourProxyUrlFor(url);
      if (proxied == null) {
        // No bound backend to proxy through (e.g. Mock mode) — an honest
        // external-tab fallback, same idea as any platform this can't embed
        // 360.arucad.edu.tr's framing on.
        setState(() {
          _webIframeUrl = null;
          _loading = false;
          _failed = true;
          _error = '360° turu yeni sekmede açıp gezebilirsin. Bu sayfaya dönerek devam edebilirsin.';
        });
        return;
      }
      setState(() {
        _webIframeUrl = proxied;
        _loading = false;
        _failed = false;
        _error = null;
      });
      return;
    }
    if (!supportsInApp360WebView) {
      setState(() {
        _controller = null;
        _loading = false;
        _failed = true;
        _error = '360° turu tarayıcıda açıp gezebilirsin.';
      });
      return;
    }
    setState(() {
      _loading = true;
      _failed = false;
      _error = null;
    });
    final controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.black)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) {
            if (mounted) setState(() => _loading = true);
          },
          onPageFinished: (_) {
            if (mounted) setState(() => _loading = false);
          },
          onWebResourceError: (err) {
            if (err.isForMainFrame == false) return;
            if (!mounted) return;
            setState(() {
              _loading = false;
              _failed = true;
              _error = err.description;
            });
          },
        ),
      )
      ..loadRequest(Uri.parse(url));
    setState(() => _controller = controller);
  }

  @override
  Widget build(BuildContext context) {
    final child = _buildBody();
    if (widget.expand) return SizedBox.expand(child: child);
    return child;
  }

  Widget _buildBody() {
    final webIframeUrl = _webIframeUrl;
    if (webIframeUrl != null) {
      return web_tour.buildTourIframe(webIframeUrl);
    }
    if (_failed || _controller == null) {
      return ColoredBox(
        color: Colors.black,
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.threed_rotation,
                    color: Colors.white70, size: 40),
                const SizedBox(height: 12),
                Text(
                  _error ?? '360° tur yüklenemedi.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white70, height: 1.4),
                ),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  icon: const Icon(Icons.open_in_new, color: Colors.white),
                  label: const Text('Turu tarayıcıda aç',
                      style: TextStyle(color: Colors.white)),
                  onPressed: () async {
                    try {
                      final opened = await launchUrl(Uri.parse(widget.url),
                          mode: LaunchMode.externalApplication,
                          webOnlyWindowName: '_blank');
                      if (!opened) throw StateError('Could not open tour');
                    } catch (_) {
                      if (!mounted) return;
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                          content: Text('Tur açılamadı. Lütfen tekrar deneyin.')));
                    }
                  },
                ),
                if (supportsInApp360WebView)
                  FilledButton(
                    onPressed: () => _boot(widget.url),
                    style: FilledButton.styleFrom(
                      backgroundColor: ArucadColors.primary,
                      foregroundColor: Colors.white,
                    ),
                    child: const Text('Tekrar dene'),
                  ),
              ],
            ),
          ),
        ),
      );
    }

    return Stack(
      fit: StackFit.expand,
      children: [
        WebViewWidget(
          controller: _controller!,
          gestureRecognizers: {
            Factory<OneSequenceGestureRecognizer>(
              () => EagerGestureRecognizer(),
            ),
          },
        ),
        if (_loading)
          const ColoredBox(
            color: Color(0x88000000),
            child: Center(
              child: CircularProgressIndicator(color: Colors.white),
            ),
          ),
      ],
    );
  }
}
