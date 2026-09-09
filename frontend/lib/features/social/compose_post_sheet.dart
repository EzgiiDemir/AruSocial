import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/upload_rules.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/image_moderation_service.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/moderation_notice.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/media_frame.dart';

/// Shared "new post" composer — the Social feed's FAB and Profile's "my
/// posts" both create the exact same kind of post, so they share this one
/// sheet instead of maintaining two copies. Returns true if a post was
/// actually created.
Future<bool> showComposePostSheet(
    BuildContext context, CampusRepository repository) async {
  final strings = AppLocale.of(context);
  final textController = TextEditingController();
  final locationController = TextEditingController();
  PickedPostMedia? picked;
  PostVisibility visibility = PostVisibility.everyone;
  PostCategory postType = PostCategory.normal;
  bool showLocationField = false;
  final posted = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setSheetState) => Padding(
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
              if (picked != null)
                FramedMediaPreview(
                  aspectRatio: MediaFrame.post,
                  maxHeight: 176,
                  onClear: () => setSheetState(() => picked = null),
                  onAdjust: picked!.isVideo
                      ? null
                      : () async {
                          final next = await adjustMediaFrame(
                            ctx,
                            picked!.bytes,
                            aspectRatio: MediaFrame.post,
                          );
                          if (next != null) {
                            setSheetState(() => picked = PickedPostMedia(
                                  bytes: next,
                                  fileName: 'photo.png',
                                  isVideo: false,
                                ));
                          }
                        },
                  child: picked!.isVideo
                      ? ColoredBox(
                          color: ArucadColors.mist,
                          child: Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.videocam_outlined, size: 36),
                                const SizedBox(height: 6),
                                Text(strings.t('compose_video_attached'),
                                    style: const TextStyle(
                                        color: ArucadColors.muted,
                                        fontSize: 12)),
                              ],
                            ),
                          ),
                        )
                      : Image.memory(picked!.bytes, fit: BoxFit.cover),
                )
              else
                OutlinedButton.icon(
                  onPressed: () async {
                    final media = await PhotoPickerService.pickPostMedia(ctx);
                    if (media == null) return;

                    // Same client-side pre-check the avatar paths use, so a
                    // file the server will refuse fails here instead of
                    // after a slow upload. The server still decides.
                    final reason =
                        UploadRules.rejectionReason(media.bytes, media.fileName);
                    if (reason != null) {
                      if (!ctx.mounted) return;
                      await showModerationNotice(ctx, message: reason);

                      return;
                    }

                    if (media.isVideo) {
                      setSheetState(() => picked = media);
                      return;
                    }
                    final framed =
                        await cropBytesToAspect(media.bytes, MediaFrame.post);
                    setSheetState(() => picked = PickedPostMedia(
                          bytes: framed,
                          fileName: 'photo.png',
                          isVideo: false,
                        ));
                  },
                  icon: const Icon(Icons.add_photo_alternate_outlined),
                  label: Text(strings.t('compose_add_media')),
                ),
              const SizedBox(height: 10),
              ActionChip(
                avatar: const Icon(Icons.place_outlined, size: 16),
                label: Text(
                    showLocationField
                        ? strings.t('compose_location_added')
                        : strings.t('compose_add_location'),
                    style: const TextStyle(fontSize: 12)),
                onPressed: () async {
                  final position =
                      await const LocationService().getCurrentPosition();
                  if (position == null) {
                    if (!ctx.mounted) return;
                    ScaffoldMessenger.of(ctx).showSnackBar(
                      const SnackBar(
                          content: Text('Konum paylaşımı için izin gerekli.')),
                    );
                    return;
                  }
                  String label =
                      '${position.latitude.toStringAsFixed(5)},${position.longitude.toStringAsFixed(5)}';
                  try {
                    final places = await repository.getPlaces();
                    CampusPlace? nearest;
                    double? bestMeters;
                    for (final place in places) {
                      final meters = Geolocator.distanceBetween(
                        position.latitude,
                        position.longitude,
                        place.lat,
                        place.lng,
                      );
                      if (bestMeters == null || meters < bestMeters) {
                        bestMeters = meters;
                        nearest = place;
                      }
                    }
                    if (nearest != null) label = nearest.name;
                  } catch (_) {}
                  if (!ctx.mounted) return;
                  setSheetState(() {
                    showLocationField = true;
                    locationController.text = label;
                  });
                },
              ),
              if (showLocationField) ...[
                const SizedBox(height: 8),
                TextField(
                  controller: locationController,
                  decoration: InputDecoration(
                    hintText: strings.t('compose_location_hint'),
                    prefixIcon: const Icon(Icons.place_outlined, size: 18),
                    isDense: true,
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.close, size: 16),
                      onPressed: () => setSheetState(() {
                        showLocationField = false;
                        locationController.clear();
                      }),
                    ),
                  ),
                ),
              ],
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
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: Text(strings.t('social_share')),
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
  if (posted != true) return false;
  final text = textController.text.trim();
  if (text.isEmpty && picked == null) return false;
  final media = picked;
  try {
    if (media != null && !media.isVideo) {
      await ImageModerationService.assertImageAllowed(media.bytes, repository);
    }
    await repository.createPost(
        text.isEmpty ? strings.t('compose_default_caption') : text,
        imageBytes: media?.bytes,
        mediaFileName: media?.fileName,
        visibility: visibility,
        postType: postType,
        courseTag: null,
        locationTag: locationController.text.trim().isEmpty
            ? null
            : locationController.text.trim());
  } on ContentModerationException catch (e) {
    if (!context.mounted) return false;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(e.reason)));
    return false;
  } catch (_) {
    if (!context.mounted) return false;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Gönderi paylaşılamadı.')),
    );
    return false;
  }
  return true;
}
