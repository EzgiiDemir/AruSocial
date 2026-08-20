import 'dart:io';

/// Simple script to apply Entra redirect snippets into AndroidManifest.xml and
/// iOS Info.plist if those files exist. Run with `dart run tools/apply_entra_snippets.dart`.

void main() {
  final projectRoot = Directory.current.path;
  final androidManifest =
      File('$projectRoot/android/app/src/main/AndroidManifest.xml');
  final iosPlist = File('$projectRoot/ios/Runner/Info.plist');

  final androidSnippet = '''
    <intent-filter>
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="com.arucad.app" android:host="oauthredirect" />
    </intent-filter>
  ''';

  if (androidManifest.existsSync()) {
    final content = androidManifest.readAsStringSync();
    if (!content.contains('com.arucad.app')) {
      // Naive insertion: add inside the first <activity ...> tag's closing '>' before its inner content.
      final idx = content.indexOf('<activity');
      if (idx != -1) {
        final insertIdx = content.indexOf('>', idx);
        if (insertIdx != -1) {
          final newContent = content.substring(0, insertIdx + 1) +
              '\n' +
              androidSnippet +
              content.substring(insertIdx + 1);
          androidManifest.writeAsStringSync(newContent);
          print(
              'Applied Android Entra snippet to android/app/src/main/AndroidManifest.xml');
        }
      }
    } else {
      print('Android manifest already contains Entra scheme, skipping.');
    }
  } else {
    print(
        'No AndroidManifest.xml found at android/app/src/main/AndroidManifest.xml; skipping Android patch.');
  }

  final iosSnippet = '''
  <key>CFBundleURLTypes</key>
  <array>
    <dict>
      <key>CFBundleTypeRole</key>
      <string>Editor</string>
      <key>CFBundleURLName</key>
      <string>com.arucad.app</string>
      <key>CFBundleURLSchemes</key>
      <array>
        <string>com.arucad.app</string>
      </array>
    </dict>
  </array>
  ''';

  if (iosPlist.existsSync()) {
    final content = iosPlist.readAsStringSync();
    if (!content.contains('com.arucad.app')) {
      // Insert before closing </dict> of the top-level plist dictionary.
      final idx = content.lastIndexOf('</dict>');
      if (idx != -1) {
        final newContent =
            content.substring(0, idx) + iosSnippet + content.substring(idx);
        iosPlist.writeAsStringSync(newContent);
        print('Applied iOS Entra snippet to ios/Runner/Info.plist');
      }
    } else {
      print('iOS Info.plist already contains Entra scheme, skipping.');
    }
  } else {
    print('No Info.plist found at ios/Runner/Info.plist; skipping iOS patch.');
  }
}
