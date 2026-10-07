/// Soft hierarchy over flat `directory_entries` rows — no separate
/// buildings / floors tables on the backend.
class CampusBuilding {
  final String id;
  final String name;
  final int entryCount;

  const CampusBuilding({
    required this.id,
    required this.name,
    required this.entryCount,
  });

  factory CampusBuilding.fromJson(Map<String, dynamic> json) => CampusBuilding(
        id: json['id'] as String,
        name: json['name'] as String,
        entryCount: json['entryCount'] as int? ?? 0,
      );
}

class CampusFloor {
  final String id;
  final String name;
  final String building;
  final int entryCount;

  const CampusFloor({
    required this.id,
    required this.name,
    required this.building,
    required this.entryCount,
  });

  factory CampusFloor.fromJson(Map<String, dynamic> json) => CampusFloor(
        id: json['id'] as String,
        name: json['name'] as String,
        building: json['building'] as String,
        entryCount: json['entryCount'] as int? ?? 0,
      );
}

/// One room / occupant row under a building+floor pair.
class CampusRoom {
  final String id;
  final String? room;
  final String building;
  final String? floor;
  final String occupantName;
  final String? occupantRole;
  final String? relatedServiceId;
  final String? tourUrl;
  final String? tourTarget;
  final String? campusName;
  final String? categoryName;
  final String? roomNumber;
  final String? notes;
  final String? splatSceneId;
  final String? splatSceneUrl;
  final Map<String, dynamic>? location;
  final Map<String, dynamic>? navigationMarker;
  final DateTime? directorySyncedAt;

  const CampusRoom({
    required this.id,
    this.room,
    required this.building,
    this.floor,
    required this.occupantName,
    this.occupantRole,
    this.relatedServiceId,
    this.tourUrl,
    this.tourTarget,
    this.campusName,
    this.categoryName,
    this.roomNumber,
    this.notes,
    this.splatSceneId,
    this.splatSceneUrl,
    this.location,
    this.navigationMarker,
    this.directorySyncedAt,
  });

  factory CampusRoom.fromJson(Map<String, dynamic> json) => CampusRoom(
        id: json['id'] as String,
        room: json['room'] as String?,
        building: json['building'] as String,
        floor: json['floor'] as String?,
        occupantName: json['occupantName'] as String? ?? '',
        occupantRole: json['occupantRole'] as String?,
        relatedServiceId: json['relatedServiceId'] as String?,
        tourUrl: json['tourUrl'] as String?,
        tourTarget: json['tourTarget'] as String?,
        campusName: json['campusName'] as String?,
        categoryName: json['categoryName'] as String?,
        roomNumber: json['roomNumber'] as String?,
        notes: json['notes'] as String?,
        splatSceneId: json['splatSceneId'] as String?,
        splatSceneUrl: json['splatSceneUrl'] as String?,
        location: (json['location'] as Map?)?.cast<String, dynamic>(),
        navigationMarker:
            (json['navigationMarker'] as Map?)?.cast<String, dynamic>(),
        directorySyncedAt: json['directorySyncedAt'] == null
            ? null
            : DateTime.tryParse(json['directorySyncedAt'] as String),
      );

  String get title {
    if (room != null && room!.isNotEmpty) return room!;
    return occupantName.isNotEmpty ? occupantName : id;
  }
}
