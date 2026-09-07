<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin-managed question schema for both stages of the apply flow, keyed
// by target type (club/sport/service/career/community/help/event) —
// never hardcoded per category in application code. `stage` is
// 'preview' (short, decisive) or 'detail' (the full form behind the
// emailed link). `options` holds choices for single/multiple/dropdown
// questions.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_questions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('target_type');
            $table->string('stage'); // preview|detail
            $table->string('type'); // text|textarea|single_choice|multiple_choice|dropdown|date|number|file|checkbox
            $table->string('label');
            $table->string('help_text')->nullable();
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['target_type', 'stage', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_questions');
    }
};
