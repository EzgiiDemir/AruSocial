import 'cv_opener_io.dart' if (dart.library.html) 'cv_opener_web.dart' as impl;

String documentMimeType(String fileName) {
  final n = fileName.toLowerCase();
  if (n.endsWith('.docx')) {
    return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  }
  if (n.endsWith('.doc')) return 'application/msword';
  return 'application/pdf';
}

/// Opens a private CV (PDF/Word bytes from the authenticated API) in the
/// platform viewer. There is no public URL on purpose.
Future<void> openDocumentBytes({
  required List<int> bytes,
  required String fileName,
  String? mimeType,
}) {
  return impl.openDocumentBytes(
    bytes: bytes,
    fileName: fileName,
    mimeType: mimeType ?? documentMimeType(fileName),
  );
}
