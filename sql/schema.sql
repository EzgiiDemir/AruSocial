-- Generated from Laravel migration state (SQLite sqlite_master dump).
-- Source of truth: backend/database/migrations/. Do not edit by hand;
-- update migrations, then regenerate (see sql/README.md).
-- No seed data. Does not represent sql/database.sqlite runtime contents.

CREATE TABLE "academic_years" ("id" varchar not null, "label" varchar not null, "starts_on" date not null, "ends_on" date not null, "is_active" tinyint(1) not null default '0', primary key ("id"));

CREATE TABLE "activity_log" ("id" varchar not null, "user_id" integer not null, "kind" varchar not null, "title" varchar not null, "subtitle" varchar not null, "meta" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "admin_audit_log" ("id" varchar not null, "actor_name" varchar not null, "action" varchar not null, "target_type" varchar not null, "target_label" varchar not null, "at" datetime not null, primary key ("id"));

CREATE TABLE "admin_pages" ("id" varchar not null, "title" varchar not null, "slug" varchar not null, "blocks" text, "status" varchar not null default 'draft', "updated_at" datetime not null, "updated_by" varchar not null, primary key ("id"));

CREATE TABLE "app_settings" ("key" varchar not null, "value" text, primary key ("key"));

CREATE TABLE "cache" ("key" varchar not null, "value" text not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "cache_locks" ("key" varchar not null, "owner" varchar not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "chat_messages" ("id" varchar not null, "user_id" integer not null, "peer_name" varchar not null, "from_me" tinyint(1) not null, "text" text not null, "sent_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "checkins" ("id" varchar not null, "place_id" varchar not null, "user_id" integer not null, "visible_to_others" tinyint(1) not null default '1', "created_at" datetime not null, foreign key("place_id") references "places"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "clubs" ("id" varchar not null, "name" varchar not null, "category" varchar not null, "description" text not null default '', "body" text, primary key ("id"));

CREATE TABLE "content_revisions" ("id" varchar not null, "content_key" varchar not null, "editor_name" varchar not null, "snapshot" text not null, "saved_at" datetime not null, primary key ("id"));

CREATE TABLE "conversation_participants" ("id" integer primary key autoincrement not null, "conversation_id" integer not null, "user_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("conversation_id") references "conversations"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "conversations" ("id" integer primary key autoincrement not null, "pair_key" varchar not null, "created_at" datetime, "updated_at" datetime);

CREATE TABLE "directory_entries" ("id" varchar not null, "building" varchar not null, "floor" varchar, "room" varchar, "occupant_name" varchar not null, "occupant_role" varchar, "related_service_id" varchar, foreign key("related_service_id") references "services"("id") on delete set null, primary key ("id"));

CREATE TABLE "drafts" ("content_key" varchar not null, "blocks" text not null, "updated_at" datetime not null, primary key ("content_key"));

CREATE TABLE "email_logs" ("id" varchar not null, "to_email" varchar not null, "subject" varchar not null, "template" varchar not null, "status" varchar not null, "error" text, "attempts" integer not null default '1', "sent_at" datetime not null, primary key ("id"));

CREATE TABLE "event_joins" ("id" varchar not null, "event_id" varchar not null, "user_id" integer not null, "participation_type_id" varchar, "joined_at" datetime not null, "approved_at" datetime, "approved_by" varchar, "form_submitted_at" datetime, foreign key("event_id") references "events"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, foreign key("participation_type_id") references "event_participation_types"("id") on delete set null, primary key ("id"));

CREATE TABLE "event_participation_types" ("id" varchar not null, "event_id" varchar not null, "label" varchar not null, "sort_order" integer not null default '0', foreign key("event_id") references "events"("id") on delete cascade, primary key ("id"));

CREATE TABLE "events" ("id" varchar not null, "title" varchar not null, "time" varchar not null, "place_name" varchar not null, "category" varchar not null, "attendees" integer not null default ('0'), "xp" integer not null default ('0'), "draft" tinyint(1) not null default ('0'), "publish_at" datetime, "expires_at" datetime, "audience" varchar not null default ('Tümü'), "organizer" varchar not null default (''), "description" text not null default (''), "created_by_user_id" integer, "workflow_status" varchar not null default 'published', "review_note" text, "place_id" varchar, "academic_year_id" varchar, "organizer_email" varchar, "event_date" date, foreign key("created_by_user_id") references "users"("id") on delete set null, foreign key("place_id") references "places"("id") on delete set null, foreign key("academic_year_id") references "academic_years"("id") on delete set null, primary key ("id"));

CREATE TABLE "failed_jobs" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "connection" varchar not null, "queue" varchar not null, "payload" text not null, "exception" text not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);

CREATE TABLE "feed_posts" ("id" varchar not null, "name" varchar not null, "text" text not null default (''), "meta" varchar not null default (''), "image_url" varchar, "visibility" varchar not null default ('everyone'), "post_type" varchar not null default ('normal'), "course_tag" varchar, "location_tag" varchar, "official" tinyint(1) not null default ('0'), "created_at" datetime not null, "author_id" integer, foreign key("author_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "food_daily_menus" ("id" varchar not null, "food_venue_id" varchar not null, "menu_date" date not null, "items" text, "price" varchar, "hours" varchar, foreign key("food_venue_id") references "food_venues"("id") on delete cascade, primary key ("id"));

CREATE TABLE "food_venues" ("id" varchar not null, "name" varchar not null, "hours" varchar, "menu_file_url" varchar, primary key ("id"));

CREATE TABLE "job_batches" ("id" varchar not null, "name" varchar not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text, "cancelled_at" integer, "created_at" integer not null, "finished_at" integer, primary key ("id"));

CREATE TABLE "jobs" ("id" integer primary key autoincrement not null, "queue" varchar not null, "payload" text not null, "attempts" integer not null, "reserved_at" integer, "available_at" integer not null, "created_at" integer not null);

CREATE TABLE "media_items" ("id" varchar not null, "file_path" varchar not null, "file_name" varchar not null, "mime_type" varchar, "size_bytes" integer not null default '0', "uploaded_at" datetime not null, "uploaded_by" varchar not null, "used_in" text, primary key ("id"));

CREATE TABLE "messages" ("id" varchar not null, "conversation_id" integer not null, "sender_id" integer not null, "body" text not null, "created_at" datetime, "updated_at" datetime, foreign key("conversation_id") references "conversations"("id") on delete cascade, foreign key("sender_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

CREATE TABLE "moderation_reports" ("id" varchar not null, "kind" varchar not null, "target_id" varchar not null, "target_label" varchar not null, "reason" text not null, "reported_at" datetime not null, "action" varchar, primary key ("id"));

CREATE TABLE "notifications" ("id" varchar not null, "user_id" integer not null, "kind" varchar not null, "title" varchar not null, "body" varchar not null, "read_at" datetime, "created_at" datetime not null, "actor_user_id" integer, foreign key("user_id") references users("id") on delete cascade on update no action, foreign key("actor_user_id") references "users"("id") on delete set null, primary key ("id"));

CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);

CREATE TABLE "places" ("id" varchar not null, "name" varchar not null, "category" varchar not null, "lat" double not null, "lng" double not null, "description" text not null default '', "distance" varchar not null default '', "density" varchar not null default 'quiet', "street" varchar not null default '', "tour_url" varchar, "accessible" tinyint(1) not null default '1', "photos" integer not null default '0', "rating" double not null default '0', primary key ("id"));

CREATE TABLE "post_comments" ("id" varchar not null, "post_id" varchar not null, "text" text not null, "meta" varchar not null default (''), "created_at" datetime not null default (CURRENT_TIMESTAMP), "user_id" integer, foreign key("post_id") references feed_posts("id") on delete cascade on update no action, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "post_likes" ("id" varchar not null, "post_id" varchar not null, "user_id" integer not null, "created_at" datetime not null default CURRENT_TIMESTAMP, foreign key("post_id") references "feed_posts"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "push_tokens" ("id" integer primary key autoincrement not null, "user_id" integer not null, "token" varchar not null, "platform" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "quests" ("id" varchar not null, "user_id" integer not null, "title" varchar not null, "subtitle" varchar not null, "progress" integer not null default '0', "target" integer not null, "reward" integer not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "reviews" ("id" varchar not null, "place_id" varchar not null, "rating" integer not null, "comment" text not null default (''), "meta" varchar not null default (''), "created_at" datetime not null default (CURRENT_TIMESTAMP), "user_id" integer, foreign key("place_id") references places("id") on delete cascade on update no action, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "role_assignments" ("email" varchar not null, "role" varchar not null, "assigned_by" varchar not null, "assigned_at" datetime not null, "permissions" text, primary key ("email"));

CREATE TABLE "saved_posts" ("id" integer primary key autoincrement not null, "user_id" integer not null, "post_id" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, foreign key("post_id") references "feed_posts"("id") on delete cascade);

CREATE TABLE "services" ("id" varchar not null, "title" varchar not null, "category" varchar not null, "description" text not null default '', "contact" varchar not null default '', "building" varchar, "floor" varchar, "room" varchar, "contact_person" varchar, "topics" text, "hours" varchar, "body" text, primary key ("id"));

CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

CREATE TABLE "social_blocks" ("id" integer primary key autoincrement not null, "blocker_user_id" integer not null, "blocked_user_id" integer not null, "created_at" datetime not null, "updated_at" datetime, foreign key("blocker_user_id") references "users"("id") on delete cascade, foreign key("blocked_user_id") references "users"("id") on delete cascade);

CREATE TABLE "social_follows" ("id" integer primary key autoincrement not null, "follower_user_id" integer not null, "followed_user_id" integer not null, "created_at" datetime not null, "updated_at" datetime, foreign key("follower_user_id") references "users"("id") on delete cascade, foreign key("followed_user_id") references "users"("id") on delete cascade);

CREATE TABLE "sports" ("id" varchar not null, "name" varchar not null, "facility" varchar not null, "contact" varchar, primary key ("id"));

CREATE TABLE "stories" ("id" varchar not null, "author_name" varchar not null, "text" text, "background_color_value" integer, "visibility" varchar not null default ('everyone'), "created_at" datetime not null, "author_id" integer, foreign key("author_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "survey_options" ("id" varchar not null, "survey_id" varchar not null, "label" varchar not null, "sort_order" integer not null default '0', foreign key("survey_id") references "surveys"("id") on delete cascade, primary key ("id"));

CREATE TABLE "survey_responses" ("id" integer primary key autoincrement not null, "survey_id" varchar not null, "option_id" varchar not null, "user_id" integer not null, "created_at" datetime not null, foreign key("survey_id") references "surveys"("id") on delete cascade, foreign key("option_id") references "survey_options"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade);

CREATE TABLE "surveys" ("id" varchar not null, "question" varchar not null, "description" text, "starts_at" datetime, "ends_at" datetime, "target_audience" varchar not null default 'Tümü', "multiple_choice" tinyint(1) not null default '0', "anonymous" tinyint(1) not null default '1', "show_results" tinyint(1) not null default '1', "active" tinyint(1) not null default '1', "created_by" varchar not null, "created_at" datetime not null, primary key ("id"));

CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "role" varchar not null default 'student', "level" integer not null default '1', "xp" integer not null default '0', "places" integer not null default '0', "events" integer not null default '0', "memories" integer not null default '0', "interests" text, "avatar_url" varchar, "created_at" datetime, "updated_at" datetime, "strikes" integer not null default '0', "banned_at" datetime);

-- Indexes

CREATE UNIQUE INDEX "admin_pages_slug_unique" on "admin_pages" ("slug");

CREATE INDEX "cache_expiration_index" on "cache" ("expiration");

CREATE INDEX "cache_locks_expiration_index" on "cache_locks" ("expiration");

CREATE INDEX "chat_messages_user_id_peer_name_index" on "chat_messages" ("user_id", "peer_name");

CREATE INDEX "content_revisions_content_key_index" on "content_revisions" ("content_key");

CREATE UNIQUE INDEX "conversation_participants_conversation_id_user_id_unique" on "conversation_participants" ("conversation_id", "user_id");

CREATE INDEX "conversation_participants_user_id_index" on "conversation_participants" ("user_id");

CREATE UNIQUE INDEX "conversations_pair_key_unique" on "conversations" ("pair_key");

CREATE UNIQUE INDEX "event_joins_event_id_user_id_unique" on "event_joins" ("event_id", "user_id");

CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs" ("connection", "queue", "failed_at");

CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");

CREATE UNIQUE INDEX "food_daily_menus_food_venue_id_menu_date_unique" on "food_daily_menus" ("food_venue_id", "menu_date");

CREATE INDEX "jobs_queue_index" on "jobs" ("queue");

CREATE INDEX "messages_conversation_id_created_at_index" on "messages" ("conversation_id", "created_at");

CREATE INDEX "messages_sender_id_index" on "messages" ("sender_id");

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

CREATE UNIQUE INDEX "survey_responses_survey_id_option_id_user_id_unique" on "survey_responses" ("survey_id", "option_id", "user_id");

CREATE UNIQUE INDEX "users_email_unique" on "users" ("email");
