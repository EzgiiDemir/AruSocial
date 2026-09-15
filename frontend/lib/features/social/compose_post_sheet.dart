import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/core/services/upload_rules.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/image_moderation_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/social/framed_story_image.dart';
import 'package:arucad_campus_prototype/features/social/post_framing_editor.dart';
import 'package:arucad_campus_prototype/features/widgets/moderation_notice.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Shared "new post" composer — the Social feed's FAB and Profile's "my
/// posts" both create the exact same kind of post, so they share this one
/// sheet instead of maintaining two copies. Returns true if a post was
/// actually created.
Future<bool> showComposePostSheet(
    BuildContext context, CampusRepository repository) async {
  final strings = AppLocale.of(context);
  final textController = TextEditingController();
  final picked = <PickedPostMedia>[];
  PostVisibility visibility = PostVisibility.everyone;
  PostCategory postType = PostCategory.normal;

  // Guards the Publish button against a second press while the first is
  // still in flight. Without it, an impatient double-tap on a slow
  // connection publishes the post twice.
  bool publishing = false;

  final posted = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setSheetState) {
        /// The carousel's shape is set by its first picture, so the feed
        /// can draw every card at one height instead of jumping as each
        /// image loads.
        double carouselRatio() {
          if (picked.isEmpty) return 4 / 5;
          final first = picked.first;

          return (first.framing.aspect ?? PostAspect.portrait)
              .ratio(first.aspectRatio);
        }

        Future<void> addPhoto() async {
          if (picked.length >= kMaxPostImages) return;
          final media = await PhotoPickerService.pickPostMedia(ctx);
          if (media == null) return;

          // Same client-side pre-check the avatar paths use, so a file the
          // server will refuse fails here instead of after a slow upload.
          // The server still decides.
          final reason = UploadRules.rejectionReason(media.bytes, media.fileName);
          if (reason != null) {
            if (!ctx.mounted) return;
            await showModerationNotice(ctx, message: reason);

            return;
          }

          // Decoded here rather than trusted from anywhere: the feed needs
          // the real shape, and a decode that fails means a file that is
          // not an image whatever its name claims.
          ui.Image? decoded;
          try {
            final codec = await ui.instantiateImageCodec(media.bytes);
            decoded = (await codec.getNextFrame()).image;
          } catch (_) {
            if (!ctx.mounted) return;
            await showModerationNotice(ctx,
                message: strings.t('compose_not_an_image'));

            return;
          }

          final width = decoded.width;
          final height = decoded.height;
          decoded.dispose();

          if (!ctx.mounted) return;

          // A warning, not a refusal — it is the author's photo and their
          // call, but they should know before it is public rather than
          // after.
          if (width < 600 || height < 600) {
            await showModerationNotice(ctx,
                message: '${strings.t('compose_low_resolution')} '
                    '(${width}x$height)');
            if (!ctx.mounted) return;
          }

          setSheetState(() => picked.add(media.copyWith(
                width: width,
                height: height,
              )));
        }

        /// Publishes, and keeps the sheet open if it does not work.
        ///
        /// The draft and every picked photo stay exactly where they are on
        /// a failure. Losing a caption and ten chosen photos because a
        /// connection dropped is the worst thing this screen can do, and
        /// it is precisely what popping the sheet before uploading did.
        Future<void> publish(StateSetter setSheetState) async {
          final text = textController.text.trim();
          if (text.isEmpty && picked.isEmpty) return;

          setSheetState(() => publishing = true);

          try {
            for (final media in picked) {
              await ImageModerationService.assertImageAllowed(
                  media.bytes, repository);
            }

            await repository.createPost(
              text.isEmpty ? strings.t('compose_default_caption') : text,
              mediaItems: picked,
              // Each success is recorded on the item, so a retry after a
              // failure part-way through re-uploads only what is left.
              onItemUploaded: (index, url) {
                if (index >= 0 && index < picked.length) {
                  picked[index] = picked[index].copyWith(uploadedUrl: url);
                }
              },
              visibility: visibility,
              postType: postType,
              courseTag: null,
              locationTag: null,
            );
          } on ContentModerationException catch (e) {
            if (!ctx.mounted) return;
            setSheetState(() => publishing = false);
            ScaffoldMessenger.of(ctx)
                .showSnackBar(SnackBar(content: Text(e.reason)));

            return;
          } catch (_) {
            if (!ctx.mounted) return;
            setSheetState(() => publishing = false);
            ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
              content: Text(strings.t('post_share_failed')),
            ));

            return;
          }

          if (ctx.mounted) Navigator.of(ctx).pop(true);
        }

        return Padding(
          padding: EdgeInsets.only(
              left: ArucadSpacing.md,
              right: ArucadSpacing.md,
              top: ArucadSpacing.md,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + ArucadSpacing.md),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(strings.t('social_new_post'),
                    style: Theme.of(ctx).textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w900,
                        )),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final type in PostCategory.values)
                      SelectableChip(
                        label: type.label(strings),
                        selected: postType == type,
                        selectedColor: ArucadColors.blue,
                        onSelected: (_) => setSheetState(() => postType = type),
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: textController,
                  maxLines: 4,
                  autofocus: true,
                  decoration:
                      InputDecoration(hintText: strings.t('compose_hint')),
                ),
                const SizedBox(height: 10),
                if (picked.isNotEmpty)
                  _CarouselEditor(
                    items: picked,
                    ratio: carouselRatio(),
                    onChanged: () => setSheetState(() {}),
                  ),
                if (picked.isNotEmpty) const SizedBox(height: 8),
                if (picked.length < kMaxPostImages)
                  OutlinedButton.icon(
                    onPressed: addPhoto,
                    icon: const Icon(Icons.add_photo_alternate_outlined),
                    label: Text(picked.isEmpty
                        ? strings.t('compose_add_media')
                        : '${strings.t('compose_add_more_photos')} '
                            '(${picked.length}/$kMaxPostImages)'),
                  ),
                const SizedBox(height: 6),
                Text(strings.t('compose_hashtag_tip'),
                    style: const TextStyle(
                        color: ArucadColors.muted, fontSize: 11.5)),
                const SizedBox(height: 14),
                Text(strings.t('social_visibility'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w700, fontSize: 12)),
                const SizedBox(height: 6),
                AudienceChips(
                  value: visibility,
                  onChanged: (v) => setSheetState(() => visibility = v),
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                        backgroundColor: ArucadColors.primary,
                        foregroundColor: Colors.white),
                    onPressed: publishing ? null : () => publish(setSheetState),
                    child: publishing
                        ? const SizedBox(
                            height: 18,
                            width: 18,
                            child: CircularProgressIndicator(
                                strokeWidth: 2, color: Colors.white),
                          )
                        : Text(strings.t('social_share')),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    ),
  );
  // Publishing happened inside the sheet, so that a failure could keep
  // the draft. Reaching here with true means it succeeded.
  return posted == true;
}

