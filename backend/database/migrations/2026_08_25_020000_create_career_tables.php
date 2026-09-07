<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P4 Mega-1: career opportunities catalog + per-student career profile.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_opportunities', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('kind'); // internship | job | event | resource
            $table->string('organization')->default('');
            $table->string('url')->nullable();
            $table->date('deadline')->nullable();
            $table->text('description')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['published', 'created_at']);
        });

        Schema::create('career_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->string('cv_url')->nullable();
            $table->boolean('looking_for_internships')->default(false);
            $table->boolean('looking_for_jobs')->default(false);
            $table->timestamp('updated_at')->nullable();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_profiles');
        Schema::dropIfExists('career_opportunities');
    }
};
