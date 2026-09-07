/// Real, read-only configuration/reachability status — reuses whatever
/// each service already exposes (RoutingService::isConfigured(), the
/// moderation/Entra/WordPress `configured` booleans, HealthController's
/// own DB probe) rather than adding new checks. Not a monitoring platform
/// — just "is this connected right now."
class SystemHealth {
  final bool database;
  final bool routingConfigured;
  final bool moderationConfigured;
  final bool entraConfigured;
  final bool wordpressConfigured;
  final bool aiConfigured;
  final bool broadcastingConfigured;

  const SystemHealth({
    required this.database,
    required this.routingConfigured,
    required this.moderationConfigured,
    required this.entraConfigured,
    required this.wordpressConfigured,
    required this.aiConfigured,
    required this.broadcastingConfigured,
  });

  factory SystemHealth.fromJson(Map<String, dynamic> json) => SystemHealth(
        database: json['database'] as bool? ?? false,
        routingConfigured: json['routingConfigured'] as bool? ?? false,
        moderationConfigured: json['moderationConfigured'] as bool? ?? false,
        entraConfigured: json['entraConfigured'] as bool? ?? false,
        wordpressConfigured: json['wordpressConfigured'] as bool? ?? false,
        aiConfigured: json['aiConfigured'] as bool? ?? false,
        broadcastingConfigured: json['broadcastingConfigured'] as bool? ?? false,
      );
}
