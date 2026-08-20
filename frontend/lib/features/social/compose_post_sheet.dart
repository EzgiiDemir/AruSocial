import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/image_moderation_service.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Shared "new post" composer — the Social feed's FAB and Profile's "my
/// posts" both create the exact same kind of post, so they share this one
/// sheet instead of maintaining two copies. Returns true if a post was
/// actually created.
Future<bool> showComposePostSheet(BuildContext context, CampusRepository repository) async {
  final strings = AppLocale.of(context);
  final textController = TextEditingController();
  final courseController = TextEditingController();
  final locationController = TextEditingController();
  Uint8List? pickedBytes;
  PostVisibility visibility = PostVisibility.everyone;
  PostCategory postType = PostCategory.normal;
  bool showCourseField = false;
  bool showLocationField = false;
  final posted = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setSheetState) => Padding(
        padding: EdgeInsets.only(
            left: 20, right: 20, top: 20, bottom: MediaQuery.of(ctx).viewInsets.bottom + 20),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(strings.t('social_new_post'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: 12),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final type in PostCategory.values)
                    SelectableChip(
                      label: '${type.emoji} ${type.label(strings)}',
                      selected: postType == type,
                      onSelected: (_) => setSheetState(() => postType = type),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              TextField(
                controller: textController,
                maxLines: 4,
                autofocus: true,
                decoration: InputDecoration(hintText: strings.t('compose_hint')),
              ),
              const SizedBox(height: 10),
              if (pickedBytes != null)
                Stack(children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: AspectRatio(
                      aspectRatio: 16 / 10,
                      child: Image.memory(pickedBytes!, fit: BoxFit.cover),
                    ),
                  ),
                  Positioned(
                    right: 6,
                    top: 6,
                    child: IconButton.filled(
                      style: IconButton.styleFrom(
                          backgroundColor: Colors.black54, minimumSize: const Size(32, 32)),
                      onPressed: () => setSheetState(() => pickedBytes = null),
                      icon: const Icon(Icons.close, size: 16, color: Colors.white),
                    ),
                  ),
                ])
              else
                OutlinedButton.icon(
                  onPressed: () async {
                    final bytes = await PhotoPickerService.pick(ctx);
                    if (bytes != null) setSheetState(() => pickedBytes = bytes);
                  },
                  icon: const Icon(Icons.add_a_photo_outlined),
                  label: Text(strings.t('compose_add_photo')),
                ),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [
                ActionChip(
                  avatar: const Icon(Icons.place_outlined, size: 16),
                  label: Text(
                      showLocationField
                          ? strings.t('compose_location_added')
                          : strings.t('compose_add_location'),
                      style: const TextStyle(fontSize: 12)),
                  onPressed: () => setSheetState(() => showLocationField = true),
                ),
                ActionChip(
                  avatar: const Icon(Icons.menu_book_outlined, size: 16),
                  label: Text(
                      showCourseField
                          ? strings.t('compose_course_added')
                          : strings.t('compose_add_course'),
                      style: const TextStyle(fontSize: 12)),
                  onPressed: () => setSheetState(() => showCourseField = true),
                ),
              ]),
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
              if (showCourseField) ...[
                const SizedBox(height: 8),
                TextField(
                  controller: courseController,
                  decoration: InputDecoration(
                    hintText: strings.t('compose_course_hint'),
                    prefixIcon: const Icon(Icons.menu_book_outlined, size: 18),
                    isDense: true,
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.close, size: 16),
                      onPressed: () => setSheetState(() {
                        showCourseField = false;
                        courseController.clear();
                      }),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 6),
              Text(strings.t('compose_hashtag_tip'),
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
              const SizedBox(height: 14),
              Text(strings.t('social_visibility'),
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
              const SizedBox(height: 6),
              Row(children: [
                SelectableChip(
                  label: strings.t('social_visibility_everyone'),
                  selected: visibility == PostVisibility.everyone,
                  onSelected: (_) => setSheetState(() => visibility = PostVisibility.everyone),
                ),
                const SizedBox(width: 8),
                SelectableChip(
                  label: strings.t('social_visibility_only_me'),
                  selected: visibility == PostVisibility.onlyMe,
                  onSelected: (_) => setSheetState(() => visibility = PostVisibility.onlyMe),
                ),
              ]),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
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
  if (text.isEmpty && pickedBytes == null) return false;
  try {
    if (pickedBytes != null) {
      await ImageModerationService.assertImageAllowed(pickedBytes!, repository);
    }
    await repository.createPost(text.isEmpty ? strings.t('compose_default_caption') : text,
        imageBytes: pickedBytes,
        visibility: visibility,
        postType: postType,
        courseTag: courseController.text.trim().isEmpty ? null : courseController.text.trim(),
        locationTag:
            locationController.text.trim().isEmpty ? null : locationController.text.trim());
  } on ContentModerationException catch (e) {
    if (!context.mounted) return false;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.reason)));
    return false;
  }
  return true;
}
