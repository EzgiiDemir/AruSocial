import 'dart:convert';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_network_image.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart'
    show categoryAccent;

/// Shared circular identity mark — real photo via [CampusNetworkImage] /
/// [MediaUrl], otherwise a deterministic letter accent (or school icon for
/// official campus accounts).
class CampusAvatar extends StatelessWidget {
  final String name;
  final String? avatarUrl;
  final double radius;
  final bool official;

  const CampusAvatar({
    super.key,
    required this.name,
    this.avatarUrl,
    this.radius = 18,
    this.official = false,
  });

  @override
  Widget build(BuildContext context) {
    final size = radius * 2;
    final letterFallback = _letterFallback();
    final officialFallback = CircleAvatar(
      radius: radius,
      backgroundColor: ArucadColors.primary,
      child: Icon(Icons.school_outlined, size: radius, color: Colors.white),
    );

    final url = avatarUrl?.trim();
    if (url == null || url.isEmpty) {
      return official ? officialFallback : letterFallback;
    }

    if (url.startsWith('data:')) {
      try {
        final bytes = base64Decode(url.split(',').last);
        return ClipOval(
          child: Image.memory(
            bytes,
            width: size,
            height: size,
            fit: BoxFit.cover,
            errorBuilder: (_, __, ___) =>
                official ? officialFallback : letterFallback,
          ),
        );
      } catch (_) {
        return official ? officialFallback : letterFallback;
      }
    }

    // Resolve once so callers can pass relative `/storage/...` paths.
    final resolved = MediaUrl.resolve(url) ?? url;
    return ClipOval(
      child: CampusNetworkImage(
        resolved,
        width: size,
        height: size,
        cacheWidth: (size * 2).round().clamp(48, 256),
        cacheBust: resolved.hashCode.toString(),
        fallback: official ? officialFallback : letterFallback,
      ),
    );
  }

  Widget _letterFallback() {
    final accent = categoryAccent(name);
    final initial = name.trim().isEmpty ? '?' : name.trim().substring(0, 1).toUpperCase();
    return CircleAvatar(
      radius: radius,
      backgroundColor: accent.withValues(alpha: .18),
      child: Text(
        initial,
        style: TextStyle(
          color: accent,
          fontWeight: FontWeight.w900,
          fontSize: radius * .8,
        ),
      ),
    );
  }
}
