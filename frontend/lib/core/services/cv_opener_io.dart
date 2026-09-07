import 'dart:io';

import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

Future<void> openDocumentBytes({
  required List<int> bytes,
  required String fileName,
  String mimeType = 'application/pdf',
}) async {
  if (bytes.isEmpty) {
    throw StateError('Boş dosya');
  }
  final safe = fileName.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
  final name = safe.isEmpty ? 'cv.pdf' : safe;
  final dir = await getTemporaryDirectory();
  final file = File('${dir.path}/$name');
  await file.writeAsBytes(bytes, flush: true);
  await OpenFilex.open(file.path, type: mimeType);
}
