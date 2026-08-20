import 'contracts.dart';

class MockAnalyticsTracker implements AnalyticsTracker {
  @override
  void track(String event, [Map<String, Object?> properties = const {}]) {
    // Placeholder for analytics integration.
    // Replace with Azure Application Insights / Firebase / Segment adapter.
    // ignore: avoid_print
    print('Analytics event: $event, properties: $properties');
  }
}
