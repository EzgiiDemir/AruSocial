<?php

/*
| Trusted ARUVERSE app destinations, for AICAD.
|
| The Flutter app has no declarative route table (screens are pushed
| imperatively), so this is the smallest trusted list of what a student can
| actually open: the five bottom tabs (lib/features/campus_shell.dart), the
| Discover tiles (lib/features/explore/explore_screen.dart), and the titled
| screens reached from them. Screens with no title string of their own (the
| full-screen map, the building directory) are not listed. Labels are NOT
| copied here: each entry names the app's own translation key, and the
| labels in every locale are read from the translation tables, so they match
| what the student sees.
|
| AICAD may refer a student only to these. "Open the X section" naming
| anything else is rejected as an unsupported claim (ClaimVerifier). Update
| this list when a screen is added or removed; AicadNavigationConfigTest
| fails when a key is missing from the app's string table or no longer used
| by any screen.
*/

return [
    'destinations' => [
        'home' => ['key' => 'nav_home'],
        'discover' => ['key' => 'nav_explore'],
        'places' => ['key' => 'discover_places', 'parent' => 'discover', 'entity' => 'place'],
        'clubs' => ['key' => 'discover_clubs', 'parent' => 'discover', 'entity' => 'club'],
        'food' => ['key' => 'discover_food', 'parent' => 'discover', 'entity' => 'food_venue'],
        'sports' => ['key' => 'discover_sports', 'parent' => 'discover', 'entity' => 'sport'],
        'calendar' => ['key' => 'discover_calendar', 'parent' => 'discover'],
        'events' => ['key' => 'discover_creative', 'parent' => 'discover', 'entity' => 'event'],
        'services' => ['key' => 'discover_services', 'parent' => 'discover', 'entity' => 'service'],
        'career' => ['key' => 'discover_career', 'parent' => 'discover'],
        'social' => ['key' => 'nav_social'],
        'ask' => ['key' => 'nav_ask'],
        'profile' => ['key' => 'nav_profile'],
        'applications' => ['key' => 'apps_title', 'parent' => 'profile', 'requires' => 'signed_in'],
        'activity' => ['key' => 'quests_title', 'parent' => 'profile', 'requires' => 'signed_in'],
        // The bell on Home, Discover and Social.
        'notifications' => ['key' => 'notif_title', 'requires' => 'signed_in'],
    ],
];
