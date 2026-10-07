import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/features/widgets/linkified_text.dart';

/// AICAD cites a source URL in nearly every grounded answer. Rendered as plain
/// text those were unusable — readable but not openable, and not selectable on
/// a phone.
void main() {
  /// Walks the rendered rich text and returns the spans that carry a tap
  /// recogniser, i.e. the ones a user can actually press.
  List<TextSpan> tappableSpans(WidgetTester tester) {
    final widget = tester.widget<Text>(find.byType(Text));
    final span = widget.textSpan! as TextSpan;

    return [
      for (final child in span.children!.cast<TextSpan>())
        if (child.recognizer is TapGestureRecognizer) child,
    ];
  }

  Future<void> pump(WidgetTester tester, String text) => tester.pumpWidget(
        MaterialApp(home: Scaffold(body: LinkifiedText(text))),
      );

  testWidgets('a cited source URL becomes a tappable link', (tester) async {
    await pump(tester,
        'Burs oranları için: https://aday.arucad.edu.tr/burs-ve-indirimler/');

    final links = tappableSpans(tester);
    expect(links, hasLength(1));
    expect(links.single.text, 'https://aday.arucad.edu.tr/burs-ve-indirimler/');
    expect(links.single.style!.decoration, TextDecoration.underline);
  });

  testWidgets('sentence punctuation stays out of the link', (tester) async {
    await pump(tester, '(Kaynak: https://arucad.edu.tr/burslar/).');

    final links = tappableSpans(tester);
    expect(links, hasLength(1));
    // The ")" and "." are punctuation, not part of the address.
    expect(links.single.text, 'https://arucad.edu.tr/burslar/');
  });

  testWidgets('an e-mail address is tappable too', (tester) async {
    await pump(tester, 'Yazabilirsin: ogrenciisleri@arucad.edu.tr');

    final links = tappableSpans(tester);
    expect(links, hasLength(1));
    expect(links.single.text, 'ogrenciisleri@arucad.edu.tr');
  });

  testWidgets('several links in one answer are each tappable', (tester) async {
    await pump(
      tester,
      'Bak https://aday.arucad.edu.tr/burs-ve-indirimler/ ve '
      'https://arucad.edu.tr/ sayfalarına.',
    );

    expect(tappableSpans(tester), hasLength(2));
  });

  testWidgets('plain text carries no links', (tester) async {
    await pump(tester, 'Bu bilgiyi kaynaklarda bulamadım.');

    expect(tappableSpans(tester), isEmpty);
  });
}
