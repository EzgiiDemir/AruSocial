import '../models/campus_models.dart';
import 'media_url.dart';

class CampusUserDto {
  CampusUserDto({
    required this.id,
    required this.name,
    required this.role,
    required this.level,
    required this.xp,
    required this.places,
    required this.events,
    required this.memories,
    required this.interests,
    this.avatarUrl,
    this.department,
    this.year,
    this.university,
    this.clubs = const [],
    this.achievements = const [],
    this.projects = const [],
    this.isPrivateProfile = false,
    this.isLocked = false,
  });

  final String id;
  final String name;
  final String role;
  final int level;
  final int xp;
  final int places;
  final int events;
  final int memories;
  final List<String> interests;
  final String? avatarUrl;
  final String? department;
  final String? year;
  final String? university;
  final List<String> clubs;
  final List<String> achievements;
  final List<String> projects;
  final bool isPrivateProfile;
  final bool isLocked;

  factory CampusUserDto.fromJson(Map<String, dynamic> json) {
    return CampusUserDto(
      id: json['id'] as String,
      name: json['name'] as String,
      role: json['role'] as String,
      level: json['level'] as int,
      xp: json['xp'] as int,
      places: json['places'] as int,
      events: json['events'] as int,
      memories: json['memories'] as int,
      interests: (json['interests'] as List<dynamic>).cast<String>(),
      avatarUrl: MediaUrl.resolve(json['avatarUrl'] as String?),
      isPrivateProfile: json['isPrivateProfile'] as bool? ?? false,
      isLocked: json['isLocked'] as bool? ?? false,
      department: json['department'] as String?,
      year: json['year'] as String?,
      university: json['university'] as String?,
      clubs: (json['clubs'] as List<dynamic>?)?.cast<String>() ?? const [],
      achievements: (json['achievements'] as List<dynamic>?)?.cast<String>() ?? const [],
      projects: (json['projects'] as List<dynamic>?)?.cast<String>() ?? const [],
    );
  }

  CampusUser toDomain() {
    return CampusUser(
      id: id,
      name: name,
      role: role,
      level: level,
      xp: xp,
      places: places,
      events: events,
      memories: memories,
      interests: interests,
      avatarUrl: avatarUrl,
      department: department,
      year: year,
      university: university,
      clubs: clubs,
      achievements: achievements,
      projects: projects,
      isPrivateProfile: isPrivateProfile,
      isLocked: isLocked,
    );
  }
}

class CampusPlaceDto {
  CampusPlaceDto({
    required this.id,
    required this.name,
    required this.category,
    required this.lat,
    required this.lng,
    required this.description,
    required this.distance,
    required this.density,
    required this.street,
    required this.tourUrl,
    this.tourTarget,
    required this.accessible,
    required this.photos,
    required this.rating,
    this.coverUrl,
    this.recentCheckins = 0,
    this.recentCheckinEntries = const [],
  });

  final String id;
  final String name;
  final String category;
  final double lat;
  final double lng;
  final String description;
  final String distance;
  final String density;
  final String street;
  final String? tourUrl;
  final String? tourTarget;
  final bool accessible;
  final int photos;
  final double rating;
  final String? coverUrl;
  final int recentCheckins;
  final List<CampusCheckinEntry> recentCheckinEntries;

  factory CampusPlaceDto.fromJson(Map<String, dynamic> json) {
    return CampusPlaceDto(
      id: json['id'] as String,
      name: json['name'] as String,
      category: json['category'] as String,
      lat: (json['lat'] as num).toDouble(),
      lng: (json['lng'] as num).toDouble(),
      description: json['description'] as String,
      distance: json['distance'] as String,
      density: json['density'] as String,
      street: json['street'] as String,
      tourUrl: json['tourUrl'] as String?,
      tourTarget: json['tourTarget'] as String?,
      accessible: json['accessible'] as bool,
      photos: json['photos'] as int,
      rating: (json['rating'] as num).toDouble(),
      coverUrl: json['coverUrl'] as String?,
      recentCheckins: (json['recentCheckins'] as num?)?.toInt() ?? 0,
      recentCheckinEntries: (json['recentCheckinEntries'] as List<dynamic>?)
              ?.map((e) => CampusCheckinEntry.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }

  CampusPlace toDomain() {
    return CampusPlace(
      id: id,
      name: name,
      category: category,
      lat: lat,
      lng: lng,
      description: description,
      distance: distance,
      density: density,
      street: street,
      tourUrl: tourUrl,
      tourTarget: tourTarget,
      accessible: accessible,
      photos: photos,
      rating: rating,
      coverUrl: coverUrl,
      recentCheckins: recentCheckins,
      recentCheckinEntries: recentCheckinEntries,
    );
  }
}

class QuestDto {
  QuestDto({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.progress,
    required this.target,
    required this.reward,
  });

  final String id;
  final String title;
  final String subtitle;
  final int progress;
  final int target;
  final int reward;

  factory QuestDto.fromJson(Map<String, dynamic> json) {
    return QuestDto(
      id: json['id'] as String,
      title: json['title'] as String,
      subtitle: json['subtitle'] as String,
      progress: json['progress'] as int,
      target: json['target'] as int,
      reward: json['reward'] as int,
    );
  }

  Quest toDomain() {
    return Quest(
      id: id,
      title: title,
      subtitle: subtitle,
      progress: progress,
      target: target,
      reward: reward,
    );
  }
}
