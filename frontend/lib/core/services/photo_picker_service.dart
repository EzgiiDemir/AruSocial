import 'dart:typed_data';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'package:arucad_campus_prototype/core/services/web_camera.dart';

class PickedPostMedia {
  final Uint8List bytes;
  final String fileName;

  const PickedPostMedia({
    required this.bytes,
    required this.fileName,
  });
}

/// Real device photo capture/selection — camera or gallery — shared by
/// every "add a photo" flow in the app (place check-ins, social posts).
/// Returns the picked image's raw bytes, or null if the user cancelled.
class PhotoPickerService {
  static final ImagePicker _picker = ImagePicker();

  /// Quality and size defaults, in one place because they decide how good
  /// every photo in the app looks.
  ///
  /// These were 82 / 1600px, which is visibly soft on a story: a story
  /// fills a phone screen that is commonly 1080 physical pixels wide at 3x
  /// density, so 1600px of source is being stretched, and a second JPEG
  /// encode at 82 on top of the camera's own is where the mush comes from.
  ///
  /// 2560 at 92 is the compromise. It is roughly 1.5 MB for a typical
  /// phone photo, comfortably inside the 16 MB the server accepts, and
  /// leaves enough resolution that zooming into part of the frame still
  /// looks like a photograph.
  static const defaultQuality = 92;
  static const defaultMaxWidth = 2560.0;

  static Future<Uint8List?> pick(BuildContext context,
      {int imageQuality = defaultQuality,
      double maxWidth = defaultMaxWidth}) async {
    final picked = await pickPostMedia(context,
        imageQuality: imageQuality, maxWidth: maxWidth);
    return picked?.bytes;
  }

  /// A photo, from the camera or the gallery.
  ///
  /// The gallery-video option and the `allowVideo` flag were removed on
  /// 14 September 2026 along with the rest of video support.
  static Future<PickedPostMedia?> pickPostMedia(BuildContext context,
      {int imageQuality = defaultQuality,
      double maxWidth = defaultMaxWidth}) async {
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
        ]),
      ),
    );
    if (choice == null) return null;

    try {
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
            bytes: shot, fileName: 'photo.jpg');
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
      return PickedPostMedia(bytes: bytes, fileName: name);
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
