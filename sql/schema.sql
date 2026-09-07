-- Generated from Laravel migration state (SQLite sqlite_master dump).
-- Source of truth: backend/database/migrations/. Do not edit by hand;
-- update migrations, then regenerate (see sql/README.md).
-- No seed data. Does not represent sql/database.sqlite runtime contents.

CREATE TABLE "academic_years" ("id" varchar not null, "label" varchar not null, "starts_on" date not null, "ends_on" date not null, "is_active" tinyint(1) not null default '0', primary key ("id"));

CREATE TABLE "achievement_definitions" ("id" varchar not null, "title" varchar not null, "subtitle" varchar not null default '', "trigger_kind" varchar not null, "threshold" integer not null default '1', "sort_order" integer not null default '0', "active" tinyint(1) not null default '1', primary key ("id"));

CREATE TABLE "activity_log" ("id" varchar not null, "user_id" integer not null, "kind" varchar not null, "title" varchar not null, "subtitle" varchar not null, "meta" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "admin_audit_log" ("id" varchar not null, "actor_name" varchar not null, "action" varchar not null, "target_type" varchar not null, "target_label" varchar not null, "at" datetime not null, primary key ("id"));

CREATE TABLE "admin_pages" ("id" varchar not null, "title" varchar not null, "slug" varchar not null, "blocks" text, "status" varchar not null default 'draft', "updated_at" datetime not null, "updated_by" varchar not null, primary key ("id"));

CREATE TABLE "app_settings" ("key" varchar not null, "value" text, primary key ("key"));

CREATE TABLE "application_questions" ("id" varchar not null, "target_type" varchar not null, "stage" varchar not null, "type" varchar not null, "label" varchar not null, "help_text" varchar, "options" text, "required" tinyint(1) not null default '0', "sort_order" integer not null default '0', "active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, primary key ("id"));

CREATE TABLE "application_status_events" ("id" varchar not null, "application_id" varchar not null, "from_status" varchar, "to_status" varchar not null, "note" text, "actor_user_id" integer, "actor_label" varchar, "created_at" datetime not null, foreign key("application_id") references "participation_applications"("id") on delete cascade, foreign key("actor_user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "appointments" ("id" varchar not null, "staff_profile_id" varchar not null, "student_user_id" integer not null, "slot_date" date not null, "start_time" varchar not null, "end_time" varchar not null, "application_id" varchar, "status" varchar not null default 'booked', "created_at" datetime, "updated_at" datetime, "subject" varchar, "notes" text, "admin_notes" text, foreign key("student_user_id") references "users"("id") on delete cascade, foreign key("staff_profile_id") references "staff_profiles"("id") on delete cascade, primary key ("id"));

CREATE TABLE "ask_conversations" ("id" varchar not null, "user_id" integer not null, "title" varchar not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "ask_messages" ("id" varchar not null, "conversation_id" varchar not null, "role" varchar not null, "content" text not null, "created_at" datetime not null default CURRENT_TIMESTAMP, foreign key("conversation_id") references "ask_conversations"("id") on delete cascade, primary key ("id"));

CREATE TABLE "cache" ("key" varchar not null, "value" text not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "cache_locks" ("key" varchar not null, "owner" varchar not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "career_applications" ("id" varchar not null, "user_id" integer not null, "opportunity_id" varchar not null, "cv_path" varchar, "cv_original_name" varchar, "cv_mime" varchar, "status" varchar not null default 'pending', "admin_notes" text, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("opportunity_id") references "career_opportunities"("id") on delete cascade, primary key ("id"));

CREATE TABLE "career_opportunities" ("id" varchar not null, "title" varchar not null, "kind" varchar not null, "organization" varchar not null default '', "url" varchar, "deadline" date, "description" text, "published" tinyint(1) not null default '1', "created_at" datetime not null default CURRENT_TIMESTAMP, "department" varchar, "purpose" text, "skills" text, "experience" text, "education" text, "work_type" varchar, "location" varchar, "posted_at" date, "extra_info" text, primary key ("id"));

CREATE TABLE "career_profiles" ("id" integer primary key autoincrement not null, "user_id" integer not null, "headline" varchar, "cv_url" varchar, "looking_for_internships" tinyint(1) not null default '0', "looking_for_jobs" tinyint(1) not null default '0', "updated_at" datetime, "expertise" varchar, "cv_path" varchar, "cv_original_name" varchar, "cv_mime" varchar, "cv_size_bytes" integer, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "chat_group_members" ("id" integer primary key autoincrement not null, "group_id" varchar not null, "user_id" integer not null, "created_at" datetime, "updated_at" datetime, "muted_at" datetime, "archived_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("group_id") references "chat_groups"("id") on delete cascade);

CREATE TABLE "chat_group_messages" ("id" varchar not null, "group_id" varchar not null, "sender_user_id" integer not null, "text" text not null, "created_at" datetime not null, foreign key("sender_user_id") references "users"("id") on delete cascade, foreign key("group_id") references "chat_groups"("id") on delete cascade, primary key ("id"));

CREATE TABLE "chat_groups" ("id" varchar not null, "name" varchar not null, "created_by" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("created_by") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "chat_messages" ("id" varchar not null, "user_id" integer not null, "peer_name" varchar not null, "from_me" tinyint(1) not null, "text" text not null, "sent_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "chat_thread_prefs" ("id" integer primary key autoincrement not null, "user_id" integer not null, "peer_user_id" integer not null, "muted_at" datetime, "archived_at" datetime, "restricted_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("peer_user_id") references "users"("id") on delete cascade);

CREATE TABLE "checkins" ("id" varchar not null, "place_id" varchar not null, "user_id" integer not null, "visible_to_others" tinyint(1) not null default '1', "created_at" datetime not null, foreign key("place_id") references "places"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "club_members" ("id" integer primary key autoincrement not null, "user_id" integer not null, "club_id" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, foreign key("club_id") references "clubs"("id") on delete cascade);

CREATE TABLE "clubs" ("id" varchar not null, "name" varchar not null, "category" varchar not null, "description" text not null default '', "body" text, "responsible_staff_id" varchar, primary key ("id"));

CREATE TABLE "collaboration_posts" ("id" varchar not null, "place_id" varchar not null, "author_id" integer not null, "text" text not null, "created_at" datetime not null default CURRENT_TIMESTAMP, "expires_at" datetime not null, foreign key("place_id") references "places"("id") on delete cascade, foreign key("author_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "consultation_applications" ("id" varchar not null, "user_id" integer not null, "consultation_id" varchar not null, "status" varchar not null default 'pending', "notes" text, "admin_notes" text, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("consultation_id") references "consultations"("id") on delete cascade, primary key ("id"));

CREATE TABLE "consultations" ("id" varchar not null, "title" varchar not null, "purpose" text, "audience" text, "content" text, "outcomes" text, "duration" varchar, "format" varchar, "requirements" text, "counselor_name" varchar, "counselor_staff_id" varchar, "published" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, primary key ("id"));

CREATE TABLE "content_revisions" ("id" varchar not null, "content_key" varchar not null, "editor_name" varchar not null, "snapshot" text not null, "saved_at" datetime not null, primary key ("id"));

CREATE TABLE "conversation_participants" ("id" integer primary key autoincrement not null, "conversation_id" integer not null, "user_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("conversation_id") references "conversations"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "conversations" ("id" integer primary key autoincrement not null, "pair_key" varchar not null, "created_at" datetime, "updated_at" datetime);

CREATE TABLE "directory_entries" ("id" varchar not null, "building" varchar not null, "floor" varchar, "room" varchar, "occupant_name" varchar not null, "occupant_role" varchar, "related_service_id" varchar, "tour_url" varchar, "tour_target" varchar, foreign key("related_service_id") references "services"("id") on delete set null, primary key ("id"));

CREATE TABLE "drafts" ("content_key" varchar not null, "blocks" text not null, "updated_at" datetime not null, primary key ("content_key"));

CREATE TABLE "email_logs" ("id" varchar not null, "to_email" varchar not null, "subject" varchar not null, "template" varchar not null, "status" varchar not null, "error" text, "attempts" integer not null default '1', "sent_at" datetime not null, "application_id" varchar, primary key ("id"));

CREATE TABLE "event_joins" ("id" varchar not null, "event_id" varchar not null, "user_id" integer not null, "participation_type_id" varchar, "joined_at" datetime not null, "approved_at" datetime, "approved_by" varchar, "form_submitted_at" datetime, foreign key("event_id") references "events"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, foreign key("participation_type_id") references "event_participation_types"("id") on delete set null, primary key ("id"));

CREATE TABLE "event_participation_types" ("id" varchar not null, "event_id" varchar not null, "label" varchar not null, "sort_order" integer not null default '0', foreign key("event_id") references "events"("id") on delete cascade, primary key ("id"));

CREATE TABLE "events" ("id" varchar not null, "title" varchar not null, "time" varchar not null, "place_name" varchar not null, "category" varchar not null, "attendees" integer not null default ('0'), "xp" integer not null default ('0'), "draft" tinyint(1) not null default ('0'), "publish_at" datetime, "expires_at" datetime, "audience" varchar not null default ('Tümü'), "organizer" varchar not null default (''), "description" text not null default (''), "created_by_user_id" integer, "workflow_status" varchar not null default 'published', "review_note" text, "place_id" varchar, "academic_year_id" varchar, "organizer_email" varchar, "event_date" date, "ai_draft" tinyint(1) not null default '0', "ai_source_media_id" varchar, "responsible_staff_id" varchar, foreign key("created_by_user_id") references "users"("id") on delete set null, foreign key("place_id") references "places"("id") on delete set null, foreign key("academic_year_id") references "academic_years"("id") on delete set null, primary key ("id"));

CREATE TABLE "failed_jobs" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "connection" varchar not null, "queue" varchar not null, "payload" text not null, "exception" text not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);

CREATE TABLE "feed_posts" ("id" varchar not null, "name" varchar not null, "text" text not null default (''), "meta" varchar not null default (''), "image_url" varchar, "visibility" varchar not null default ('everyone'), "post_type" varchar not null default ('normal'), "course_tag" varchar, "location_tag" varchar, "official" tinyint(1) not null default ('0'), "created_at" datetime not null, "author_id" integer, "is_pinned" tinyint(1) not null default '0', "pinned_at" datetime, "pinned_by" integer, "workflow_status" varchar not null default 'published', "review_note" text, "media_mime_type" varchar, foreign key("author_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "food_daily_menus" ("id" varchar not null, "food_venue_id" varchar not null, "menu_date" date not null, "items" text, "price" varchar, "hours" varchar, foreign key("food_venue_id") references "food_venues"("id") on delete cascade, primary key ("id"));

CREATE TABLE "food_venues" ("id" varchar not null, "name" varchar not null, "hours" varchar, "menu_file_url" varchar, primary key ("id"));

CREATE TABLE "job_batches" ("id" varchar not null, "name" varchar not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text, "cancelled_at" integer, "created_at" integer not null, "finished_at" integer, primary key ("id"));

CREATE TABLE "jobs" ("id" integer primary key autoincrement not null, "queue" varchar not null, "payload" text not null, "attempts" integer not null, "reserved_at" integer, "available_at" integer not null, "created_at" integer not null);

CREATE TABLE "media_items" ("id" varchar not null, "file_path" varchar not null, "file_name" varchar not null, "mime_type" varchar, "size_bytes" integer not null default ('0'), "uploaded_at" datetime not null, "uploaded_by" varchar not null, "used_in" text, "user_id" integer, "moderation_status" varchar not null default 'approved', foreign key("user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "messages" ("id" varchar not null, "conversation_id" integer not null, "sender_id" integer not null, "body" text not null, "created_at" datetime, "updated_at" datetime, foreign key("conversation_id") references "conversations"("id") on delete cascade, foreign key("sender_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

CREATE TABLE "moderation_reports" ("id" varchar not null, "kind" varchar not null, "target_id" varchar not null, "target_label" varchar not null, "reason" text not null, "reported_at" datetime not null, "action" varchar, primary key ("id"));

CREATE TABLE "notifications" ("id" varchar not null, "user_id" integer not null, "kind" varchar not null, "title" varchar not null, "body" varchar not null, "read_at" datetime, "created_at" datetime not null, "actor_user_id" integer, "data" text, foreign key("user_id") references users("id") on delete cascade on update no action, foreign key("actor_user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "onboarding_progress" ("id" integer primary key autoincrement not null, "user_id" integer not null, "step_id" varchar not null, "completed_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "onboarding_steps" ("id" varchar not null, "group_label" varchar not null, "title" varchar not null, "detail" text not null, "action_kind" varchar not null default 'info', "ref_id" varchar, "sort_order" integer not null default '0', "active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, primary key ("id"));

CREATE TABLE "participation_applications" ("id" varchar not null, "user_id" integer not null, "target_type" varchar not null, "target_id" varchar not null, "status" varchar not null default 'submitted', "responsible_staff_id" varchar, "form_payload" text, "review_note" text, "submitted_at" datetime not null, "reviewed_at" datetime, "reviewed_by_user_id" integer, "detail_payload" text, "detail_form_token" varchar, "detail_form_submitted_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("reviewed_by_user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);

CREATE TABLE "places" ("id" varchar not null, "name" varchar not null, "category" varchar not null, "lat" double not null, "lng" double not null, "description" text not null default '', "distance" varchar not null default '', "density" varchar not null default 'quiet', "street" varchar not null default '', "tour_url" varchar, "accessible" tinyint(1) not null default '1', "photos" integer not null default '0', "rating" double not null default '0', "cover_url" varchar, "tour_target" varchar, primary key ("id"));

CREATE TABLE "post_comments" ("id" varchar not null, "post_id" varchar not null, "text" text not null, "meta" varchar not null default (''), "created_at" datetime not null default (CURRENT_TIMESTAMP), "user_id" integer, foreign key("post_id") references feed_posts("id") on delete cascade on update no action, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "post_likes" ("id" varchar not null, "post_id" varchar not null, "user_id" integer not null, "created_at" datetime not null default CURRENT_TIMESTAMP, foreign key("post_id") references "feed_posts"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "push_tokens" ("id" integer primary key autoincrement not null, "user_id" integer not null, "token" varchar not null, "platform" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "quests" ("id" varchar not null, "user_id" integer not null, "title" varchar not null, "subtitle" varchar not null, "progress" integer not null default '0', "target" integer not null, "reward" integer not null, "kind" varchar not null default 'static', foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "reviews" ("id" varchar not null, "place_id" varchar not null, "rating" integer not null, "comment" text not null default (''), "meta" varchar not null default (''), "created_at" datetime not null default (CURRENT_TIMESTAMP), "user_id" integer, foreign key("place_id") references places("id") on delete cascade on update no action, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "role_assignments" ("email" varchar not null, "role" varchar not null, "assigned_by" varchar not null, "assigned_at" datetime not null, "permissions" text, primary key ("email"));

CREATE TABLE "saved_posts" ("id" integer primary key autoincrement not null, "user_id" integer not null, "post_id" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, foreign key("post_id") references "feed_posts"("id") on delete cascade);

CREATE TABLE "services" ("id" varchar not null, "title" varchar not null, "category" varchar not null, "description" text not null default '', "contact" varchar not null default '', "building" varchar, "floor" varchar, "room" varchar, "contact_person" varchar, "topics" text, "hours" varchar, "body" text, "responsible_staff_id" varchar, primary key ("id"));

CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

CREATE TABLE "shuttle_routes" ("id" varchar not null, "name" varchar not null, "color_key" varchar not null default 'blue', "stops" text not null, "departures" text not null, "returns" text, "sort_order" integer not null default '0', "created_at" datetime, "updated_at" datetime, primary key ("id"));

CREATE TABLE "social_blocks" ("id" integer primary key autoincrement not null, "blocker_user_id" integer not null, "blocked_user_id" integer not null, "created_at" datetime not null, "updated_at" datetime, foreign key("blocker_user_id") references "users"("id") on delete cascade, foreign key("blocked_user_id") references "users"("id") on delete cascade);

CREATE TABLE "social_follows" ("id" integer primary key autoincrement not null, "follower_user_id" integer not null, "followed_user_id" integer not null, "created_at" datetime not null, "updated_at" datetime, "status" varchar not null default 'accepted', foreign key("follower_user_id") references "users"("id") on delete cascade, foreign key("followed_user_id") references "users"("id") on delete cascade);

CREATE TABLE "sports" ("id" varchar not null, "name" varchar not null, "facility" varchar not null, "contact" varchar, "responsible_staff_id" varchar, primary key ("id"));

CREATE TABLE "staff_availability_slots" ("id" varchar not null, "staff_profile_id" varchar not null, "slot_date" date not null, "start_time" varchar not null, "end_time" varchar not null, "is_blocked" tinyint(1) not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("staff_profile_id") references "staff_profiles"("id") on delete cascade, primary key ("id"));

CREATE TABLE "staff_profiles" ("id" varchar not null, "name" varchar not null, "faculty" varchar, "department" varchar, "title" varchar, "email" varchar, "is_department_head" tinyint(1) not null default '0', "active" tinyint(1) not null default '1', "user_id" integer, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "stories" ("id" varchar not null, "author_name" varchar not null, "text" text, "background_color_value" integer, "visibility" varchar not null default ('everyone'), "created_at" datetime not null, "author_id" integer, "image_url" varchar, "media_mime_type" varchar, "style_json" text, foreign key("author_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "story_views" ("id" integer primary key autoincrement not null, "story_id" varchar not null, "viewer_user_id" integer not null, "viewed_at" datetime not null, foreign key("viewer_user_id") references "users"("id") on delete cascade, foreign key("story_id") references "stories"("id") on delete cascade);

CREATE TABLE "survey_options" ("id" varchar not null, "survey_id" varchar not null, "label" varchar not null, "sort_order" integer not null default '0', foreign key("survey_id") references "surveys"("id") on delete cascade, primary key ("id"));

CREATE TABLE "survey_responses" ("id" integer primary key autoincrement not null, "survey_id" varchar not null, "option_id" varchar not null, "user_id" integer not null, "created_at" datetime not null, foreign key("survey_id") references "surveys"("id") on delete cascade, foreign key("option_id") references "survey_options"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "surveys" ("id" varchar not null, "question" varchar not null, "description" text, "starts_at" datetime, "ends_at" datetime, "target_audience" varchar not null default 'Tümü', "multiple_choice" tinyint(1) not null default '0', "anonymous" tinyint(1) not null default '1', "show_results" tinyint(1) not null default '1', "active" tinyint(1) not null default '1', "created_by" varchar not null, "created_at" datetime not null, primary key ("id"));

CREATE TABLE "user_achievements" ("id" integer primary key autoincrement not null, "user_id" integer not null, "achievement_id" varchar not null, "unlocked_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, foreign key("achievement_id") references "achievement_definitions"("id") on delete cascade);

CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "role" varchar not null default 'student', "level" integer not null default '1', "xp" integer not null default '0', "places" integer not null default '0', "events" integer not null default '0', "memories" integer not null default '0', "interests" text, "avatar_url" varchar, "created_at" datetime, "updated_at" datetime, "strikes" integer not null default '0', "banned_at" datetime, "department" varchar, "year" varchar, "university" varchar, "clubs" text, "achievements" text, "projects" text, "location_visibility" varchar not null default 'ghost', "nearby_discoverable" tinyint(1) not null default '0', "check_in_visible" tinyint(1) not null default '1', "personalization" tinyint(1) not null default '1', "is_private_profile" tinyint(1) not null default '0', "preferred_language" varchar not null default 'TR');

CREATE TABLE "wordpress_form_versions" ("id" varchar not null, "source_url" varchar not null, "content_hash" varchar not null, "version" integer not null, "payload" text not null, "saved_by" integer, "created_at" datetime, "updated_at" datetime, foreign key("saved_by") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "workshop_equipment_items" ("id" varchar not null, "place_id" varchar not null, "name" varchar not null, "available" tinyint(1) not null default '1', "sort_order" integer not null default '0', "updated_by" varchar, "created_at" datetime, "updated_at" datetime, foreign key("place_id") references "places"("id") on delete cascade, primary key ("id"));

-- Indexes

CREATE UNIQUE INDEX "admin_pages_slug_unique" on "admin_pages" ("slug");

CREATE INDEX "application_questions_target_type_stage_active_sort_order_index" on "application_questions" ("target_type", "stage", "active", "sort_order");

CREATE INDEX "application_status_events_application_id_created_at_index" on "application_status_events" ("application_id", "created_at");

CREATE UNIQUE INDEX appointments_active_slot_unique
             ON appointments (staff_profile_id, slot_date, start_time)
             WHERE status IN ('pending', 'approved', 'booked');

CREATE INDEX "appointments_staff_profile_id_slot_date_status_index" on "appointments" ("staff_profile_id", "slot_date", "status");

CREATE INDEX "appointments_student_user_id_status_index" on "appointments" ("student_user_id", "status");

CREATE INDEX "ask_conversations_user_id_updated_at_index" on "ask_conversations" ("user_id", "updated_at");

CREATE INDEX "ask_messages_conversation_id_index" on "ask_messages" ("conversation_id");

CREATE INDEX "cache_expiration_index" on "cache" ("expiration");

CREATE INDEX "cache_locks_expiration_index" on "cache_locks" ("expiration");

CREATE UNIQUE INDEX career_applications_open_unique
             ON career_applications (user_id, opportunity_id)
             WHERE status IN ('pending', 'reviewed', 'shortlisted');

CREATE INDEX "career_applications_opportunity_id_status_index" on "career_applications" ("opportunity_id", "status");

CREATE INDEX "career_applications_user_id_status_index" on "career_applications" ("user_id", "status");

CREATE INDEX "career_opportunities_published_created_at_index" on "career_opportunities" ("published", "created_at");

CREATE UNIQUE INDEX "career_profiles_user_id_unique" on "career_profiles" ("user_id");

CREATE UNIQUE INDEX "chat_group_members_group_id_user_id_unique" on "chat_group_members" ("group_id", "user_id");

CREATE INDEX "chat_group_messages_group_id_created_at_index" on "chat_group_messages" ("group_id", "created_at");

CREATE INDEX "chat_messages_user_id_peer_name_index" on "chat_messages" ("user_id", "peer_name");

CREATE UNIQUE INDEX "chat_thread_prefs_user_id_peer_user_id_unique" on "chat_thread_prefs" ("user_id", "peer_user_id");

CREATE UNIQUE INDEX "club_members_user_id_club_id_unique" on "club_members" ("user_id", "club_id");

CREATE INDEX "consultation_applications_consultation_id_status_index" on "consultation_applications" ("consultation_id", "status");

CREATE UNIQUE INDEX consultation_applications_open_unique
             ON consultation_applications (user_id, consultation_id)
             WHERE status IN ('pending', 'reviewed', 'shortlisted');

CREATE INDEX "consultation_applications_user_id_status_index" on "consultation_applications" ("user_id", "status");

CREATE INDEX "consultations_published_created_at_index" on "consultations" ("published", "created_at");

CREATE INDEX "content_revisions_content_key_index" on "content_revisions" ("content_key");

CREATE UNIQUE INDEX "conversation_participants_conversation_id_user_id_unique" on "conversation_participants" ("conversation_id", "user_id");

CREATE INDEX "conversation_participants_user_id_index" on "conversation_participants" ("user_id");

CREATE UNIQUE INDEX "conversations_pair_key_unique" on "conversations" ("pair_key");

CREATE INDEX "email_logs_application_id_index" on "email_logs" ("application_id");

CREATE UNIQUE INDEX "event_joins_event_id_user_id_unique" on "event_joins" ("event_id", "user_id");

CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs" ("connection", "queue", "failed_at");

CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");

CREATE INDEX "feed_posts_is_pinned_pinned_at_index" on "feed_posts" ("is_pinned", "pinned_at");

CREATE INDEX "feed_posts_workflow_status_index" on "feed_posts" ("workflow_status");

CREATE UNIQUE INDEX "food_daily_menus_food_venue_id_menu_date_unique" on "food_daily_menus" ("food_venue_id", "menu_date");

CREATE INDEX "jobs_queue_index" on "jobs" ("queue");

CREATE INDEX "media_items_moderation_status_index" on "media_items" ("moderation_status");

CREATE INDEX "media_items_user_id_index" on "media_items" ("user_id");

CREATE INDEX "messages_conversation_id_created_at_index" on "messages" ("conversation_id", "created_at");

CREATE INDEX "messages_sender_id_index" on "messages" ("sender_id");

CREATE UNIQUE INDEX "onboarding_progress_user_id_step_id_unique" on "onboarding_progress" ("user_id", "step_id");

CREATE UNIQUE INDEX "participation_applications_detail_form_token_unique" on "participation_applications" ("detail_form_token");

CREATE UNIQUE INDEX participation_applications_open_unique
             ON participation_applications (user_id, target_type, target_id)
             WHERE status IN (
                'preview_submitted',
                'detail_form_pending',
                'detail_form_submitted',
                'under_review',
                'revision_required'
             );

CREATE INDEX "participation_applications_status_submitted_at_index" on "participation_applications" ("status", "submitted_at");

CREATE INDEX "participation_applications_target_type_target_id_status_index" on "participation_applications" ("target_type", "target_id", "status");

CREATE INDEX "participation_applications_user_id_status_index" on "participation_applications" ("user_id", "status");

CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens" ("expires_at");

CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens" ("token");

CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens" ("tokenable_type", "tokenable_id");

CREATE UNIQUE INDEX "post_likes_user_id_post_id_unique" on "post_likes" ("user_id", "post_id");

CREATE UNIQUE INDEX "push_tokens_user_id_token_unique" on "push_tokens" ("user_id", "token");

CREATE UNIQUE INDEX "saved_posts_user_id_post_id_unique" on "saved_posts" ("user_id", "post_id");

CREATE INDEX "sessions_last_activity_index" on "sessions" ("last_activity");

CREATE INDEX "sessions_user_id_index" on "sessions" ("user_id");

CREATE UNIQUE INDEX "social_blocks_by_id_blocker_user_id_blocked_user_id_unique" on "social_blocks" ("blocker_user_id", "blocked_user_id");

CREATE UNIQUE INDEX "social_follows_by_id_follower_user_id_followed_user_id_unique" on "social_follows" ("follower_user_id", "followed_user_id");

CREATE INDEX "staff_profiles_department_active_index" on "staff_profiles" ("department", "active");

CREATE INDEX "staff_profiles_faculty_active_index" on "staff_profiles" ("faculty", "active");

CREATE UNIQUE INDEX "staff_slot_unique" on "staff_availability_slots" ("staff_profile_id", "slot_date", "start_time");

CREATE UNIQUE INDEX "story_views_story_id_viewer_user_id_unique" on "story_views" ("story_id", "viewer_user_id");

CREATE UNIQUE INDEX "survey_responses_survey_id_option_id_user_id_unique" on "survey_responses" ("survey_id", "option_id", "user_id");

CREATE UNIQUE INDEX "user_achievements_user_id_achievement_id_unique" on "user_achievements" ("user_id", "achievement_id");

CREATE UNIQUE INDEX "users_email_unique" on "users" ("email");

CREATE INDEX "wordpress_form_versions_content_hash_index" on "wordpress_form_versions" ("content_hash");

CREATE UNIQUE INDEX "wordpress_form_versions_source_url_version_unique" on "wordpress_form_versions" ("source_url", "version");
