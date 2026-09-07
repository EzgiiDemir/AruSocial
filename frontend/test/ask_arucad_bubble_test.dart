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
  });
}
