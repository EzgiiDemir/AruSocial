import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/features/guide/ask_arucad_bubble.dart';

void main() {
  testWidgets('Ask ARUCAD map bubble fires onTap', (tester) async {
    var tapped = false;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: AskArucadBubble(onTap: () => tapped = true),
      ),
    ));
    await tester.tap(find.byType(AskArucadBubble));
    expect(tapped, isTrue);
    expect(tester.getSize(find.byType(AskArucadBubble)), const Size(60, 60));

    final logo = tester.widget<Image>(find.byType(Image));
    expect(logo.width, 52);
    expect(logo.height, 52);
    expect(logo.alignment, Alignment.center);

    // The PNG's coloured pixels are visually left/low inside its square.
    // The image box is shifted right/up so the artwork itself—not merely the
    // transparent canvas—lands at the circle's centre.
    final bubbleCenter = tester.getCenter(find.byType(AskArucadBubble));
    final imageCenter = tester.getCenter(find.byType(Image));
    expect(imageCenter.dx - bubbleCenter.dx, closeTo(5, .1));
    expect(imageCenter.dy - bubbleCenter.dy, closeTo(-4, .1));
  });
}
