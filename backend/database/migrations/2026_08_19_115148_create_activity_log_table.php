<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // kind must be one of ActivityKind's real values — see
        // lib/core/models/campus_models.dart: checkIn, eventJoin, review,
        // comment, like, report. No "post created" kind exists there on
        // purpose (see FeedController@store).
        Schema::create('activity_log', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->string('subtitle');
            $table->string('meta');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
