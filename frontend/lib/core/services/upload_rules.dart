import 'dart:typed_data';

/// What the phone is allowed to send, checked before anything is uploaded.
///
/// This mirrors `config('services.media_uploads')` on the server. It is a
/// convenience, never the enforcement: the server re-checks everything,
/// because a client can be modified and an old build can be kept installed.
/// Its value is that a student on mobile data finds out a 400 MB video is
/// too long *before* spending ten minutes uploading it.
class UploadRules {
  const UploadRules._();

  static const imageExtensions = {'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'};
  static const videoExtensions = {'mp4', 'mov', 'webm'};

  static const maxImageBytes = 12 * 1024 * 1024;
  static const maxVideoBytes = 100 * 1024 * 1024;

  static String _extension(String fileName) {
    final dot = fileName.lastIndexOf('.');
    if (dot < 0 || dot == fileName.length - 1) return '';
    return fileName.substring(dot + 1).toLowerCase();
  }

  static bool isVideo(String fileName) =>
      videoExtensions.contains(_extension(fileName));

  static bool isImage(String fileName) =>
      imageExtensions.contains(_extension(fileName));

  /// A human-readable reason the file cannot be sent, or null if it can.
  static String? rejectionReason(Uint8List bytes, String fileName) {
    final ext = _extension(fileName);
    if (ext.isEmpty) {
      return 'Dosyanın uzantısı okunamadı. Lütfen galeriden tekrar seç.';
    }
    final video = videoExtensions.contains(ext);
    final image = imageExtensions.contains(ext);

    if (!video && !image) {
      return 'Bu dosya türü desteklenmiyor (.$ext). '
          'Fotoğraf için JPG, PNG, WEBP veya HEIC; '
          'video için MP4, MOV veya WEBM yükleyebilirsin.';
    }

    final max = video ? maxVideoBytes : maxImageBytes;
    if (bytes.lengthInBytes > max) {
      final mb = (bytes.lengthInBytes / 1048576).toStringAsFixed(1);
      final limit = (max / 1048576).round();
      return '${video ? 'Video' : 'Fotoğraf'} çok büyük ($mb MB). '
          'En fazla $limit MB olabilir.';
    }

    if (bytes.isEmpty) {
      return 'Dosya boş görünüyor. Lütfen tekrar seç.';
    }

    return null;
  }
}
