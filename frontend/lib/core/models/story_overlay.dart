import 'dart:math' as math;

/// A piece of text the author placed on top of a story.
///
/// Position and size are **normalised**: [x] and [y] are fractions of the
/// frame from its top-left, and [fontScale] is a fraction of the frame's
/// shorter side. Nothing here is in pixels, deliberately — a story is
/// composed on one phone and watched on dozens of different ones, and an
/// overlay placed 240 pixels down is in a different place on every one of
/// them. Placed at 0.42 of the way down, it is in the same place on all.
///
/// Everything is clamped on the way in and on the way out. Overlays are
/// written by one client and read by every other, including builds older
/// and newer than the one that wrote them, so a malformed value is an
/// expected input rather than an exceptional one. A story that will not
/// render is worse than one whose caption sits slightly off.
class StoryOverlay {
  const StoryOverlay({
    required this.text,
    this.x = 0.5,
    this.y = 0.5,
    this.fontScale = defaultFontScale,
    this.color = 0xFFFFFFFF,
    this.align = OverlayAlign.center,
    this.background,
  });

  final String text;

  /// Centre of the overlay, as a fraction of the frame.
  final double x;
  final double y;

  /// Font size as a fraction of the frame's shorter side.
  final double fontScale;

  /// ARGB, raw int rather than a Flutter Color so this file stays
  /// UI-framework-free like the rest of the models.
  final int color;

  final OverlayAlign align;

  /// Optional plate behind the text, for when the photo underneath is busy
  /// enough that white-on-anything stops being readable.
  final int? background;

  static const minFontScale = 0.03;
  static const maxFontScale = 0.20;
  static const defaultFontScale = 0.07;

  /// The most overlays one story may carry.
  ///
  /// Not a technical limit — a cap on how much work a malicious or broken
  /// client can make every viewer's device do.
  static const maxPerStory = 12;

  /// How much of each edge the story's own UI covers.
  ///
  /// The progress bar, author name and close button live along the top,
  /// and the reply box along the bottom. Text placed under those is not
  /// hidden so much as unreadable, so the editor keeps overlays inside
  /// this band and shows it as a guide.
  static const safeTop = 0.16;
  static const safeBottom = 0.14;
  static const safeSide = 0.06;

  StoryOverlay copyWith({
    String? text,
    double? x,
    double? y,
    double? fontScale,
    int? color,
    OverlayAlign? align,
    int? background,
    bool clearBackground = false,
  }) {
    return StoryOverlay(
      text: text ?? this.text,
      x: _fraction(x ?? this.x, 0.5),
      y: _fraction(y ?? this.y, 0.5),
      fontScale: _clamp(fontScale ?? this.fontScale, minFontScale, maxFontScale,
          defaultFontScale),
      color: color ?? this.color,
      align: align ?? this.align,
      background: clearBackground ? null : (background ?? this.background),
    );
  }

  /// Keeps an overlay clear of the story's own controls.
  StoryOverlay clampedToSafeArea() {
    return copyWith(
      x: x.clamp(safeSide, 1 - safeSide).toDouble(),
      y: y.clamp(safeTop, 1 - safeBottom).toDouble(),
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'text': text,
      'x': x,
      'y': y,
      'size': fontScale,
      'color': color,
      'align': align.name,
      if (background != null) 'bg': background,
    };
  }

  /// Reads one overlay back, tolerating anything.
  ///
  /// Returns null for something that cannot be an overlay — an entry that
  /// is not a map, or one with no text to show. A null is dropped from the
  /// list rather than rendered as an empty box.
  static StoryOverlay? fromMap(Object? raw) {
    if (raw is! Map) return null;

    final text = raw['text'];
    if (text is! String || text.trim().isEmpty) return null;

    return StoryOverlay(
      // Bounded so one overlay cannot be a whole novel that takes a
      // second to lay out on every frame.
      text: text.length > 500 ? text.substring(0, 500) : text,
      x: _fraction(raw['x'], 0.5),
      y: _fraction(raw['y'], 0.5),
      fontScale: _clamp(raw['size'], minFontScale, maxFontScale, defaultFontScale),
      color: raw['color'] is int ? raw['color'] as int : 0xFFFFFFFF,
      align: OverlayAlign.fromName(raw['align']),
      background: raw['bg'] is int ? raw['bg'] as int : null,
    );
  }

  /// Every overlay on a story, in the order they were placed.
  static List<StoryOverlay> listFromStyle(Map<String, dynamic>? style) {
    final raw = style?['overlays'];
    if (raw is! List) return const [];

    final out = <StoryOverlay>[];
    for (final entry in raw) {
      final overlay = fromMap(entry);
      if (overlay != null) out.add(overlay);
      if (out.length >= maxPerStory) break;
    }

    return out;
  }

  /// Merged into the story's style map rather than replacing it: the same
  /// map already carries framing and text styling.
  static Map<String, dynamic> listToStyle(
    Map<String, dynamic>? existing,
    List<StoryOverlay> overlays,
  ) {
    final style = <String, dynamic>{...?existing};

    if (overlays.isEmpty) {
      style.remove('overlays');

      return style;
    }

    style['overlays'] =
        overlays.take(maxPerStory).map((o) => o.toMap()).toList();

    return style;
  }

  /// A fraction of the frame, or [fallback] for anything unusable.
  ///
  /// `isFinite` is the point: NaN and infinity survive JSON decoding and
  /// pass every naive range check, because every comparison against NaN is
  /// false. One of them in a layout calculation takes out the whole frame.
  static double _fraction(Object? value, double fallback) =>
      _clamp(value, 0.0, 1.0, fallback);

  static double _clamp(Object? value, double min, double max, double fallback) {
    if (value is! num) return fallback;

    final n = value.toDouble();
    if (!n.isFinite) return fallback;

    return math.max(min, math.min(max, n));
  }
}

enum OverlayAlign {
  left,
  center,
  right;

  static OverlayAlign fromName(Object? value) {
    for (final align in OverlayAlign.values) {
      if (align.name == value) return align;
    }

    return OverlayAlign.center;
  }
}
