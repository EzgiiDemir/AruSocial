import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/framed_story_image.dart';

/// Choosing how a post's photo is shown, without altering the photo.
///
/// Everything here writes to a [MediaFraming] and nothing touches the
/// bytes. That is the whole point: the author can come back and change
/// their mind, and what is stored is still the picture they took.
///
/// The preview is [FramedImage] — the same widget the feed uses — so the
/// frame shown here is the frame that gets published, rather than two
/// renderers that agree until one of them changes.
Future<MediaFraming?> editPostFraming(
  BuildContext context, {
  required Uint8List bytes,
  required MediaFraming framing,
  double? intrinsicAspect,
}) {
  return showModalBottomSheet<MediaFraming>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _PostFramingSheet(
      bytes: bytes,
      initial: framing,
      intrinsicAspect: intrinsicAspect,
    ),
  );
}

class _PostFramingSheet extends StatefulWidget {
  const _PostFramingSheet({
    required this.bytes,
    required this.initial,
    this.intrinsicAspect,
  });

  final Uint8List bytes;
  final MediaFraming initial;
  final double? intrinsicAspect;

  @override
  State<_PostFramingSheet> createState() => _PostFramingSheetState();
}

class _PostFramingSheetState extends State<_PostFramingSheet> {
  late MediaFraming _framing = widget.initial;

  /// Scale at the moment the current pinch began, so a gesture scales from
  /// where the photo already was rather than jumping back to 1.
  double _scaleAtGestureStart = 1;
  Offset _offsetAtGestureStart = Offset.zero;

  PostAspect get _aspect => _framing.aspect ?? PostAspect.portrait;

  double get _ratio => _aspect.ratio(widget.intrinsicAspect);

  void _onScaleStart(ScaleStartDetails details) {
    _scaleAtGestureStart = _framing.scale;
    _offsetAtGestureStart = Offset(_framing.offsetX, _framing.offsetY);
  }

  void _onScaleUpdate(ScaleUpdateDetails details, Size frame) {
    final scale = (_scaleAtGestureStart * details.scale)
        .clamp(MediaFraming.minScale, MediaFraming.maxScale)
        .toDouble();

    // focalPointDelta is in pixels; offsets are fractions of the frame, so
    // that a post framed on a tablet lands the same way on a phone.
    final dx = _offsetAtGestureStart.dx +
        (frame.width == 0 ? 0 : details.focalPointDelta.dx / frame.width);
    final dy = _offsetAtGestureStart.dy +
        (frame.height == 0 ? 0 : details.focalPointDelta.dy / frame.height);

    setState(() {
      _framing = _framing.copyWith(
        scale: scale,
        offsetX: MediaFraming.clampOffset(dx, scale),
        offsetY: MediaFraming.clampOffset(dy, scale),
      );
      _offsetAtGestureStart = Offset(_framing.offsetX, _framing.offsetY);
    });
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final strings = AppLocale.of(context);

    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: media.viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              strings.t('frame_title'),
              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18),
            ),
            const SizedBox(height: 6),
            Text(
              strings.t('frame_explain'),
              style: const TextStyle(color: ArucadColors.muted, fontSize: 13),
            ),
            const SizedBox(height: 14),
            Center(
              child: ConstrainedBox(
                constraints: BoxConstraints(maxHeight: media.size.height * 0.45),
                child: AspectRatio(
                  aspectRatio: _ratio,
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final frame =
                          Size(constraints.maxWidth, constraints.maxHeight);

                      return GestureDetector(
                        onScaleStart: _onScaleStart,
                        onScaleUpdate: (d) => _onScaleUpdate(d, frame),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(14),
                          child: FramedImage(
                            framing: _framing,
                            bytes: widget.bytes,
                            fallbackBackground: Colors.black,
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),
            ),
            const SizedBox(height: 14),
            _label(strings.t('frame_ratio')),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              children: [
                for (final aspect in PostAspect.values)
                  ChoiceChip(
                    label: Text(_aspectLabel(strings, aspect)),
                    selected: _aspect == aspect,
                    onSelected: (_) =>
                        setState(() => _framing = _framing.copyWith(aspect: aspect)),
                  ),
              ],
            ),
            const SizedBox(height: 14),
            _label(strings.t('frame_fit_mode')),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              children: [
                ChoiceChip(
                  label: Text(strings.t('frame_fit_whole')),
                  selected: _framing.fit == FrameFit.fit,
                  onSelected: (_) => setState(
                      () => _framing = _framing.copyWith(fit: FrameFit.fit)),
                ),
                ChoiceChip(
                  label: Text(strings.t('frame_fit_fill')),
                  selected: _framing.fit == FrameFit.fill,
                  onSelected: (_) => setState(
                      () => _framing = _framing.copyWith(fit: FrameFit.fill)),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _label(strings.t('frame_background')),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final color in MediaFraming.backgrounds)
                  _Swatch(
                    color: Color(color),
                    selected: _framing.backgroundColor == color,
                    onTap: () => setState(() =>
                        _framing = _framing.copyWith(backgroundColor: color)),
                  ),
              ],
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    // Always available, never destructive: it puts the
                    // framing back, and the photo was never changed.
                    onPressed: () => setState(() => _framing = const MediaFraming(
                          fit: FrameFit.fit,
                          aspect: PostAspect.portrait,
                        )),
                    child: Text(strings.t('frame_reset')),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    onPressed: () => Navigator.pop(context, _framing),
                    child: Text(strings.t('frame_apply')),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  static Widget _label(String text) => Text(
        text,
        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12),
      );

  static String _aspectLabel(AppStrings strings, PostAspect aspect) {
    switch (aspect) {
      case PostAspect.original:
        return strings.t('frame_ratio_original');
      case PostAspect.square:
        return strings.t('frame_ratio_square');
      case PostAspect.portrait:
        return strings.t('frame_ratio_portrait');
    }
  }
}

class _Swatch extends StatelessWidget {
  const _Swatch({
    required this.color,
    required this.selected,
    required this.onTap,
  });

  final Color color;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: AppLocale.of(context).t('frame_background_color'),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(18),
        child: Container(
          // 44 rather than the 28 a swatch visually needs: this is the
          // minimum target anyone can reliably hit.
          width: 44,
          height: 44,
          alignment: Alignment.center,
          child: Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(
              color: color,
              shape: BoxShape.circle,
              border: Border.all(
                color: selected ? ArucadColors.primary : Colors.black26,
                width: selected ? 3 : 1,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
