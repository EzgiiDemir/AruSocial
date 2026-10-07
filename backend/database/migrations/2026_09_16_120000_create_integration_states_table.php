<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational state for the integrations the admin panel lists.
 *
 * Deliberately NOT a place to store credentials. Every integration's
 * credentials already live where they belong — server-only env
 * (`config/services.php`) or `app_settings` for the two an administrator may
 * edit (Entra, WordPress). Duplicating a secret into a third table would
 * widen the blast radius for no gain, so this table holds only what neither
 * of those can answer: whether an operator switched the integration off, and
 * what happened the last time we actually talked to it.
 *
 * One row per integration key, created on demand. A missing row means "never
 * tested, never disabled", which is the correct starting state and is why
 * nothing is seeded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_states', function (Blueprint $table) {
            // The registry key ('campus_directory', 'groq', ...). Natural
            // primary key: the set is defined in code, not by users, so an
            // autoincrement id would only add a second way to say the same
            // thing.
            $table->string('key')->primary();

            // Operator kill switch. Nullable rather than default(true) so
            // "no one has touched this" stays distinguishable from "someone
            // deliberately enabled it" — the Disabled status must never be
            // inferred from an absent row.
            $table->boolean('enabled')->nullable();

            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();

            // Last time the integration genuinely worked, whether that was a
            // connection test or real traffic reporting success.
            $table->timestamp('last_success_at')->nullable();

            // Operator-facing message only. Provider payloads, stack traces
            // and anything that could carry a key stay in the server log —
            // see IntegrationRegistry::recordFailure().
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();

            // Who flipped the switch last. Audit detail also goes to
            // admin_audit_log; this is just for the row's own tooltip.
            $table->string('updated_by_email')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_states');
    }
};
