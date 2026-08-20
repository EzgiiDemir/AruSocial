CREATE TABLE "activity_log" ("id" varchar not null, "user_id" integer not null, "kind" varchar not null, "title" varchar not null, "subtitle" varchar not null, "meta" varchar not null, "created_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "cache" ("key" varchar not null, "value" text not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "cache_locks" ("key" varchar not null, "owner" varchar not null, "expiration" integer not null, primary key ("key"));

CREATE TABLE "checkins" ("id" varchar not null, "place_id" varchar not null, "user_id" integer not null, "visible_to_others" tinyint(1) not null default '1', "created_at" datetime not null, foreign key("place_id") references "places"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "event_joins" ("id" varchar not null, "event_id" varchar not null, "user_id" integer not null, "participation_type_id" varchar, "joined_at" datetime not null, foreign key("event_id") references "events"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade, foreign key("participation_type_id") references "event_participation_types"("id") on delete set null, primary key ("id"));

CREATE TABLE "event_participation_types" ("id" varchar not null, "event_id" varchar not null, "label" varchar not null, "sort_order" integer not null default '0', foreign key("event_id") references "events"("id") on delete cascade, primary key ("id"));

CREATE TABLE "events" ("id" varchar not null, "title" varchar not null, "time" varchar not null, "place_name" varchar not null, "category" varchar not null, "attendees" integer not null default '0', "xp" integer not null default '0', "draft" tinyint(1) not null default '0', "publish_at" datetime, "expires_at" datetime, "audience" varchar not null default 'Tümü', "organizer" varchar not null default '', "description" text not null default '', primary key ("id"));

CREATE TABLE "failed_jobs" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "connection" varchar not null, "queue" varchar not null, "payload" text not null, "exception" text not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);

CREATE TABLE "feed_posts" ("id" varchar not null, "author_id" varchar not null default '', "name" varchar not null, "text" text not null default '', "meta" varchar not null default '', "likes" integer not null default '0', "liked_by_me" tinyint(1) not null default '0', "image_url" varchar, "visibility" varchar not null default 'everyone', "post_type" varchar not null default 'normal', "course_tag" varchar, "location_tag" varchar, "official" tinyint(1) not null default '0', "created_at" datetime not null, primary key ("id"));

CREATE TABLE "job_batches" ("id" varchar not null, "name" varchar not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text, "cancelled_at" integer, "created_at" integer not null, "finished_at" integer, primary key ("id"));

CREATE TABLE "jobs" ("id" integer primary key autoincrement not null, "queue" varchar not null, "payload" text not null, "attempts" integer not null, "reserved_at" integer, "available_at" integer not null, "created_at" integer not null);

CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

CREATE TABLE "moderation_reports" ("id" varchar not null, "kind" varchar not null, "target_id" varchar not null, "target_label" varchar not null, "reason" text not null, "reported_at" datetime not null, "action" varchar, primary key ("id"));

CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);

CREATE TABLE "places" ("id" varchar not null, "name" varchar not null, "category" varchar not null, "lat" double not null, "lng" double not null, "description" text not null default '', "distance" varchar not null default '', "density" varchar not null default 'quiet', "street" varchar not null default '', "tour_url" varchar, "accessible" tinyint(1) not null default '1', "photos" integer not null default '0', "rating" double not null default '0', primary key ("id"));

CREATE TABLE "post_comments" ("id" varchar not null, "post_id" varchar not null, "author" varchar not null, "text" text not null, "meta" varchar not null default '', "created_at" datetime not null default CURRENT_TIMESTAMP, foreign key("post_id") references "feed_posts"("id") on delete cascade, primary key ("id"));

CREATE TABLE "quests" ("id" varchar not null, "user_id" integer not null, "title" varchar not null, "subtitle" varchar not null, "progress" integer not null default '0', "target" integer not null, "reward" integer not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"));

CREATE TABLE "reviews" ("id" varchar not null, "place_id" varchar not null, "author" varchar not null, "rating" integer not null, "comment" text not null default '', "meta" varchar not null default '', "created_at" datetime not null default CURRENT_TIMESTAMP, foreign key("place_id") references "places"("id") on delete cascade, primary key ("id"));

CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

CREATE TABLE "stories" ("id" varchar not null, "author_id" varchar not null default '', "author_name" varchar not null, "text" text, "background_color_value" integer, "visibility" varchar not null default 'everyone', "created_at" datetime not null, primary key ("id"));

CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "role" varchar not null default 'student', "level" integer not null default '1', "xp" integer not null default '0', "places" integer not null default '0', "events" integer not null default '0', "memories" integer not null default '0', "interests" text, "avatar_url" varchar, "created_at" datetime, "updated_at" datetime);

