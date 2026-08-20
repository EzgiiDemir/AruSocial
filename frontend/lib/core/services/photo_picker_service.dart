import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

/// Real device photo capture/selection — camera or gallery — shared by
/// every "add a photo" flow in the app (place check-ins, social posts).
/// Returns the picked image's raw bytes, or null if the user cancelled.
class PhotoPickerService {
  static final ImagePicker _picker = ImagePicker();

  static Future<Uint8List?> pick(BuildContext context,
      {int imageQuality = 82, double maxWidth = 1600}) async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('Kameradan çek'),
            onTap: () => Navigator.of(ctx).pop(ImageSource.camera),
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined),
            title: const Text('Galeriden seç'),
            onTap: () => Navigator.of(ctx).pop(ImageSource.gallery),
          ),
        ]),
      ),
    );
    if (source == null) return null;

    try {
      final file = await _picker.pickImage(
          source: source, imageQuality: imageQuality, maxWidth: maxWidth);
      if (file == null) return null;
      return await file.readAsBytes();
    } catch (e) {
      if (!context.mounted) return null;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Fotoğraf alınamadı: $e')));
      return null;
    }
  }
}
