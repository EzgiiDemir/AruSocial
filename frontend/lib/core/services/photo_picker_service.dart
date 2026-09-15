import 'dart:typed_data';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/core/services/web_camera.dart';

/// One picture the author has chosen, with how they want it shown.
///
/// [bytes] is the photo as it came off the camera or out of the gallery
/// and stays that way. The composer used to centre-crop it to 4:5 the
/// instant it was picked and throw the rest away — before the author had
/// seen it, with no way back and no way to change their mind later.
/// [framing] replaces that: it says how to *draw* the photo, and the photo
/// itself is uploaded whole.
class PickedPostMedia {
  final Uint8List bytes;
  final String fileName;

  /// How the author framed it. Defaults to showing the whole picture in a
  /// 4:5 frame, which is a choice about layout and not a cut.
  final MediaFraming framing;

  /// What the picture shows, for anyone who cannot see it.
  final String? altText;

  /// Intrinsic pixel size, once known. The feed needs the shape before the
  /// bytes arrive or every card resizes as its image loads.
  final int? width;
  final int? height;

  /// Where this picture ended up, once it has been uploaded.
  ///
  /// Kept so that retrying a failed publish does not re-upload the items
  /// that already succeeded. Uploading ten photos and having the tenth
  /// fail should cost one photo on the retry, not ten — which matters
  /// most on exactly the bad connection that caused the failure.
  final String? uploadedUrl;

  const PickedPostMedia({
    required this.bytes,
    required this.fileName,
    this.framing = const MediaFraming(fit: FrameFit.fit, aspect: PostAspect.portrait),
    this.altText,
    this.width,
    this.height,
    this.uploadedUrl,
  });

  double? get aspectRatio =>
      (width != null && height != null && height! > 0) ? width! / height! : null;

  PickedPostMedia copyWith({
    MediaFraming? framing,
    String? altText,
    int? width,
    int? height,
    String? uploadedUrl,
  }) {
    return PickedPostMedia(
      bytes: bytes,
      fileName: fileName,
      framing: framing ?? this.framing,
      altText: altText ?? this.altText,
      width: width ?? this.width,
      height: height ?? this.height,
      uploadedUrl: uploadedUrl ?? this.uploadedUrl,
    );
  }
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
