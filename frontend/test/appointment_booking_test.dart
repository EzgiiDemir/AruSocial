import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';

void main() {
  testWidgets('AppointmentBookingScreen loads staff and slot CTA', (tester) async {
    final repo = MockCampusRepository();
    await tester.pumpWidget(MaterialApp(
      home: AppointmentBookingScreen(repository: repo),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Randevu'), findsWidgets);
    expect(find.text('Yeni randevu'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'Danışmanlık');
    await tester.pump();
    expect(find.text('Oluştur'), findsOneWidget);
    await tester.tap(find.text('Oluştur'));
    await tester.pumpAndSettle();
    expect(find.text('Randevu oluştur'), findsOneWidget);
  });

  testWidgets('staff dropdown clips long names without overflow', (tester) async {
    final repo = MockCampusRepository();
    await tester.binding.setSurfaceSize(const Size(360, 900));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
      home: SizedBox(
        width: 360,
        child: AppointmentBookingScreen(repository: repo),
      ),
    ));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byType(DropdownButtonFormField<String>), findsNWidgets(2));
  });

  test('StaffSlot.fromJson reads status', () {
    final slot = StaffSlot.fromJson({
      'id': 's1',
      'staffProfileId': 'st1',
      'date': '2099-01-01',
      'startTime': '10:00',
      'endTime': '10:30',
      'available': false,
      'status': 'booked',
    });
    expect(slot.status, 'booked');
    expect(slot.available, isFalse);
  });
}
