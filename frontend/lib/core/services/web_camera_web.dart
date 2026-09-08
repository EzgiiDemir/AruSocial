import 'dart:async';
import 'dart:convert';
import 'dart:js_interop';
import 'dart:typed_data';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Real webcam capture for the web build.
///
/// `image_picker` cannot do this. On the web it renders a file input with
/// the `capture` attribute, which a phone browser *may* interpret as "open
/// the camera app" and which a desktop browser ignores entirely — so on a
/// PC "Kameradan çek" only ever opened a file dialog. This talks to
/// `getUserMedia` instead, shows a live preview, and grabs a frame.
bool get webCameraAvailable => true;

/// Browsers only expose a camera in a secure context: HTTPS, or localhost.
/// Over plain http:// on a LAN address — which is exactly how a phone
/// reaches a laptop dev server — `mediaDevices` is not even defined, and
/// the failure is silent unless it is checked for.
bool get webContextIsSecure => web.window.isSecureContext;

int _viewSeq = 0;

Future<Uint8List?> captureFromWebCamera(BuildContext context) {
  return showDialog<Uint8List?>(
    context: context,
    barrierDismissible: false,
    builder: (_) => const _WebCameraDialog(),
  );
}

class _WebCameraDialog extends StatefulWidget {
  const _WebCameraDialog();

  @override
  State<_WebCameraDialog> createState() => _WebCameraDialogState();
}

class _WebCameraDialogState extends State<_WebCameraDialog> {
  late final String _viewType = 'aruverse-camera-${_viewSeq++}';
  web.MediaStream? _stream;
  web.HTMLVideoElement? _video;
  String? _error;
  bool _busy = true;

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    try {
      final video = web.HTMLVideoElement()
        ..autoplay = true
        ..muted = true
        ..setAttribute('playsinline', 'true')
        ..style.width = '100%'
        ..style.height = '100%'
        ..style.objectFit = 'cover';

      // A back camera is the right default for photographing a place; the
      // browser falls back to whatever exists when there is no rear camera,
      // which is the usual case on a laptop.
      final constraints = web.MediaStreamConstraints(
        video: {'facingMode': 'environment'}.jsify() ?? true.toJS,
        audio: false.toJS,
      );

      final stream = await web.window.navigator.mediaDevices
          .getUserMedia(constraints)
          .toDart;

      video.srcObject = stream;
      ui_web.platformViewRegistry
          .registerViewFactory(_viewType, (int _) => video);

      if (!mounted) {
        _stopTracks(stream);

        return;
      }
      setState(() {
        _stream = stream;
        _video = video;
        _busy = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = _explain(e);
      });
    }
  }

  /// Browser camera errors are opaque by name alone, and the fix differs
  /// completely between them — so each one says what to actually do.
  String _explain(Object e) {
    final text = e.toString();
    if (!web.window.isSecureContext) {
      return 'Tarayıcı, güvenli olmayan bir bağlantıda kameraya izin vermiyor. '
          'Kamerayı kullanmak için siteyi https:// üzerinden veya localhost '
          'adresinden aç. Şimdilik galeriden fotoğraf seçebilirsin.';
    }
    if (text.contains('NotAllowedError') || text.contains('Permission')) {
      return 'Kamera izni verilmedi. Tarayıcının adres çubuğundaki kamera '
          'simgesinden izin verip tekrar dene.';
    }
    if (text.contains('NotFoundError') || text.contains('DevicesNotFound')) {
      return 'Bu cihazda kamera bulunamadı.';
    }
    if (text.contains('NotReadableError')) {
      return 'Kamera başka bir uygulama tarafından kullanılıyor olabilir. '
          'Diğer uygulamaları kapatıp tekrar dene.';
    }

    return 'Kamera açılamadı: $text';
  }

  void _stopTracks(web.MediaStream stream) {
    final tracks = stream.getTracks().toDart;
    for (final track in tracks) {
      track.stop();
    }
  }

  @override
  void dispose() {
    // Without this the camera light stays on after the dialog closes.
    final stream = _stream;
    if (stream != null) _stopTracks(stream);
    _video?.srcObject = null;
    super.dispose();
  }

  Uint8List? _grabFrame() {
    final video = _video;
    if (video == null) return null;

    final w = video.videoWidth;
    final h = video.videoHeight;
    if (w == 0 || h == 0) return null;

    // Capped so a 4K webcam does not produce a file the upload limit
    // rejects — and moderation has less to chew through.
    const maxEdge = 1600;
    final scale = (w > h ? w : h) > maxEdge ? maxEdge / (w > h ? w : h) : 1.0;

    final canvas = web.HTMLCanvasElement()
      ..width = (w * scale).round()
      ..height = (h * scale).round();
    final ctx = canvas.getContext('2d') as web.CanvasRenderingContext2D;
    ctx.drawImage(video, 0, 0, canvas.width.toDouble(), canvas.height.toDouble());

    final dataUrl = canvas.toDataURL('image/jpeg', 0.85.toJS);
    final comma = dataUrl.indexOf(',');
    if (comma < 0) return null;

    return base64Decode(dataUrl.substring(comma + 1));
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      insetPadding: const EdgeInsets.all(16),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 8, 8),
            child: Row(children: [
              const Expanded(
                child: Text('Kameradan çek',
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              ),
              IconButton(
                onPressed: () => Navigator.pop(context, null),
                icon: const Icon(Icons.close),
                tooltip: 'Kapat',
              ),
            ]),
          ),
          if (_busy)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 48),
              child: CircularProgressIndicator(),
            )
          else if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
              child: Column(children: [
                const Icon(Icons.videocam_off_outlined,
                    size: 36, color: ArucadColors.muted),
                const SizedBox(height: 12),
                Text(_error!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 13, height: 1.45)),
              ]),
            )
          else ...[
            AspectRatio(
              aspectRatio: 3 / 4,
              child: HtmlElementView(viewType: _viewType),
            ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: () => Navigator.pop(context, _grabFrame()),
                  icon: const Icon(Icons.camera_alt_outlined),
                  label: const Text('Fotoğrafı çek'),
                ),
              ),
            ),
          ],
        ]),
      ),
    );
  }
}
