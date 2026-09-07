import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Instagram-like display frames. Posts sit in a portrait 4:5 box; stories
/// in 9:16. Images are always `BoxFit.cover` inside so mixed camera sizes
/// cannot blow out the feed.
class MediaFrame {
  static const post = 4 / 5;
  static const story = 9 / 16;
}

class FramedMedia extends StatelessWidget {
  final double aspectRatio;
  final Widget child;
  final double borderRadius;

  const FramedMedia({
    super.key,
    required this.aspectRatio,
    required this.child,
    this.borderRadius = 14,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: AspectRatio(
        aspectRatio: aspectRatio,
        child: SizedBox.expand(child: child),
      ),
    );
  }
}

/// Feed / detail photos sit in a left-aligned 4:5 tile. Sized to read as a
/// photo inside the card without stretching to full card width/height.
class CompactFeedImage extends StatelessWidget {
  final Widget child;
  final double aspectRatio;
  final double maxWidth;
  final double borderRadius;

  const CompactFeedImage({
    super.key,
    required this.child,
    this.aspectRatio = MediaFrame.post,
    this.maxWidth = 236,
    this.borderRadius = 12,
  });

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final available = constraints.maxWidth.isFinite
            ? constraints.maxWidth
            : maxWidth;
        final width = maxWidth.clamp(0.0, available);
        final height = width / aspectRatio;
        return Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(
            width: width,
            height: height,
            child: FramedMedia(
              aspectRatio: aspectRatio,
              borderRadius: borderRadius,
              child: child,
            ),
          ),
        );
      },
    );
  }
}

/// Compact compose preview so a 9:16 / 4:5 frame cannot blow out a sheet.
class FramedMediaPreview extends StatelessWidget {
  final double aspectRatio;
  final Widget child;
  final VoidCallback? onClear;
  final VoidCallback? onAdjust;
  final double maxHeight;

  const FramedMediaPreview({
    super.key,
    required this.aspectRatio,
    required this.child,
    this.onClear,
    this.onAdjust,
    this.maxHeight = 176,
  });

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: SizedBox(
        height: maxHeight,
        width: maxHeight * aspectRatio,
        child: Stack(
          fit: StackFit.expand,
          children: [
            FramedMedia(aspectRatio: aspectRatio, child: child),
            if (onAdjust != null)
              Positioned(
                left: 6,
                bottom: 6,
                child: IconButton.filled(
                  tooltip: 'Kırp / konumlandır',
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.black54,
                    minimumSize: const Size(36, 36),
                  ),
                  onPressed: onAdjust,
                  icon: const Icon(Icons.crop, size: 16, color: Colors.white),
                ),
              ),
            if (onClear != null)
              Positioned(
                right: 6,
                top: 6,
                child: IconButton.filled(
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.black54,
                    minimumSize: const Size(32, 32),
                  ),
                  onPressed: onClear,
                  icon: const Icon(Icons.close, size: 16, color: Colors.white),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Center-crops [bytes] to [aspectRatio] so the stored file already matches
/// the on-screen frame.
Future<Uint8List> cropBytesToAspect(Uint8List bytes, double aspectRatio) async {
  final codec = await ui.instantiateImageCodec(bytes);
  final frame = await codec.getNextFrame();
  final src = frame.image;
  final sw = src.width.toDouble();
  final sh = src.height.toDouble();
  var cropW = sw;
  var cropH = sw / aspectRatio;
  if (cropH > sh) {
    cropH = sh;
    cropW = sh * aspectRatio;
  }
  final left = ((sw - cropW) / 2).round();
  final top = ((sh - cropH) / 2).round();
  final outW = cropW.round().clamp(1, src.width);
  final outH = cropH.round().clamp(1, src.height);

  final recorder = ui.PictureRecorder();
  final canvas = Canvas(recorder);
  canvas.drawImageRect(
    src,
    Rect.fromLTWH(left.toDouble(), top.toDouble(), outW.toDouble(), outH.toDouble()),
    Rect.fromLTWH(0, 0, outW.toDouble(), outH.toDouble()),
    Paint()..filterQuality = FilterQuality.medium,
  );
  final picture = recorder.endRecording();
  final out = await picture.toImage(outW, outH);
  final data = await out.toByteData(format: ui.ImageByteFormat.png);
  src.dispose();
  out.dispose();
  picture.dispose();
  if (data == null) return bytes;
  return data.buffer.asUint8List();
}

Future<Uint8List?> adjustMediaFrame(
  BuildContext context,
  Uint8List bytes, {
  required double aspectRatio,
}) {
  return showModalBottomSheet<Uint8List>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _FrameAdjustSheet(bytes: bytes, aspectRatio: aspectRatio),
  );
}

class _FrameAdjustSheet extends StatefulWidget {
  final Uint8List bytes;
  final double aspectRatio;
  const _FrameAdjustSheet({required this.bytes, required this.aspectRatio});

  @override
  State<_FrameAdjustSheet> createState() => _FrameAdjustSheetState();
}

class _FrameAdjustSheetState extends State<_FrameAdjustSheet> {
  final _boundaryKey = GlobalKey();
  final _transform = TransformationController();

  @override
  void dispose() {
    _transform.dispose();
    super.dispose();
  }

  Future<void> _confirm() async {
    final boundary = _boundaryKey.currentContext?.findRenderObject()
        as RenderRepaintBoundary?;
    if (boundary == null) {
      Navigator.pop(context, widget.bytes);
      return;
    }
    try {
      final image = await boundary.toImage(pixelRatio: 2);
      final data = await image.toByteData(format: ui.ImageByteFormat.png);
      image.dispose();
      if (!mounted) return;
      Navigator.pop(context, data?.buffer.asUint8List() ?? widget.bytes);
    } catch (_) {
      if (mounted) Navigator.pop(context, widget.bytes);
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final maxH = size.height * 0.5;
    var width = size.width - 40;
    var height = width / widget.aspectRatio;
    if (height > maxH) {
      height = maxH;
      width = height * widget.aspectRatio;
    }
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Kırp / konumlandır',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 8),
            const Text(
              'Pinch ile yakınlaştır, sürükleyerek kadrajı ayarla.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 13),
            ),
            const SizedBox(height: 14),
            Center(
              child: SizedBox(
                width: width,
                height: height,
                child: RepaintBoundary(
                  key: _boundaryKey,
                  child: ClipRect(
                    child: InteractiveViewer(
                      transformationController: _transform,
                      minScale: 1,
                      maxScale: 4,
                      child: SizedBox.expand(
                        child: Image.memory(widget.bytes, fit: BoxFit.cover),
                      ),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Row(children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () => Navigator.pop(context, widget.bytes),
                  child: const Text('Bu kadraj'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton(
                  onPressed: _confirm,
                  child: const Text('Uygula'),
                ),
              ),
            ]),
          ],
        ),
      ),
    );
  }
}
