// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use
import 'dart:html' as html;
import 'dart:typed_data';

Future<void> openDocumentBytes({
  required List<int> bytes,
  required String fileName,
  String mimeType = 'application/pdf',
}) async {
  if (bytes.isEmpty) {
    throw StateError('Boş dosya');
  }
  final blob = html.Blob([Uint8List.fromList(bytes)], mimeType);
  final url = html.Url.createObjectUrlFromBlob(blob);
  html.AnchorElement(href: url)
    ..download = fileName
    ..target = '_blank'
    ..click();
  html.Url.revokeObjectUrl(url);
}
