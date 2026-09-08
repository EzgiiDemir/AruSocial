import 'dart:typed_data';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'package:arucad_campus_prototype/core/services/web_camera.dart';

class PickedPostMedia {
  final Uint8List bytes;
  final String fileName;
  final bool isVideo;

  const PickedPostMedia({
    required this.bytes,
    required this.fileName,
    required this.isVideo,
  });
}

/// Real device photo capture/selection — camera or gallery — shared by
/// every "add a photo" flow in the app (place check-ins, social posts).
/// Returns the picked image's raw bytes, or null if the user cancelled.
class PhotoPickerService {
  static final ImagePicker _picker = ImagePicker();

  static Future<Uint8List?> pick(BuildContext context,
      {int imageQuality = 82, double maxWidth = 1600}) async {
    final picked = await pickPostMedia(context,
        imageQuality: imageQuality, maxWidth: maxWidth, allowVideo: false);
    return picked?.bytes;
  }

  /// Photo (camera/gallery) or a gallery video for the social composer.
  static Future<PickedPostMedia?> pickPostMedia(BuildContext context,
      {int imageQuality = 82,
      double maxWidth = 1600,
      bool allowVideo = true}) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('Kameradan çek'),
            // Browsers only expose a camera over HTTPS or on localhost.
            // Reaching a dev server at http://192.168.x.x has neither, so
            // the reason is stated here rather than after a tap that could
            // only ever fail.
            subtitle: kIsWeb && !webContextIsSecure
                ? const Text(
                    'Bu bağlantıda kullanılamaz — https:// veya localhost gerekli',
                    style: TextStyle(fontSize: 11.5))
                : null,
            enabled: !kIsWeb || webContextIsSecure,
            onTap: () => Navigator.of(ctx).pop('camera'),
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined),
            title: const Text('Galeriden fotoğraf'),
            onTap: () => Navigator.of(ctx).pop('photo'),
          ),
          if (allowVideo)
            ListTile(
              leading: const Icon(Icons.videocam_outlined),
              title: const Text('Galeriden video'),
              onTap: () => Navigator.of(ctx).pop('video'),
            ),
        ]),
      ),
    );
    if (choice == null) return null;

    try {
      if (choice == 'video') {
        final file = await _picker.pickVideo(source: ImageSource.gallery);
        if (file == null) return null;
        final bytes = await file.readAsBytes();
        final name = file.name.toLowerCase().endsWith('.mp4') ||
                file.name.toLowerCase().endsWith('.mov') ||
                file.name.toLowerCase().endsWith('.webm')
            ? file.name
            : 'clip.mp4';
        return PickedPostMedia(bytes: bytes, fileName: name, isVideo: true);
      }
      // On the web, `image_picker`'s camera source is a file input with a
      // `capture` hint. A desktop browser ignores it outright, so this used
      // to open a file dialog rather than the webcam. getUserMedia is the
      // only thing that actually opens a camera in a browser.
      if (choice == 'camera' && kIsWeb && webCameraAvailable) {
        // The sheet has closed by now, so the context is checked before it
        // is used to open the capture dialog.
        if (!context.mounted) return null;
        final shot = await captureFromWebCamera(context);
        if (shot == null) return null;

        return PickedPostMedia(
            bytes: shot, fileName: 'photo.jpg', isVideo: false);
      }

      final source =
          choice == 'camera' ? ImageSource.camera : ImageSource.gallery;
      final file = await _picker.pickImage(
          source: source, imageQuality: imageQuality, maxWidth: maxWidth);
      if (file == null) return null;
      final bytes = await file.readAsBytes();
      final lower = file.name.toLowerCase();
      final name = lower.endsWith('.png') ||
              lower.endsWith('.webp') ||
              lower.endsWith('.gif')
          ? file.name
          : 'photo.jpg';
      return PickedPostMedia(bytes: bytes, fileName: name, isVideo: false);
    } catch (e) {
      if (!context.mounted) return null;
      final hint = e.toString().contains('camera') || e.toString().contains('Camera')
          ? 'Kamera izni veya cihaz kamerası gerekli. Telefonda dene; tarayıcıda genelde galeri çalışır.'
          : 'Medya alınamadı: $e';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(hint)));
      return null;
    }
  }
}
