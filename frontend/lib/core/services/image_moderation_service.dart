import 'dart:typed_data';

import 'contracts.dart';

/// Thin call-site wrapper around [CampusRepository.checkImageModeration] —
/// kept as its own class so `compose_post_sheet.dart`/`social_screen.dart`
/// don't need to know it's a repository call versus a dedicated service.
///
/// The actual vision-moderation check (docs/EKSIKLER.md §26) is fully
/// server-side now: this used to make a direct OpenAI call from the device
/// using an API key read out of on-device SharedPreferences (entered via
/// Admin Panel → Site Settings) — meaning the key shipped to, and the raw
/// external call was made from, the client. `RestCampusRepository` now
/// uploads the bytes to the backend instead; the key lives only in the
/// backend's `app_settings` table (`Admin\SettingsController`) and never
/// reaches this app. Throws [ContentModerationException] if the backend
/// rejects the image.
class ImageModerationService {
  static Future<void> assertImageAllowed(
      Uint8List bytes, CampusRepository repository) {
    return repository.checkImageModeration(bytes, mimeType: sniffMime(bytes));
  }

  /// The image's real format, read from its header.
  ///
  /// This used to be left at the `image/jpeg` default, so a PNG — which is
  /// what a screenshot or a web file picker usually produces — was checked
  /// against the JPEG signature and refused as invalid. The server sniffs
  /// independently and does not trust this value; sending the truth just
  /// keeps the two ends telling the same story.
  static String sniffMime(Uint8List b) {
    bool startsWith(List<int> sig) {
      if (b.length < sig.length) return false;
      for (var i = 0; i < sig.length; i++) {
        if (b[i] != sig[i]) return false;
      }

      return true;
    }

    if (startsWith([0xFF, 0xD8, 0xFF])) return 'image/jpeg';
    if (startsWith([0x89, 0x50, 0x4E, 0x47])) return 'image/png';
    if (startsWith([0x47, 0x49, 0x46, 0x38])) return 'image/gif';
    if (b.length >= 12 &&
        startsWith([0x52, 0x49, 0x46, 0x46]) &&
        String.fromCharCodes(b.sublist(8, 12)) == 'WEBP') {
      return 'image/webp';
    }
    // HEIC/HEIF from an iPhone: ISO base media container, brand at byte 8.
    if (b.length >= 12 && String.fromCharCodes(b.sublist(4, 8)) == 'ftyp') {
      const heic = {'heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1'};
      if (heic.contains(String.fromCharCodes(b.sublist(8, 12)))) {
        return 'image/heic';
      }
    }

    return 'application/octet-stream';
  }
}
