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
    return Image.network(
      resolved,
      fit: fit,
      width: width ?? double.infinity,
      height: height,
      cacheWidth: cacheWidth,
      errorBuilder: (_, __, ___) =>
          fallback ??
          ColoredBox(
            color: ArucadColors.mist,
            child: const Center(
              child: Icon(Icons.broken_image_outlined, color: ArucadColors.muted),
            ),
          ),
    );
  }
}