/// The product limit, mirrored by `FeedPostMedia::MAX_ITEMS` on the server.
const kMaxPostImages = 10;

/// The picked photos, in the order they will be published.
///
/// Every item is drawn at the carousel's ratio — the first picture's — so
/// what the author sees here is the height the feed will use, and adding a
/// landscape photo after a portrait one cannot silently change the shape
/// of the whole post.
class _CarouselEditor extends StatelessWidget {
  const _CarouselEditor({
    required this.items,
    required this.ratio,
    required this.onChanged,
  });

  final List<PickedPostMedia> items;
  final double ratio;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < items.length; i++) ...[
          if (i > 0) const SizedBox(height: 10),
          _Item(
            media: items[i],
            index: i,
            total: items.length,
            ratio: ratio,
            onRemove: () {
              items.removeAt(i);
              onChanged();
            },
            onMove: (delta) {
              final target = i + delta;
              if (target < 0 || target >= items.length) return;
              final moved = items.removeAt(i);
              items.insert(target, moved);
              onChanged();
            },
            onFramed: (framing) {
              items[i] = items[i].copyWith(framing: framing);
              onChanged();
            },
            onAlt: (alt) {
              items[i] = items[i].copyWith(altText: alt);
              onChanged();
            },
          ),
        ],
      ],
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({
    required this.media,
    required this.index,
    required this.total,
    required this.ratio,
    required this.onRemove,
    required this.onMove,
    required this.onFramed,
    required this.onAlt,
  });

  final PickedPostMedia media;
  final int index;
  final int total;
  final double ratio;
  final VoidCallback onRemove;
  final void Function(int delta) onMove;
  final void Function(MediaFraming) onFramed;
  final void Function(String?) onAlt;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(14),
          child: AspectRatio(
            aspectRatio: ratio,
            child: Stack(
              fit: StackFit.expand,
              children: [
                FramedImage(
                  framing: media.framing,
                  bytes: media.bytes,
                  altText: media.altText,
                  fallbackBackground: Colors.black,
                ),
                if (total > 1)
                  Positioned(
                    left: 8,
                    top: 8,
                    child: _Pill(label: '${index + 1}/$total'),
                  ),
                // Says which pictures are already safely uploaded, so a
                // retry after a failure is visibly cheaper than starting
                // again.
                if (media.uploadedUrl != null)
                  const Positioned(
                    left: 8,
                    bottom: 8,
                    child: _Pill(label: '✓'),
                  ),
                Positioned(
                  right: 6,
                  top: 6,
                  child: _RoundButton(
                    icon: Icons.close,
                    tooltip: AppLocale.of(context).t('compose_remove_photo'),
                    onPressed: onRemove,
                  ),
                ),
                Positioned(
                  left: 6,
                  bottom: 6,
                  child: _RoundButton(
                    icon: Icons.crop_rotate,
                    tooltip: AppLocale.of(context).t('frame_title'),
                    onPressed: () async {
                      final next = await editPostFraming(
                        context,
                        bytes: media.bytes,
                        framing: media.framing,
                        intrinsicAspect: media.aspectRatio,
                      );
                      if (next != null) onFramed(next);
                    },
                  ),
                ),
                if (total > 1)
                  Positioned(
                    right: 6,
                    bottom: 6,
                    child: Row(children: [
                      _RoundButton(
                        icon: Icons.arrow_upward,
                        tooltip: AppLocale.of(context).t('compose_move_up'),
                        onPressed: index == 0 ? null : () => onMove(-1),
                      ),
                      const SizedBox(width: 6),
                      _RoundButton(
                        icon: Icons.arrow_downward,
                        tooltip: AppLocale.of(context).t('compose_move_down'),
                        onPressed: index == total - 1 ? null : () => onMove(1),
                      ),
                    ]),
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 6),
        TextFormField(
          initialValue: media.altText ?? '',
          maxLength: 1000,
          style: const TextStyle(fontSize: 12.5),
          decoration: InputDecoration(
            isDense: true,
            counterText: '',
            prefixIcon: const Icon(Icons.text_fields, size: 18),
            hintText: AppLocale.of(context).t('compose_alt_hint'),
          ),
          onChanged: (value) => onAlt(value.trim().isEmpty ? null : value.trim()),
        ),
      ],
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: Colors.black54,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(label,
          style: const TextStyle(
              color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
    );
  }
}

class _RoundButton extends StatelessWidget {
  const _RoundButton({
    required this.icon,
    required this.tooltip,
    required this.onPressed,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton.filled(
      tooltip: tooltip,
      style: IconButton.styleFrom(
        backgroundColor: Colors.black54,
        disabledBackgroundColor: Colors.black26,
        // 44 is the smallest target most people can hit reliably; the icon
        // inside stays small.
        minimumSize: const Size(44, 44),
      ),
      onPressed: onPressed,
      icon: Icon(icon, size: 18, color: Colors.white),
    );
  }
}
