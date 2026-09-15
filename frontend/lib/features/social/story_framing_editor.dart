import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/features/social/framed_story_image.dart';

/// Lets the author decide how their photo sits in the story frame.
///
/// The frame is roughly 9:19.5 and almost no photo is that shape, so the
/// app used to make the decision for everyone: crop to fill, centred. On a
/// portrait photo that throws away most of the top and bottom and zooms
/// into the middle, which is why stories came out closer than the picture
/// they were made from.
///
/// Three controls, and no more:
///
///   * **Fill / Fit** — crop to the frame, or show the whole photo on a
///     background. This one control is the actual fix; the rest is
///     refinement.
///   * **Pinch and drag** — push the crop where the author wants it,
///     rather than accepting the centre.
///   * **Background** — only offered in Fit, because in Fill there is
///     nothing behind the photo to colour.
///
/// The preview is the same widget the viewer uses, so what is on screen
/// here is what gets published.
class StoryFramingEditor extends StatefulWidget {
  const StoryFramingEditor({
    super.key,
    required this.bytes,
    required this.framing,
    required this.onChanged,
  });

  final Uint8List bytes;
  final StoryFraming framing;
  final ValueChanged<StoryFraming> onChanged;

  @override
  State<StoryFramingEditor> createState() => _StoryFramingEditorState();
}

class _StoryFramingEditorState extends State<StoryFramingEditor> {
  /// Scale at the moment the current pinch started, so a gesture is
  /// relative to where the photo already was rather than jumping to 1.0.
  double _scaleAtGestureStart = 1.0;
  Offset _offsetAtGestureStart = Offset.zero;

  StoryFraming get _framing => widget.framing;

  void _apply(StoryFraming next) => widget.onChanged(next);

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: Center(
            child: AspectRatio(
              // The real story shape, so the preview is the crop rather
              // than an approximation of it.
              aspectRatio: 9 / 16,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: GestureDetector(
                  onScaleStart: (_) {
                    _scaleAtGestureStart = _framing.scale;
                    _offsetAtGestureStart =
                        Offset(_framing.offsetX, _framing.offsetY);
                  },
                  onScaleUpdate: (details) {
                    final scale = (_scaleAtGestureStart * details.scale)
                        .clamp(StoryFraming.minScale, StoryFraming.maxScale)
                        .toDouble();

                    // focalPointDelta is in pixels; offsets are fractions
                    // of the frame, so it is divided by the frame size.
                    final box = context.findRenderObject() as RenderBox?;
                    final size = box?.size ?? const Size(1, 1);

                    _apply(_framing.copyWith(
                      scale: scale,
                      offsetX: StoryFraming.clampOffset(
                        _offsetAtGestureStart.dx +
                            details.focalPointDelta.dx / size.width,
                        scale,
                      ),
                      offsetY: StoryFraming.clampOffset(
                        _offsetAtGestureStart.dy +
                            details.focalPointDelta.dy / size.height,
                        scale,
                      ),
                    ));
                    _offsetAtGestureStart =
                        Offset(_framing.offsetX, _framing.offsetY);
                  },
                  child: FramedStoryImage(
                    framing: _framing,
                    bytes: widget.bytes,
                  ),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        _FitToggle(
          fit: _framing.fit,
          onChanged: (fit) => _apply(_framing.copyWith(
            fit: fit,
            // Coming back to Fit resets the push: there is nothing to crop,
            // so a leftover offset would only shift the photo off-centre
            // against its background for no reason the author asked for.
            scale: fit == FrameFit.fit ? 1.0 : null,
            offsetX: fit == FrameFit.fit ? 0.0 : null,
            offsetY: fit == FrameFit.fit ? 0.0 : null,
          )),
        ),

        // Only in Fit. In Fill the photo covers the frame and a background
        // colour would be a control that visibly does nothing.
        if (_framing.fit == FrameFit.fit) ...[
          const SizedBox(height: 12),
          _BackgroundPicker(
            selected: _framing.backgroundColor,
            onPicked: (value) => _apply(_framing.copyWith(
              backgroundColor: value,
              clearBackground: value == null,
            )),
          ),
        ],

        if (_framing.fit == FrameFit.fill) ...[
          const SizedBox(height: 8),
          Text(
            strings.t('story_frame_hint'),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 12, color: Colors.white70),
          ),
        ],
      ],
    );
  }
}

class _FitToggle extends StatelessWidget {
  const _FitToggle({required this.fit, required this.onChanged});

  final FrameFit fit;
  final ValueChanged<FrameFit> onChanged;

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);

    return SegmentedButton<FrameFit>(
      segments: [
        ButtonSegment(
          value: FrameFit.fill,
          icon: const Icon(Icons.crop_free, size: 18),
          label: Text(strings.t('story_frame_fill')),
        ),
        ButtonSegment(
          value: FrameFit.fit,
          icon: const Icon(Icons.fit_screen_outlined, size: 18),
          label: Text(strings.t('story_frame_fit')),
        ),
      ],
      selected: {fit},
      showSelectedIcon: false,
      onSelectionChanged: (values) => onChanged(values.first),
    );
  }
}

class _BackgroundPicker extends StatelessWidget {
  const _BackgroundPicker({required this.selected, required this.onPicked});

  final int? selected;
  final ValueChanged<int?> onPicked;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 44,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: StoryFraming.backgrounds.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (context, i) {
          final value = StoryFraming.backgrounds[i];
          final isSelected = selected == value;

          return Semantics(
            selected: isSelected,
            button: true,
            child: GestureDetector(
              onTap: () => onPicked(value),
              child: Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: Color(value),
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: isSelected ? Colors.white : Colors.white24,
                    width: isSelected ? 3 : 1,
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
