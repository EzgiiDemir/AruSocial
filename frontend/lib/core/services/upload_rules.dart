import 'dart:typed_data';

/// What the phone is allowed to send, checked before anything is uploaded.
///
/// This mirrors `config('services.media_uploads')` on the server. It is a
/// convenience, never the enforcement: the server re-checks everything,
/// because a client can be modified and an old build can be kept installed.
/// Its value is that a student on mobile data finds out a file is too big
/// *before* spending minutes uploading it.
///
/// Images only. Video was removed from the product on 14 September 2026 —
/// the server answers an upload of one with `VIDEO_NOT_SUPPORTED`, and a
/// video file selected here is rejected by [rejectionReason] with the same
/// explanation rather than a generic "unsupported type".
class UploadRules {
  const UploadRules._();

  static const imageExtensions = {'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'};

  /// Kept only to recognise a video and explain why it cannot be sent.
  static const _retiredVideoExtensions = {'mp4', 'mov', 'webm', 'm4v', 'avi'};

  static const maxImageBytes = 12 * 1024 * 1024;

  static String _extension(String fileName) {
    final dot = fileName.lastIndexOf('.');
    if (dot < 0 || dot == fileName.length - 1) return '';
    return fileName.substring(dot + 1).toLowerCase();
  }

  static bool isImage(String fileName) =>
      imageExtensions.contains(_extension(fileName));

  /// A human-readable reason the file cannot be sent, or null if it can.
  static String? rejectionReason(Uint8List bytes, String fileName) {
    final ext = _extension(fileName);
    if (ext.isEmpty) {
      return 'Dosyanın uzantısı okunamadı. Lütfen galeriden tekrar seç.';
    }

    // Answered before the generic branch: someone picking a clip from their
    // gallery has a specific question, and "unsupported type" does not
    // answer it.
    if (_retiredVideoExtensions.contains(ext)) {
      return 'Video paylaşımı artık desteklenmiyor. Lütfen bir fotoğraf seç.';
    }

    if (!imageExtensions.contains(ext)) {
      return 'Bu dosya türü desteklenmiyor (.$ext). '
          'JPG, PNG, WEBP veya HEIC yükleyebilirsin.';
    }

    if (bytes.lengthInBytes > maxImageBytes) {
      final mb = (bytes.lengthInBytes / 1048576).toStringAsFixed(1);
      final limit = (maxImageBytes / 1048576).round();
      return 'Fotoğraf çok büyük ($mb MB). En fazla $limit MB olabilir.';
    }

    if (bytes.isEmpty) {
      return 'Dosya boş görünüyor. Lütfen tekrar seç.';
    }

    return null;
  }
}
