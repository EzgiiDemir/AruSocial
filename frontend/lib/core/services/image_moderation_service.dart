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
  static Future<void> assertImageAllowed(Uint8List bytes, CampusRepository repository) {
    return repository.checkImageModeration(bytes);
  }
}
