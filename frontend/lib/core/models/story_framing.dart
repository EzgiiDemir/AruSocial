import 'dart:math' as math;

/// How a photo sits inside a story frame.
///
/// A phone story is roughly 9:19.5. Almost no photo is that shape, so
/// something has to give, and the app was giving the same answer every
/// time: `BoxFit.cover`, which fills the frame by cropping whatever does
/// not fit. On a 3:4 portrait that removes most of the top and bottom and
/// zooms hard into the middle — which is why a posted story came out
/// looking far closer than the photo it was made from.
///
/// This is the author's answer instead. [FrameFit.fill] is the old
/// behaviour, kept because it is right for a photo already shaped like a
/// story; [FrameFit.fit] shows the whole photo on a background of their
/// choosing. [scale] and [offset] let them push the crop where they want
/// rather than accepting the centre.
///
/// Stored in the story's existing `style_json`, so none of this needed a
/// schema change — and a story written by an older build simply has no
/// framing and falls back to [FrameFit.fill], which is what it was
/// published as.
enum FrameFit {
  /// Fill the frame, cropping the overflow. The old, only behaviour.
  fill,

  /// Show the whole photo, letterboxed onto [StoryFraming.backgroundColor].
  fit,
}

class StoryFraming {
  const StoryFraming({
    this.fit = FrameFit.fill,
    this.scale = 1.0,
    this.offsetX = 0.0,
    this.offsetY = 0.0,
    this.backgroundColor,
  });

  final FrameFit fit;

  /// 1.0 is "as [fit] decides". Above that the photo is pushed in closer.
  final double scale;

  /// Where the photo sits, as a fraction of the frame: -1 is a full frame
  /// left/up, +1 a full frame right/down. Fractions rather than pixels
  /// because the frame is a different size on every phone, and a story
  /// composed on one must look the same on another.
  final double offsetX;
  final double offsetY;

  /// ARGB, and null means "pick one from the photo". Raw int rather than a
  /// Flutter Color so this file stays UI-framework-free, like the rest of
  /// the models.
  final int? backgroundColor;

  static const minScale = 1.0;
  static const maxScale = 4.0;

  bool get isDefault =>
      fit == FrameFit.fill &&
      scale == 1.0 &&
      offsetX == 0.0 &&
      offsetY == 0.0 &&
      backgroundColor == null;

  StoryFraming copyWith({
    FrameFit? fit,
    double? scale,
    double? offsetX,
    double? offsetY,
    int? backgroundColor,
    bool clearBackground = false,
  }) {
    return StoryFraming(
      fit: fit ?? this.fit,
      scale: (scale ?? this.scale).clamp(minScale, maxScale).toDouble(),
      offsetX: (offsetX ?? this.offsetX).clamp(-1.0, 1.0).toDouble(),
      offsetY: (offsetY ?? this.offsetY).clamp(-1.0, 1.0).toDouble(),
      backgroundColor:
          clearBackground ? null : (backgroundColor ?? this.backgroundColor),
    );
  }

  /// Merged into the story's `style` map rather than replacing it: the same
  /// map already carries the text styling for text-only stories, and a
  /// story can have both.
  Map<String, dynamic> toStyle(Map<String, dynamic>? existing) {
    final style = <String, dynamic>{...?existing};

    if (isDefault) {
      style.remove('framing');

      return style;
    }

    style['framing'] = {
      'fit': fit.name,
      'scale': scale,
      'offsetX': offsetX,
      'offsetY': offsetY,
      if (backgroundColor != null) 'bg': backgroundColor,
    };

    return style;
  }

  /// Reads framing back out, tolerating anything.
  ///
  /// A story is published once and read for 24 hours by clients that may be
  /// older or newer than the one that wrote it. A malformed or absent
  /// `framing` gives the default rather than throwing — a story that will
  /// not render is worse than one framed the old way.
  static StoryFraming fromStyle(Map<String, dynamic>? style) {
    final raw = style?['framing'];
    if (raw is! Map) return const StoryFraming();

    double number(Object? value, double fallback) {
      if (value is num) {
        final result = value.toDouble();

        return result.isFinite ? result : fallback;
      }

      return fallback;
    }

    final fitName = raw['fit'];

    return StoryFraming(
      fit: fitName == FrameFit.fit.name ? FrameFit.fit : FrameFit.fill,
      scale: number(raw['scale'], 1.0).clamp(minScale, maxScale).toDouble(),
      offsetX: number(raw['offsetX'], 0.0).clamp(-1.0, 1.0).toDouble(),
      offsetY: number(raw['offsetY'], 0.0).clamp(-1.0, 1.0).toDouble(),
      backgroundColor: raw['bg'] is int ? raw['bg'] as int : null,
    );
  }

  /// The background palette offered in the editor.
  ///
  /// Deliberately short. A colour wheel invites people to spend time on a
  /// decision that does not matter much; these are the ones that actually
  /// look right behind a photo — true black and white for a clean border,
  /// then muted tones that do not fight the picture.
  static const backgrounds = <int>[
    0xFF000000,
    0xFFFFFFFF,
    0xFF14181F,
    0xFFF2EFE9,
    0xFF1B4A9C,
    0xFF7B2D26,
    0xFF2E5B45,
    0xFFE8B04B,
  ];

  /// Clamps an offset so the photo cannot be dragged off the frame.
  ///
  /// Without this, pushing a barely-zoomed photo far enough leaves a band
  /// of background where the picture should be — which looks like a bug
  /// rather than a choice.
  static double clampOffset(double value, double scale) {
    // At scale 1 in fill mode there is nothing spare to move; the further
    // in it is pushed, the more slack there is.
    final slack = math.max(0.0, (scale - 1.0) / scale);

    return value.clamp(-slack, slack).toDouble();
  }
}
