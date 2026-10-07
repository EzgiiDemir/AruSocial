<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AICAD evaluation: persistent test cases, runs, and per-case results.
 *
 * A case is a question plus the structured assertions that matter for it
 * (routing, entities, retrieval, prompt, grounding, response). A run is
 * one execution of a selection of cases against the code and data as they
 * stood; its results keep a redacted diagnostic snapshot so a regression can
 * be understood after the fact, and two runs can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluation_cases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->text('question');
            $table->string('locale', 8)->nullable();
            // retrieval: no model, no web — the fast suite. full: the real answer path.
            $table->string('mode', 16)->default('retrieval');
            $table->boolean('active')->default(true);
            $table->json('tags')->nullable();
            // Earlier turns, oldest first: [{role, content}].
            $table->json('previous_turns')->nullable();
            // Optional fixture: the user a full-answer case runs as.
            $table->string('context_user_email', 191)->nullable();
            // [{type, ...params}] — see App\Services\Ai\Evaluation\AssertionEvaluator.
            $table->json('assertions');
            $table->text('notes')->nullable();
            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->index(['active', 'mode']);
        });

        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            // queued | running | finished | failed
            $table->string('status', 16)->default('queued');
            // retrieval | full | all
            $table->string('mode', 16);
            // {case_ids?: [], tags?: [], label?: string}
            $table->json('scope')->nullable();
            $table->string('initiated_by', 191)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('git_commit', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('local_model', 120)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->string('retrieval_fingerprint', 64)->nullable();
            $table->string('prompt_fingerprint', 64)->nullable();
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            // hit@k, per-category pass rates, latency percentiles.
            $table->json('metrics')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('ai_evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_evaluation_runs')->cascadeOnDelete();
            // Kept when a case is deleted, so old runs stay readable.
            $table->foreignId('case_id')->nullable()->constrained('ai_evaluation_cases')->nullOnDelete();
            $table->string('case_name', 191);
            $table->text('question');
            $table->string('mode', 16);
            // passed | failed | skipped
            $table->string('status', 16);
            $table->string('failure_stage', 40)->nullable();
            $table->json('failed_assertions')->nullable();
            // Redacted, trimmed AskTrace facts (planner, entities, retrieval, prompt, grounding…).
            $table->json('snapshot')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index(['case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_results');
        Schema::dropIfExists('ai_evaluation_runs');
        Schema::dropIfExists('ai_evaluation_cases');
    }
};
