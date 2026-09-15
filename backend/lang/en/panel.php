<?php

/**
 * The Admin and Trainer panels' own labels.
 *
 * Filament ships its own translations for its chrome (buttons, filters,
 * pagination, validation), so only the wording this project writes lives
 * here. Keys are grouped by where they appear rather than alphabetically —
 * a translator works through a screen, not an index.
 */
return [

    'groups' => [
        'campus' => 'Campus',
        'people' => 'People',
        'content' => 'Content',
        'settings' => 'Settings',
    ],

    'dashboard' => [
        'quick_actions' => 'Quick actions',
        'new' => 'New :thing',
        'needs_attention' => 'Needs attention',
        'on_campus' => 'On campus now',
        'my_department' => 'My department',
    ],

    'common' => [
        'deleted_at' => 'Deleted',
        'purge' => 'Delete permanently?',
        'purge_body' => 'This removes the row for good. Deleting normally is enough to take it out of the app, and that one can be undone.',
        'purge_bulk' => 'Delete these permanently?',
        'responsible_staff' => 'Responsible staff member',
        'responsible_staff_help' => 'Who a student is sent to with a question.',
    ],

    'places' => [
        'section' => 'The place',
        'category_help' => 'Groups the place on the Explore screen.',
        'street' => 'Street / address',
        'map' => 'On the map',
        'map_help' => 'Where the pin goes, and how someone gets in.',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'accessible' => 'Step-free access',
        'accessible_help' => 'Shown to students who filter for it, so an optimistic answer here sends someone to a door they cannot use.',
        'distance' => 'Walking distance',
        'distance_help' => 'Free text, e.g. "3 min".',
        'tour' => '360° tour',
        'tour_help' => 'Filled in by the campus-directory sync; override only if it is wrong.',
        'tour_url' => 'Tour URL',
        'tour_target' => 'Tour target',
        'tour_target_help' => 'The scene the tour opens on.',
        'media' => 'Media',
        'cover_url' => 'Cover image URL',
        'has_tour' => 'Has a 360° tour',
    ],

    'clubs' => [
        'section' => 'The club',
        'members' => 'Members',
    ],

    'sports' => [
        'section' => 'The sport',
        'facility_help' => 'Where it is played.',
        'contact_help' => 'Phone, email or a name — free text.',
    ],

    'services' => [
        'section' => 'The service',
        'hours' => 'Opening hours',
        'where' => 'Where to find it',
        'contact_person' => 'Contact person',
        'contact_help' => 'Phone or email.',
        'topics' => 'Topics',
        'topics_help' => 'What a student can come here about. Shown as chips.',
        'add_topic' => 'Add a topic',
    ],

    'food' => [
        'section' => 'The venue',
        'hours' => 'Opening hours',
        'standing_menu' => 'Standing menu',
        'menu_file' => 'Menu file URL',
        'daily_menus' => 'Daily menus',
        'has_menu_file' => 'Menu file',
    ],

    'shuttle' => [
        'section' => 'The route',
        'colour' => 'Colour',
        'colour_help' => 'How the route is drawn in the app.',
        'order' => 'Order',
        'order_help' => 'Lower numbers appear first.',
        'stops' => 'Stops',
        'stops_help' => 'In the order the shuttle visits them.',
        'add_stop' => 'Add a stop',
        'times' => 'Times',
        'times_help' => 'Free text, as printed on the timetable — e.g. 08:30.',
        'departures' => 'Departures',
        'returns' => 'Returns',
        'add_departure' => 'Add a departure',
        'add_return' => 'Add a return',
    ],

    'career' => [
        'section' => 'The opportunity',
        'kind' => 'Kind',
        'kind_help' => 'Internship, full-time, part-time…',
        'work_type' => 'Work type',
        'url' => 'Application link',
        'dates' => 'Dates and visibility',
        'posted' => 'Posted',
        'published_help' => 'Unpublished listings are invisible to students.',
        'details' => 'Details',
        'extra_info' => 'Anything else',
        'expired' => 'Deadline passed',
    ],

    'events' => [
        'section' => 'The event',
        'date' => 'Date',
        'time_help' => 'As it should read, e.g. 18:00 - 20:00.',
        'where' => 'Where',
        'campus_place' => 'Campus place',
        'campus_place_help' => 'Links the event to the map.',
        'place_name' => 'Place name',
        'who' => 'Who',
        'organizer_email' => 'Organizer email',
        'academic_year' => 'Academic year',
        'xp_help' => 'Awarded to a student who attends.',
        'publishing' => 'Publishing',
        'publishing_help' => 'An event is only on the campus calendar when it is published, not a draft, and inside its window.',
        'draft_help' => 'Drafts are invisible to students.',
        'publish_at' => 'Publish at',
        'publish_at_help' => 'Leave empty to publish immediately.',
        'expires_at' => 'Expires at',
        'expires_at_help' => 'Leave empty to stay listed.',
        'review_note' => 'Review note',
        'status' => 'Status',
        'on_calendar' => 'On the campus calendar',
        'upcoming' => 'Upcoming',
        'workflow' => [
            'draft' => 'Draft',
            'pending' => 'Pending review',
            'published' => 'Published',
            'rejected' => 'Rejected',
        ],
    ],

    'media' => [
        'preview' => 'Preview',
        'file' => 'File',
        'uploaded_by' => 'Uploaded by',
        'uploaded_at' => 'Uploaded',
        'size' => 'Size',
        'status' => 'Status',
        'used_in' => 'Used in',
        'unused' => 'Not used anywhere',
        'unused_only' => 'Unused only',
        'missing_file' => 'File is missing',
        'unknown_type' => 'Not a previewable file',
        'approved' => 'Approved',
        'pending' => 'Pending',
        'rejected' => 'Rejected',
        'purge' => 'Delete permanently',
        'purge_bulk' => 'Delete these permanently?',
        'purge_body' => 'This deletes the file from storage. It cannot be undone and no backup is kept.',
        'purge_confirm' => 'I understand this cannot be undone',
        'delete_unused' => 'Nothing is using this file. It can be restored afterwards.',
        'delete_used' => 'This file is used by :count item(s): :list. They will show a blank frame. It can be restored afterwards.',
    ],

    'translations' => [
        'nav' => 'Translations',
        'missing_badge' => 'Strings with no published translation in at least one language',
        'missing' => 'Missing',
        'unpublished' => 'Unpublished edits',
        'publish' => 'Publish',
        'publish_heading' => 'Publish these drafts?',
        'publish_body' => 'Every phone picks this up on its next check. There is no app release involved — and no undo beyond rolling back to a previous version.',
        'published_none' => 'Nothing to publish',
        'published_count' => 'Published :count translation(s)',
        'delete_body' => 'The app calls these keys by name. Deleting one means every installed build shows nothing where that text was.',
    ],

];
