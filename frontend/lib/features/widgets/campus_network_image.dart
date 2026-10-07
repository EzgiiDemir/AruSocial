import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

class CampusNetworkImage extends StatelessWidget {
  final String url;
  final BoxFit fit;
  final int? cacheWidth;
  final String? cacheBust;
  final Widget? fallback;
  final double? width;
  final double? height;

  const CampusNetworkImage(
    this.url, {
    super.key,
    this.fit = BoxFit.cover,
    this.cacheWidth,
    this.cacheBust,
    this.fallback,
    this.width,
    this.height,
  });

  @override
  Widget build(BuildContext context) {
    final resolved = MediaUrl.resolve(url, cacheBust: cacheBust) ?? url;

    return LayoutBuilder(builder: (context, constraints) {
      return Image.network(
        resolved,
        fit: fit,
        width: width ?? double.infinity,
        height: height,
        // Decode at the size we actually draw, not the size that was
        // uploaded.
        //
        // `cacheWidth` was optional and six of the eight call sites omitted
        // it, so a 60x60 story thumbnail decoded the student's full-resolution
        // photo: a 2000x2000 upload becomes ~16 MB of ARGB in memory, for
        // 3,600 pixels of screen. A row of those is enough to cause visible
        // scroll jank and, on a modest phone, image-cache eviction thrash.
        //
        // Derived from the real layout rather than required from the caller,
        // because an optional performance parameter is one that gets
        // forgotten — as it demonstrably was.
        cacheWidth: cacheWidth ?? _decodeWidth(context, constraints),
        // Keeps the previous frame while a new URL loads instead of blinking
        // to the error/empty state, which matters on a feed that refreshes.
        gaplessPlayback: true,
        errorBuilder: (_, __, ___) =>
            fallback ??
            ColoredBox(
              color: ArucadColors.mist,
              child: const Center(
                child: Icon(Icons.broken_image_outlined, color: ArucadColors.muted),
              ),
            ),
      );
    });
  }

  /// The widest this image is actually painted, in device pixels.
  ///
  /// Null when nothing bounds it — an unconstrained width with no explicit
  /// `width` means we genuinely do not know, and guessing small there would
  /// show a blurry hero image. Capped so a very wide tablet layout cannot ask
  /// for a decode larger than the source.
  static int? _decodeWidth(BuildContext context, BoxConstraints constraints) {
    final logical = switch (constraints.maxWidth) {
      final w when w.isFinite && w > 0 => w,
      _ => null,
    };
    if (logical == null) {
      return null;
    }

    final ratio = MediaQuery.maybeDevicePixelRatioOf(context) ?? 1.0;

    return (logical * ratio).round().clamp(1, 2048);
  }
}
