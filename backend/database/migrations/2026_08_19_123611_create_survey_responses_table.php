<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->string('survey_id');
            $table->foreign('survey_id')->references('id')->on('surveys')->cascadeOnDelete();
            $table->string('option_id');
            $table->foreign('option_id')->references('id')->on('survey_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            // A single-choice survey is enforced in the controller (delete
            // prior responses before inserting); multi-choice allows
            // several rows per (survey, user) but never a duplicate option.
            $table->unique(['survey_id', 'option_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
