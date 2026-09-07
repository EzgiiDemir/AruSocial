<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: the "First 30 Days" checklist (frontend's static
// `onboardingSteps` list) was tracked entirely in device-local
// SharedPreferences (`AppSettingsStore.onboardingDone`) — a reinstall or a
// second device silently reset every ticked box. `step_id` matches one of
// the fixed ids in `frontend/lib/core/config/onboarding_config.dart`
// (there is no backend-side steps table — the checklist content itself is
// still real static product copy, not user data; only *completion* is).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('step_id');
            $table->timestamp('completed_at');
            $table->unique(['user_id', 'step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_progress');
    }
};
